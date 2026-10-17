<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Una unidad solo puede estar "en ruta" si tiene piloto asignado.
 *  - trg_buses_ruta_con_piloto: no deja poner en ruta un bus sin piloto.
 *  - trg_pilotos_deja_bus: si el bus pierde a su piloto (se reasigna o se da de baja) mientras va en ruta,
 *    termina su viaje y queda en espera.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
-- Datos que ya existan: los buses en ruta sin piloto terminan su viaje y quedan en espera.
UPDATE viajes SET estado = 'finalizado', fecha_hora_fin = (now() AT TIME ZONE 'America/Guatemala')::timestamp(0)
 WHERE estado = 'en_curso' AND id_bus NOT IN (SELECT id_bus_asignado FROM pilotos WHERE id_bus_asignado IS NOT NULL);
UPDATE buses SET estado_logico = 'en_espera', pasajeros_a_bordo = 0
 WHERE estado_logico = 'en_ruta' AND id_bus NOT IN (SELECT id_bus_asignado FROM pilotos WHERE id_bus_asignado IS NOT NULL);

CREATE OR REPLACE FUNCTION fn_buses_ruta_con_piloto() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.estado_logico = 'en_ruta'
       AND (TG_OP = 'INSERT' OR OLD.estado_logico IS DISTINCT FROM 'en_ruta')
       AND NOT EXISTS (SELECT 1 FROM pilotos WHERE id_bus_asignado = NEW.id_bus) THEN
        RAISE EXCEPTION 'El bus % no tiene piloto asignado: no puede salir a ruta.', NEW.placa;
    END IF;
    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_buses_ruta_con_piloto
BEFORE INSERT OR UPDATE OF estado_logico ON buses
FOR EACH ROW EXECUTE FUNCTION fn_buses_ruta_con_piloto();

CREATE OR REPLACE FUNCTION fn_pilotos_deja_bus() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF OLD.id_bus_asignado IS NOT NULL
       AND (TG_OP = 'DELETE' OR NEW.id_bus_asignado IS DISTINCT FROM OLD.id_bus_asignado)
       AND NOT EXISTS (SELECT 1 FROM pilotos WHERE id_bus_asignado = OLD.id_bus_asignado) THEN
        UPDATE viajes SET estado = 'finalizado', fecha_hora_fin = (now() AT TIME ZONE 'America/Guatemala')::timestamp(0)
         WHERE id_bus = OLD.id_bus_asignado AND estado = 'en_curso';
        UPDATE buses SET estado_logico = 'en_espera', pasajeros_a_bordo = 0
         WHERE id_bus = OLD.id_bus_asignado AND estado_logico = 'en_ruta';
    END IF;
    RETURN NULL;
END;
$$;

CREATE TRIGGER trg_pilotos_deja_bus
AFTER UPDATE OF id_bus_asignado OR DELETE ON pilotos
FOR EACH ROW EXECUTE FUNCTION fn_pilotos_deja_bus();
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS trg_pilotos_deja_bus ON pilotos;
DROP FUNCTION IF EXISTS fn_pilotos_deja_bus();
DROP TRIGGER IF EXISTS trg_buses_ruta_con_piloto ON buses;
DROP FUNCTION IF EXISTS fn_buses_ruta_con_piloto();
SQL);
    }
};
