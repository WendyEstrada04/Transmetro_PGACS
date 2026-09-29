-- =====================================================================
-- PROYECTO ACS - TRANSMETRO
-- Script de creacion de estructuras de base de datos (DDL)
-- Motor: PostgreSQL 15+ (Supabase)
-- Autor: Wendy Celeste Estrada
-- Fecha: 2026-09-28
-- Equivale a las migraciones de Laravel en database/migrations.
-- Requiere la tabla users de Laravel (se crea con php artisan migrate).
-- =====================================================================

-- ---------------------------------------------------------------------
-- 1. MUNICIPIOS
--    Punto 11: cada linea y estacion pertenece a una municipalidad.
-- ---------------------------------------------------------------------
CREATE TABLE municipios (
    id_municipio  UUID NOT NULL DEFAULT gen_random_uuid(),
    codigo_iso    VARCHAR(10) NOT NULL,
    nombre        VARCHAR(150) NOT NULL,
    activo        BOOLEAN NOT NULL DEFAULT true,
    CONSTRAINT municipios_pkey PRIMARY KEY (id_municipio),
    CONSTRAINT uq_municipios_codigo_iso UNIQUE (codigo_iso)
);

-- ---------------------------------------------------------------------
-- 2. LINEAS
--    Puntos 11 y 13. distancia_total_km se recalcula con trg_linea_estacion_distancia.
-- ---------------------------------------------------------------------
CREATE TABLE lineas (
    id_linea            UUID NOT NULL DEFAULT gen_random_uuid(),
    id_municipio        UUID NOT NULL,
    codigo_linea        VARCHAR(20) NOT NULL,
    distancia_total_km  DECIMAL(6,2) NOT NULL DEFAULT 0,
    estado              VARCHAR(255) NOT NULL DEFAULT 'activa',
    CONSTRAINT lineas_pkey PRIMARY KEY (id_linea),
    CONSTRAINT uq_lineas_codigo UNIQUE (codigo_linea),
    CONSTRAINT fk_lineas_municipio FOREIGN KEY (id_municipio) REFERENCES municipios(id_municipio) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT chk_lineas_distancia CHECK (distancia_total_km >= 0),
    CONSTRAINT lineas_estado_check CHECK (estado IN ('activa', 'inactiva', 'mantenimiento'))
);

-- ---------------------------------------------------------------------
-- 3. ESTACIONES
--    Punto 11. Coordenadas como latitud/longitud (antes POINT en MySQL).
-- ---------------------------------------------------------------------
CREATE TABLE estaciones (
    id_estacion   UUID NOT NULL DEFAULT gen_random_uuid(),
    id_municipio  UUID NOT NULL,
    nombre        VARCHAR(150) NOT NULL,
    aforo_maximo  INTEGER NOT NULL,
    aforo_actual  INTEGER NOT NULL DEFAULT 0,
    latitud       DECIMAL(9,6) NOT NULL,
    longitud      DECIMAL(9,6) NOT NULL,
    CONSTRAINT estaciones_pkey PRIMARY KEY (id_estacion),
    CONSTRAINT fk_estaciones_municipio FOREIGN KEY (id_municipio) REFERENCES municipios(id_municipio) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT chk_estaciones_aforo CHECK (aforo_maximo > 0 AND aforo_actual >= 0),
    CONSTRAINT chk_estaciones_coordenadas CHECK (latitud BETWEEN -90 AND 90 AND longitud BETWEEN -180 AND 180)
);

-- ---------------------------------------------------------------------
-- 4. LINEA_ESTACION
--    Puntos 1, 2 y 12: estaciones en orden por linea y distancia desde la parada anterior.
-- ---------------------------------------------------------------------
CREATE TABLE linea_estacion (
    id_linea             UUID NOT NULL,
    id_estacion          UUID NOT NULL,
    orden_parada         INTEGER NOT NULL,
    distancia_previa_km  DECIMAL(6,2) NOT NULL DEFAULT 0,
    CONSTRAINT linea_estacion_pkey PRIMARY KEY (id_linea, id_estacion),
    CONSTRAINT uq_linea_orden UNIQUE (id_linea, orden_parada),
    CONSTRAINT fk_linea_estacion_estacion FOREIGN KEY (id_estacion) REFERENCES estaciones(id_estacion) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_linea_estacion_linea FOREIGN KEY (id_linea) REFERENCES lineas(id_linea) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT chk_linea_estacion_distancia CHECK (distancia_previa_km >= 0),
    CONSTRAINT chk_linea_estacion_orden CHECK (orden_parada >= 1)
);
CREATE INDEX idx_linea_estacion_estacion ON linea_estacion (id_estacion);

-- ---------------------------------------------------------------------
-- 5. BUSES
--    Puntos 4, 14 y 15: bus en una sola linea o ninguna, capacidad y ocupacion.
-- ---------------------------------------------------------------------
CREATE TABLE buses (
    id_bus                UUID NOT NULL DEFAULT gen_random_uuid(),
    placa                 VARCHAR(15) NOT NULL,
    capacidad_maxima      INTEGER NOT NULL,
    id_linea_asignada     UUID,
    ocupacion_actual_pct  DECIMAL(5,2) NOT NULL DEFAULT 0,
    estado_logico         VARCHAR(255) NOT NULL DEFAULT 'fuera_de_servicio',
    CONSTRAINT buses_pkey PRIMARY KEY (id_bus),
    CONSTRAINT uq_buses_placa UNIQUE (placa),
    CONSTRAINT fk_buses_linea FOREIGN KEY (id_linea_asignada) REFERENCES lineas(id_linea) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT buses_estado_logico_check CHECK (estado_logico IN ('en_ruta', 'en_espera', 'fuera_de_servicio', 'en_mantenimiento')),
    CONSTRAINT chk_buses_capacidad CHECK (capacidad_maxima > 0),
    CONSTRAINT chk_buses_ocupacion CHECK (ocupacion_actual_pct BETWEEN 0 AND 100)
);
CREATE INDEX idx_buses_linea ON buses (id_linea_asignada);

-- ---------------------------------------------------------------------
-- 6. ACCESOS
--    Punto 3: varios accesos por estacion, un acceso pertenece a una sola estacion.
-- ---------------------------------------------------------------------
CREATE TABLE accesos (
    id_acceso         UUID NOT NULL DEFAULT gen_random_uuid(),
    id_estacion       UUID NOT NULL,
    ubicacion_fisica  VARCHAR(100) NOT NULL,
    CONSTRAINT accesos_pkey PRIMARY KEY (id_acceso),
    CONSTRAINT fk_accesos_estacion FOREIGN KEY (id_estacion) REFERENCES estaciones(id_estacion) ON UPDATE CASCADE ON DELETE RESTRICT
);
CREATE INDEX idx_accesos_estacion ON accesos (id_estacion);

-- ---------------------------------------------------------------------
-- 7. MOLINETES
--    Un molinete propio por acceso (1:1).
-- ---------------------------------------------------------------------
CREATE TABLE molinetes (
    id_molinete       UUID NOT NULL DEFAULT gen_random_uuid(),
    id_acceso         UUID NOT NULL,
    ip_address        VARCHAR(45) NOT NULL,
    estado_operativo  VARCHAR(255) NOT NULL DEFAULT 'activo',
    CONSTRAINT molinetes_pkey PRIMARY KEY (id_molinete),
    CONSTRAINT uq_molinetes_acceso UNIQUE (id_acceso),
    CONSTRAINT fk_molinetes_acceso FOREIGN KEY (id_acceso) REFERENCES accesos(id_acceso) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT molinetes_estado_operativo_check CHECK (estado_operativo IN ('activo', 'inactivo', 'mantenimiento'))
);

-- ---------------------------------------------------------------------
-- 8. PARQUEOS
--    Puntos 6 y 7: un bus nunca ocupa dos parqueos (UNIQUE) y cambia con sp_cambiar_parqueo_bus.
-- ---------------------------------------------------------------------
CREATE TABLE parqueos (
    id_parqueo       UUID NOT NULL DEFAULT gen_random_uuid(),
    id_estacion      UUID NOT NULL,
    codigo_bahia     VARCHAR(20) NOT NULL,
    id_bus_asignado  UUID,
    CONSTRAINT parqueos_pkey PRIMARY KEY (id_parqueo),
    CONSTRAINT uq_parqueos_bus UNIQUE (id_bus_asignado),
    CONSTRAINT uq_parqueos_estacion_bahia UNIQUE (id_estacion, codigo_bahia),
    CONSTRAINT fk_parqueos_bus FOREIGN KEY (id_bus_asignado) REFERENCES buses(id_bus) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_parqueos_estacion FOREIGN KEY (id_estacion) REFERENCES estaciones(id_estacion) ON UPDATE CASCADE ON DELETE RESTRICT
);

-- ---------------------------------------------------------------------
-- 9. EMPLEADOS
--    Datos generales del personal (contacto y direccion cifrados por Laravel).
-- ---------------------------------------------------------------------
CREATE TABLE empleados (
    id_empleado           UUID NOT NULL DEFAULT gen_random_uuid(),
    dpi                   VARCHAR(20) NOT NULL,
    nombre_completo       VARCHAR(200) NOT NULL,
    fecha_nacimiento      DATE NOT NULL,
    rol                   VARCHAR(255) NOT NULL,
    contacto_encriptado   TEXT NOT NULL,
    direccion_encriptada  TEXT NOT NULL,
    activo                BOOLEAN NOT NULL DEFAULT true,
    CONSTRAINT empleados_pkey PRIMARY KEY (id_empleado),
    CONSTRAINT uq_empleados_dpi UNIQUE (dpi),
    CONSTRAINT empleados_rol_check CHECK (rol IN ('piloto', 'seguridad', 'operador'))
);

-- ---------------------------------------------------------------------
-- 10. PILOTOS
--    Punto 9: historial educativo, residencia y comunicacion del piloto.
-- ---------------------------------------------------------------------
CREATE TABLE pilotos (
    id_empleado                     UUID NOT NULL,
    numero_licencia                 VARCHAR(30) NOT NULL,
    tipo_licencia                   VARCHAR(10) NOT NULL,
    anios_experiencia               INTEGER NOT NULL DEFAULT 0,
    nivel_educativo                 VARCHAR(50) NOT NULL,
    historial_educativo_encriptado  TEXT,
    id_bus_asignado                 UUID,
    CONSTRAINT pilotos_pkey PRIMARY KEY (id_empleado),
    CONSTRAINT uq_pilotos_bus UNIQUE (id_bus_asignado),
    CONSTRAINT uq_pilotos_licencia UNIQUE (numero_licencia),
    CONSTRAINT fk_pilotos_bus FOREIGN KEY (id_bus_asignado) REFERENCES buses(id_bus) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_pilotos_empleado FOREIGN KEY (id_empleado) REFERENCES empleados(id_empleado) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT chk_pilotos_experiencia CHECK (anios_experiencia >= 0)
);

-- ---------------------------------------------------------------------
-- 11. SEGURIDAD
--    Punto 10: al menos un guardia por acceso (trg_seguridad_min_uno).
-- ---------------------------------------------------------------------
CREATE TABLE seguridad (
    id_empleado                      UUID NOT NULL,
    permisos_vigentes                VARCHAR(100) NOT NULL,
    constancia_antecedentes_penales  VARCHAR(100) NOT NULL,
    numero_placa_asignada            VARCHAR(15),
    id_acceso_asignado               UUID,
    CONSTRAINT seguridad_pkey PRIMARY KEY (id_empleado),
    CONSTRAINT fk_seguridad_acceso FOREIGN KEY (id_acceso_asignado) REFERENCES accesos(id_acceso) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_seguridad_empleado FOREIGN KEY (id_empleado) REFERENCES empleados(id_empleado) ON UPDATE CASCADE ON DELETE CASCADE
);
CREATE INDEX idx_seguridad_acceso ON seguridad (id_acceso_asignado);

-- ---------------------------------------------------------------------
-- 12. OPERADORES
--    Punto 16: operador asignado a la PC de trabajo de cada estacion.
-- ---------------------------------------------------------------------
CREATE TABLE operadores (
    id_empleado                  UUID NOT NULL,
    nivel_estudios               VARCHAR(50) NOT NULL,
    anios_experiencia_monitoreo  INTEGER NOT NULL DEFAULT 0,
    id_estacion_asignada         UUID,
    CONSTRAINT operadores_pkey PRIMARY KEY (id_empleado),
    CONSTRAINT fk_operadores_empleado FOREIGN KEY (id_empleado) REFERENCES empleados(id_empleado) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_operadores_estacion FOREIGN KEY (id_estacion_asignada) REFERENCES estaciones(id_estacion) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT chk_operadores_experiencia CHECK (anios_experiencia_monitoreo >= 0)
);
CREATE INDEX idx_operadores_estacion ON operadores (id_estacion_asignada);

-- ---------------------------------------------------------------------
-- 13. TARJETAS
--    NUEVO - Punto 18: tarjeta de acceso Transmetro con saldo.
-- ---------------------------------------------------------------------
CREATE TABLE tarjetas (
    id_tarjeta      UUID NOT NULL DEFAULT gen_random_uuid(),
    numero_tarjeta  VARCHAR(20) NOT NULL,
    saldo           DECIMAL(8,2) NOT NULL DEFAULT 0,
    estado          VARCHAR(255) NOT NULL DEFAULT 'activa',
    fecha_emision   TIMESTAMP(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT tarjetas_pkey PRIMARY KEY (id_tarjeta),
    CONSTRAINT uq_tarjetas_numero UNIQUE (numero_tarjeta),
    CONSTRAINT chk_tarjetas_saldo CHECK (saldo >= 0),
    CONSTRAINT tarjetas_estado_check CHECK (estado IN ('activa', 'bloqueada', 'vencida'))
);

-- ---------------------------------------------------------------------
-- 14. RECARGAS
--    NUEVO - Punto 18: recargas de saldo a las tarjetas.
-- ---------------------------------------------------------------------
CREATE TABLE recargas (
    id_recarga   UUID NOT NULL DEFAULT gen_random_uuid(),
    id_tarjeta   UUID NOT NULL,
    monto        DECIMAL(8,2) NOT NULL,
    metodo_pago  VARCHAR(255) NOT NULL,
    fecha_hora   TIMESTAMP(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT recargas_pkey PRIMARY KEY (id_recarga),
    CONSTRAINT fk_recargas_tarjeta FOREIGN KEY (id_tarjeta) REFERENCES tarjetas(id_tarjeta) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT chk_recargas_monto CHECK (monto > 0),
    CONSTRAINT recargas_metodo_pago_check CHECK (metodo_pago IN ('efectivo', 'credito', 'debito'))
);
CREATE INDEX idx_recargas_tarjeta ON recargas (id_tarjeta);

-- ---------------------------------------------------------------------
-- 15. ALERTAS_SISTEMA
--    Punto 14: alertas de refuerzo de bus (trg_estaciones_alerta_aforo).
-- ---------------------------------------------------------------------
CREATE TABLE alertas_sistema (
    id_alerta       UUID NOT NULL DEFAULT gen_random_uuid(),
    id_estacion     UUID NOT NULL,
    tipo_alerta     VARCHAR(255) NOT NULL,
    descripcion     TEXT NOT NULL,
    estado          VARCHAR(255) NOT NULL DEFAULT 'abierta',
    fecha_creacion  TIMESTAMP(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_cierre    TIMESTAMP(0),
    CONSTRAINT alertas_sistema_pkey PRIMARY KEY (id_alerta),
    CONSTRAINT fk_alertas_estacion FOREIGN KEY (id_estacion) REFERENCES estaciones(id_estacion) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT alertas_sistema_estado_check CHECK (estado IN ('abierta', 'atendida', 'cerrada')),
    CONSTRAINT alertas_sistema_tipo_alerta_check CHECK (tipo_alerta IN ('refuerzo_bus', 'bahia_ocupada', 'espera_minima', 'otro'))
);
CREATE INDEX idx_alertas_estado ON alertas_sistema (estado);

-- ---------------------------------------------------------------------
-- 16. TRANSACCIONES_PAGO
--    Punto 18: pagos en molinetes; con tarjeta Transmetro exige id_tarjeta.
-- ---------------------------------------------------------------------
CREATE TABLE transacciones_pago (
    id_transaccion  UUID NOT NULL DEFAULT gen_random_uuid(),
    id_molinete     UUID NOT NULL,
    id_tarjeta      UUID,
    monto           DECIMAL(8,2) NOT NULL,
    metodo_pago     VARCHAR(255) NOT NULL,
    fecha_hora      TIMESTAMP(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT transacciones_pago_pkey PRIMARY KEY (id_transaccion),
    CONSTRAINT fk_transacciones_molinete FOREIGN KEY (id_molinete) REFERENCES molinetes(id_molinete) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_transacciones_tarjeta FOREIGN KEY (id_tarjeta) REFERENCES tarjetas(id_tarjeta) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT chk_transacciones_monto CHECK (monto > 0),
    CONSTRAINT chk_transacciones_tarjeta CHECK (metodo_pago <> 'tarjeta_transmetro' OR id_tarjeta IS NOT NULL),
    CONSTRAINT transacciones_pago_metodo_pago_check CHECK (metodo_pago IN ('tarjeta_transmetro', 'credito', 'debito', 'efectivo'))
);
CREATE INDEX idx_transacciones_fecha ON transacciones_pago (fecha_hora);
CREATE INDEX idx_transacciones_tarjeta ON transacciones_pago (id_tarjeta);

-- ---------------------------------------------------------------------
-- 17. BITACORA_AUDITORIA
--    Auditoria de cambios (quien, que tabla, antes y despues).
-- ---------------------------------------------------------------------
CREATE TABLE bitacora_auditoria (
    id_bitacora           UUID NOT NULL DEFAULT gen_random_uuid(),
    id_usuario            BIGINT,
    id_empleado_usuario   UUID,
    tabla_afectada        VARCHAR(100) NOT NULL,
    id_registro_afectado  VARCHAR(36),
    accion                VARCHAR(255) NOT NULL,
    datos_previos         JSONB,
    datos_nuevos          JSONB,
    fecha_registro        TIMESTAMP(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT bitacora_auditoria_pkey PRIMARY KEY (id_bitacora),
    CONSTRAINT fk_bitacora_empleado FOREIGN KEY (id_empleado_usuario) REFERENCES empleados(id_empleado) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_bitacora_usuario FOREIGN KEY (id_usuario) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT bitacora_auditoria_accion_check CHECK (accion IN ('INSERT', 'UPDATE', 'DELETE'))
);
CREATE INDEX idx_bitacora_tabla ON bitacora_auditoria (tabla_afectada, id_registro_afectado);

-- ---------------------------------------------------------------------
-- 18. VIAJES
--    Punto 1: recorridos reales de cada bus.
-- ---------------------------------------------------------------------
CREATE TABLE viajes (
    id_viaje           UUID NOT NULL DEFAULT gen_random_uuid(),
    id_bus             UUID NOT NULL,
    id_linea           UUID NOT NULL,
    fecha_hora_inicio  TIMESTAMP(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_hora_fin     TIMESTAMP(0),
    estado             VARCHAR(255) NOT NULL DEFAULT 'en_curso',
    CONSTRAINT viajes_pkey PRIMARY KEY (id_viaje),
    CONSTRAINT fk_viajes_bus FOREIGN KEY (id_bus) REFERENCES buses(id_bus) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_viajes_linea FOREIGN KEY (id_linea) REFERENCES lineas(id_linea) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT chk_viajes_fechas CHECK (fecha_hora_fin IS NULL OR fecha_hora_fin >= fecha_hora_inicio),
    CONSTRAINT viajes_estado_check CHECK (estado IN ('en_curso', 'finalizado', 'cancelado'))
);
CREATE INDEX idx_viajes_bus ON viajes (id_bus);
CREATE INDEX idx_viajes_linea ON viajes (id_linea);

-- ---------------------------------------------------------------------
-- 19. VISITAS_VIAJE
--    Puntos 1 y 15: paradas visitadas con hora de llegada/salida y ocupacion.
-- ---------------------------------------------------------------------
CREATE TABLE visitas_viaje (
    id_visita                UUID NOT NULL DEFAULT gen_random_uuid(),
    id_viaje                 UUID NOT NULL,
    id_estacion              UUID NOT NULL,
    orden_visita             INTEGER NOT NULL,
    hora_llegada             TIMESTAMP(0),
    hora_salida              TIMESTAMP(0),
    ocupacion_al_llegar_pct  DECIMAL(5,2),
    CONSTRAINT visitas_viaje_pkey PRIMARY KEY (id_visita),
    CONSTRAINT uq_visitas_viaje_orden UNIQUE (id_viaje, orden_visita),
    CONSTRAINT fk_visitas_estacion FOREIGN KEY (id_estacion) REFERENCES estaciones(id_estacion) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_visitas_viaje FOREIGN KEY (id_viaje) REFERENCES viajes(id_viaje) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT chk_visitas_horas CHECK (hora_salida IS NULL OR hora_llegada IS NULL OR hora_salida >= hora_llegada),
    CONSTRAINT chk_visitas_ocupacion CHECK (ocupacion_al_llegar_pct IS NULL OR ocupacion_al_llegar_pct BETWEEN 0 AND 100),
    CONSTRAINT chk_visitas_orden CHECK (orden_visita >= 1)
);
CREATE INDEX idx_visitas_estacion ON visitas_viaje (id_estacion, hora_llegada);

-- =====================================================================
-- USUARIOS DEL SISTEMA (tabla users de Laravel): rol y empleado vinculado
-- =====================================================================
ALTER TABLE users
    ADD COLUMN rol VARCHAR(255) NOT NULL DEFAULT 'operador' CHECK (rol IN ('administrador','operador')),
    ADD COLUMN id_empleado UUID NULL,
    ADD CONSTRAINT uq_users_empleado UNIQUE (id_empleado),
    ADD CONSTRAINT fk_users_empleado FOREIGN KEY (id_empleado) REFERENCES empleados (id_empleado) ON UPDATE CASCADE ON DELETE SET NULL;

-- =====================================================================
-- REGLAS DE NEGOCIO: funciones, triggers y procedimiento almacenado
-- =====================================================================
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

-- =====================================================================
-- SEGURIDAD: Row Level Security en todas las tablas del sistema
-- =====================================================================
ALTER TABLE municipios ENABLE ROW LEVEL SECURITY;
ALTER TABLE lineas ENABLE ROW LEVEL SECURITY;
ALTER TABLE estaciones ENABLE ROW LEVEL SECURITY;
ALTER TABLE linea_estacion ENABLE ROW LEVEL SECURITY;
ALTER TABLE buses ENABLE ROW LEVEL SECURITY;
ALTER TABLE accesos ENABLE ROW LEVEL SECURITY;
ALTER TABLE molinetes ENABLE ROW LEVEL SECURITY;
ALTER TABLE parqueos ENABLE ROW LEVEL SECURITY;
ALTER TABLE empleados ENABLE ROW LEVEL SECURITY;
ALTER TABLE pilotos ENABLE ROW LEVEL SECURITY;
ALTER TABLE seguridad ENABLE ROW LEVEL SECURITY;
ALTER TABLE operadores ENABLE ROW LEVEL SECURITY;
ALTER TABLE tarjetas ENABLE ROW LEVEL SECURITY;
ALTER TABLE recargas ENABLE ROW LEVEL SECURITY;
ALTER TABLE alertas_sistema ENABLE ROW LEVEL SECURITY;
ALTER TABLE transacciones_pago ENABLE ROW LEVEL SECURITY;
ALTER TABLE bitacora_auditoria ENABLE ROW LEVEL SECURITY;
ALTER TABLE viajes ENABLE ROW LEVEL SECURITY;
ALTER TABLE visitas_viaje ENABLE ROW LEVEL SECURITY;
ALTER TABLE users ENABLE ROW LEVEL SECURITY;
