<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * REGLAS DE NEGOCIO en la base de datos (funciones, triggers y procedimiento).
 *  - trg_accesos_min_dos_before_delete  → una estación conserva al menos 2 accesos.
 *  - trg_seguridad_min_uno              → ningún acceso se queda sin guardia (punto 10).
 *  - trg_buses_max_flota                → máximo 2 × estaciones buses por línea (punto 5).
 *  - trg_estaciones_alerta_aforo        → alerta al superar el 150 % del aforo (punto 14).
 *  - trg_linea_estacion_distancia       → distancia total de la línea = suma de tramos (punto 13).
 *  - sp_cambiar_parqueo_bus             → cambio de parqueo sin quedar sin parqueo (punto 7).
 *    Uso desde Laravel: DB::statement('CALL sp_cambiar_parqueo_bus(?, ?)', [$idBus, $idParqueo]);
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
-- Punto 3 (regla propia del equipo): una estación debe conservar al menos 2 accesos.
CREATE OR REPLACE FUNCTION fn_accesos_min_dos() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
    v_conteo INT;
BEGIN
    SELECT COUNT(*) INTO v_conteo FROM accesos WHERE id_estacion = OLD.id_estacion;
    IF v_conteo <= 2 THEN
        RAISE EXCEPTION 'No se puede eliminar: la estacion debe conservar al menos 2 accesos.';
    END IF;
    RETURN OLD;
END;
$$;

CREATE TRIGGER trg_accesos_min_dos_before_delete
BEFORE DELETE ON accesos
FOR EACH ROW EXECUTE FUNCTION fn_accesos_min_dos();

-- Punto 10: ningún acceso puede quedarse sin guardia al reasignar o dar de baja al último.
CREATE OR REPLACE FUNCTION fn_seguridad_min_uno() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
    v_conteo INT;
BEGIN
    IF OLD.id_acceso_asignado IS NOT NULL
       AND (TG_OP = 'DELETE' OR NEW.id_acceso_asignado IS DISTINCT FROM OLD.id_acceso_asignado)
       AND EXISTS (SELECT 1 FROM accesos WHERE id_acceso = OLD.id_acceso_asignado) THEN
        SELECT COUNT(*) INTO v_conteo FROM seguridad WHERE id_acceso_asignado = OLD.id_acceso_asignado;
        IF v_conteo <= 1 THEN
            RAISE EXCEPTION 'No se puede reasignar ni dar de baja: el acceso quedaria sin personal de seguridad.';
        END IF;
    END IF;
    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;
    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_seguridad_min_uno
BEFORE UPDATE OR DELETE ON seguridad
FOR EACH ROW EXECUTE FUNCTION fn_seguridad_min_uno();

-- Punto 5: una línea tiene como máximo el doble de buses que de estaciones.
CREATE OR REPLACE FUNCTION fn_buses_max_flota() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
    v_buses_actuales INT;
    v_paradas INT;
BEGIN
    IF NEW.id_linea_asignada IS NOT NULL
       AND (TG_OP = 'INSERT' OR NEW.id_linea_asignada IS DISTINCT FROM OLD.id_linea_asignada) THEN
        SELECT COUNT(*) INTO v_buses_actuales FROM buses WHERE id_linea_asignada = NEW.id_linea_asignada;
        SELECT COUNT(*) INTO v_paradas FROM linea_estacion WHERE id_linea = NEW.id_linea_asignada;
        IF v_buses_actuales >= 2 * v_paradas THEN
            RAISE EXCEPTION 'No se puede asignar el bus: se alcanzo el maximo permitido (2x el numero de paradas) para esta linea.';
        END IF;
    END IF;
    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_buses_max_flota
BEFORE INSERT OR UPDATE OF id_linea_asignada ON buses
FOR EACH ROW EXECUTE FUNCTION fn_buses_max_flota();

-- Punto 14: alerta automática cuando la estación supera en 50 % su aforo (150 %).
CREATE OR REPLACE FUNCTION fn_estaciones_alerta_aforo() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.aforo_actual >= 1.5 * NEW.aforo_maximo
       AND OLD.aforo_actual < 1.5 * OLD.aforo_maximo THEN
        INSERT INTO alertas_sistema (id_estacion, tipo_alerta, descripcion, estado)
        VALUES (
            NEW.id_estacion,
            'refuerzo_bus',
            'La estacion supero el 150% de su aforo maximo (' || NEW.aforo_actual || '/' || NEW.aforo_maximo
                || '). Se requiere unidad de refuerzo.',
            'abierta'
        );
    END IF;
    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_estaciones_alerta_aforo
AFTER UPDATE OF aforo_actual, aforo_maximo ON estaciones
FOR EACH ROW EXECUTE FUNCTION fn_estaciones_alerta_aforo();

-- Punto 13: la distancia total de la línea es la suma de las distancias entre sus paradas.
CREATE OR REPLACE FUNCTION fn_linea_estacion_distancia() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP IN ('UPDATE', 'DELETE') THEN
        UPDATE lineas
           SET distancia_total_km = COALESCE((SELECT SUM(distancia_previa_km) FROM linea_estacion WHERE id_linea = OLD.id_linea), 0)
         WHERE id_linea = OLD.id_linea;
    END IF;
    IF TG_OP IN ('INSERT', 'UPDATE') THEN
        UPDATE lineas
           SET distancia_total_km = COALESCE((SELECT SUM(distancia_previa_km) FROM linea_estacion WHERE id_linea = NEW.id_linea), 0)
         WHERE id_linea = NEW.id_linea;
    END IF;
    RETURN NULL;
END;
$$;

CREATE TRIGGER trg_linea_estacion_distancia
AFTER INSERT OR UPDATE OR DELETE ON linea_estacion
FOR EACH ROW EXECUTE FUNCTION fn_linea_estacion_distancia();

-- Punto 7: cambio de parqueo en una sola operación; el bus nunca queda sin parqueo.
-- Primero libera el parqueo actual y luego ocupa el nuevo (respetando el UNIQUE).
CREATE OR REPLACE PROCEDURE sp_cambiar_parqueo_bus(p_id_bus UUID, p_id_parqueo_nuevo UUID)
LANGUAGE plpgsql AS $$
DECLARE
    v_parqueo_actual UUID;
    v_ocupado UUID;
BEGIN
    SELECT id_bus_asignado INTO v_ocupado
      FROM parqueos WHERE id_parqueo = p_id_parqueo_nuevo
       FOR UPDATE;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'El parqueo destino no existe.';
    END IF;
    IF v_ocupado = p_id_bus THEN
        RETURN;
    END IF;
    IF v_ocupado IS NOT NULL THEN
        RAISE EXCEPTION 'El parqueo destino ya esta ocupado por otro bus.';
    END IF;

    SELECT id_parqueo INTO v_parqueo_actual
      FROM parqueos WHERE id_bus_asignado = p_id_bus
       FOR UPDATE;
    IF v_parqueo_actual IS NOT NULL THEN
        UPDATE parqueos SET id_bus_asignado = NULL WHERE id_parqueo = v_parqueo_actual;
    END IF;
    UPDATE parqueos SET id_bus_asignado = p_id_bus WHERE id_parqueo = p_id_parqueo_nuevo;
END;
$$;
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP PROCEDURE IF EXISTS sp_cambiar_parqueo_bus(UUID, UUID);
DROP TRIGGER IF EXISTS trg_linea_estacion_distancia ON linea_estacion;
DROP FUNCTION IF EXISTS fn_linea_estacion_distancia();
DROP TRIGGER IF EXISTS trg_estaciones_alerta_aforo ON estaciones;
DROP FUNCTION IF EXISTS fn_estaciones_alerta_aforo();
DROP TRIGGER IF EXISTS trg_buses_max_flota ON buses;
DROP FUNCTION IF EXISTS fn_buses_max_flota();
DROP TRIGGER IF EXISTS trg_seguridad_min_uno ON seguridad;
DROP FUNCTION IF EXISTS fn_seguridad_min_uno();
DROP TRIGGER IF EXISTS trg_accesos_min_dos_before_delete ON accesos;
DROP FUNCTION IF EXISTS fn_accesos_min_dos();
SQL);
    }
};
