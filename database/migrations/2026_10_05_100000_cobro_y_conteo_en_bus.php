<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cobro al subir al bus y conteo al bajar.
 *
 * Cada bus tiene DOS molinetes:
 *  - entrada_bus: en la puerta de subida. Cobra el pasaje de cada persona que sube (un pago por persona).
 *  - salida_bus:  en la puerta de bajada. Cuenta a las personas que bajan (no cobra).
 * Los molinetes de las estaciones (tipo 'estacion') siguen en cada acceso: controlan el ingreso
 * y cuentan a la gente que espera (aforo), pero ya no cobran.
 *
 * Con eso la base de datos sabe en todo momento:
 *  - cuántas personas van en el bus:  pasajeros_a_bordo = subidas − bajadas,
 *  - cuántas subieron y bajaron en cada estación (visitas_viaje.subieron / bajaron),
 *  - la ocupación del bus (ocupacion_actual_pct se calcula sola a partir de pasajeros_a_bordo).
 *
 * Triggers:
 *  - trg_buses_molinetes        → todo bus nuevo nace con su molinete de entrada y de salida.
 *  - trg_buses_ocupacion        → ocupacion_actual_pct = pasajeros_a_bordo / capacidad.
 *  - trg_transacciones_en_bus   → solo se cobra en el molinete de entrada de un bus que está en una estación;
 *                                 completa bus, estación, visita y línea del pago.
 *  - trg_transacciones_suben    → cada pago es una persona que sube: suma al bus y a la visita y
 *                                 la resta de la gente que espera en la estación. Si no cabe, no la deja pasar.
 *  - trg_bajadas_en_bus / trg_bajadas_restan → igual para el molinete de salida.
 *
 * La alerta 'bus_lleno' se crea cuando un bus va lleno y quedan personas en la estación sin poder subir;
 * el centro de control (o el operador) manda otro bus de la línea.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
-- ---------------------------------------------------------------- MOLINETES DEL BUS
ALTER TABLE molinetes ALTER COLUMN id_acceso DROP NOT NULL;
ALTER TABLE molinetes ADD COLUMN id_bus UUID NULL;
ALTER TABLE molinetes ADD COLUMN tipo VARCHAR(20) NOT NULL DEFAULT 'estacion';
ALTER TABLE molinetes ADD CONSTRAINT fk_molinetes_bus FOREIGN KEY (id_bus) REFERENCES buses(id_bus)
    ON UPDATE CASCADE ON DELETE CASCADE;
ALTER TABLE molinetes ADD CONSTRAINT chk_molinetes_tipo CHECK (tipo IN ('estacion', 'entrada_bus', 'salida_bus'));
ALTER TABLE molinetes ADD CONSTRAINT chk_molinetes_ubicacion CHECK (
    (tipo = 'estacion' AND id_acceso IS NOT NULL AND id_bus IS NULL)
    OR (tipo IN ('entrada_bus', 'salida_bus') AND id_bus IS NOT NULL AND id_acceso IS NULL));
ALTER TABLE molinetes ADD CONSTRAINT uq_molinetes_bus_tipo UNIQUE (id_bus, tipo);

-- ---------------------------------------------------------------- PASAJEROS A BORDO
ALTER TABLE buses ADD COLUMN pasajeros_a_bordo INTEGER NOT NULL DEFAULT 0;
UPDATE buses SET pasajeros_a_bordo = LEAST(capacidad_maxima, ROUND(capacidad_maxima * ocupacion_actual_pct / 100))::int;
ALTER TABLE buses ADD CONSTRAINT chk_buses_a_bordo CHECK (pasajeros_a_bordo >= 0 AND pasajeros_a_bordo <= capacidad_maxima);

CREATE OR REPLACE FUNCTION fn_buses_ocupacion() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    NEW.ocupacion_actual_pct := ROUND(NEW.pasajeros_a_bordo * 100.0 / GREATEST(NEW.capacidad_maxima, 1), 2);
    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_buses_ocupacion
BEFORE INSERT OR UPDATE ON buses
FOR EACH ROW EXECUTE FUNCTION fn_buses_ocupacion();

UPDATE buses SET pasajeros_a_bordo = pasajeros_a_bordo;   -- recalcula la ocupación con el trigger

-- Todo bus tiene su molinete de entrada (cobra) y de salida (cuenta).
CREATE OR REPLACE FUNCTION fn_buses_molinetes() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
    v_n INT;
BEGIN
    SELECT COUNT(*) INTO v_n FROM buses;
    INSERT INTO molinetes (id_bus, tipo, ip_address, estado_operativo) VALUES
        (NEW.id_bus, 'entrada_bus', '10.20.' || v_n || '.11', 'activo'),
        (NEW.id_bus, 'salida_bus',  '10.20.' || v_n || '.12', 'activo')
    ON CONFLICT (id_bus, tipo) DO NOTHING;
    RETURN NULL;
END;
$$;

CREATE TRIGGER trg_buses_molinetes
AFTER INSERT ON buses
FOR EACH ROW EXECUTE FUNCTION fn_buses_molinetes();

INSERT INTO molinetes (id_bus, tipo, ip_address, estado_operativo)
SELECT b.id_bus, t.tipo, '10.20.' || b.n || CASE t.tipo WHEN 'entrada_bus' THEN '.11' ELSE '.12' END, 'activo'
  FROM (SELECT id_bus, row_number() OVER (ORDER BY placa) AS n FROM buses) b
 CROSS JOIN (VALUES ('entrada_bus'), ('salida_bus')) AS t(tipo)
ON CONFLICT (id_bus, tipo) DO NOTHING;

-- ---------------------------------------------------------------- SUBIERON / BAJARON POR ESTACIÓN
ALTER TABLE visitas_viaje ADD COLUMN subieron INTEGER NOT NULL DEFAULT 0;
ALTER TABLE visitas_viaje ADD COLUMN bajaron INTEGER NOT NULL DEFAULT 0;
ALTER TABLE visitas_viaje ADD CONSTRAINT chk_visitas_pasajeros CHECK (subieron >= 0 AND bajaron >= 0);

-- ---------------------------------------------------------------- PAGOS: EN QUÉ BUS Y ESTACIÓN SUBIÓ
ALTER TABLE transacciones_pago ADD COLUMN id_bus UUID NULL;
ALTER TABLE transacciones_pago ADD COLUMN id_estacion UUID NULL;
ALTER TABLE transacciones_pago ADD COLUMN id_visita UUID NULL;
ALTER TABLE transacciones_pago ADD CONSTRAINT fk_transacciones_bus FOREIGN KEY (id_bus) REFERENCES buses(id_bus)
    ON UPDATE CASCADE ON DELETE SET NULL;
ALTER TABLE transacciones_pago ADD CONSTRAINT fk_transacciones_estacion FOREIGN KEY (id_estacion) REFERENCES estaciones(id_estacion)
    ON UPDATE CASCADE ON DELETE SET NULL;
ALTER TABLE transacciones_pago ADD CONSTRAINT fk_transacciones_visita FOREIGN KEY (id_visita) REFERENCES visitas_viaje(id_visita)
    ON UPDATE CASCADE ON DELETE SET NULL;
CREATE INDEX idx_transacciones_bus ON transacciones_pago (id_bus, fecha_hora);
CREATE INDEX idx_transacciones_estacion ON transacciones_pago (id_estacion, fecha_hora);
CREATE INDEX idx_transacciones_visita ON transacciones_pago (id_visita);

-- Estación donde está detenido un bus: la última visita de su viaje en curso.
CREATE OR REPLACE FUNCTION fn_visita_actual_bus(p_id_bus UUID, OUT id_visita UUID, OUT id_estacion UUID, OUT id_linea UUID)
LANGUAGE sql STABLE AS $$
    SELECT vv.id_visita, vv.id_estacion, v.id_linea
      FROM viajes v
      JOIN visitas_viaje vv ON vv.id_viaje = v.id_viaje
     WHERE v.id_bus = p_id_bus AND v.estado = 'en_curso'
     ORDER BY vv.orden_visita DESC
     LIMIT 1
$$;

-- Solo se cobra en el molinete de ENTRADA de un bus detenido en una estación.
CREATE OR REPLACE FUNCTION fn_transacciones_en_bus() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
    v_tipo VARCHAR;
    v_bus UUID;
    v_actual RECORD;
BEGIN
    SELECT tipo, id_bus INTO v_tipo, v_bus FROM molinetes WHERE id_molinete = NEW.id_molinete;
    IF v_tipo IS DISTINCT FROM 'entrada_bus' THEN
        RAISE EXCEPTION 'El pasaje se cobra en el molinete de entrada del bus.';
    END IF;
    NEW.id_bus := v_bus;
    -- Si no se indica la estación (lo normal en el molinete real), es donde está detenido el bus.
    IF NEW.id_visita IS NULL AND NEW.id_estacion IS NULL THEN
        SELECT * INTO v_actual FROM fn_visita_actual_bus(v_bus);
        IF v_actual.id_visita IS NULL THEN
            RAISE EXCEPTION 'El bus no esta detenido en una estacion: no tiene un viaje en curso.';
        END IF;
        NEW.id_visita := v_actual.id_visita;
        NEW.id_estacion := COALESCE(NEW.id_estacion, v_actual.id_estacion);
        NEW.id_linea := COALESCE(NEW.id_linea, v_actual.id_linea);
    END IF;
    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_transacciones_en_bus
BEFORE INSERT ON transacciones_pago
FOR EACH ROW EXECUTE FUNCTION fn_transacciones_en_bus();

-- Cada pago es una persona que sube: suma al bus y a la visita, y deja de esperar en la estación.
-- Se hace una vez por instrucción (aunque se inserten cientos de pagos juntos).
CREATE OR REPLACE FUNCTION fn_transacciones_suben() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
    v_placa VARCHAR;
BEGIN
    SELECT b.placa INTO v_placa
      FROM (SELECT id_bus, COUNT(*) AS n FROM nuevos WHERE id_bus IS NOT NULL GROUP BY id_bus) s
      JOIN buses b ON b.id_bus = s.id_bus
     WHERE b.pasajeros_a_bordo + s.n > b.capacidad_maxima
     LIMIT 1;
    IF v_placa IS NOT NULL THEN
        RAISE EXCEPTION 'El bus % esta lleno: el molinete de entrada no deja subir mas pasajeros.', v_placa;
    END IF;

    UPDATE estaciones e SET aforo_actual = GREATEST(0, e.aforo_actual - s.n)
      FROM (SELECT id_estacion, COUNT(*) AS n FROM nuevos WHERE id_bus IS NOT NULL AND id_estacion IS NOT NULL GROUP BY id_estacion) s
     WHERE e.id_estacion = s.id_estacion;
    UPDATE buses b SET pasajeros_a_bordo = b.pasajeros_a_bordo + s.n
      FROM (SELECT id_bus, COUNT(*) AS n FROM nuevos WHERE id_bus IS NOT NULL GROUP BY id_bus) s
     WHERE b.id_bus = s.id_bus;
    UPDATE visitas_viaje vv SET subieron = vv.subieron + s.n
      FROM (SELECT id_visita, COUNT(*) AS n FROM nuevos WHERE id_visita IS NOT NULL GROUP BY id_visita) s
     WHERE vv.id_visita = s.id_visita;
    RETURN NULL;
END;
$$;

CREATE TRIGGER trg_transacciones_suben
AFTER INSERT ON transacciones_pago
REFERENCING NEW TABLE AS nuevos
FOR EACH STATEMENT EXECUTE FUNCTION fn_transacciones_suben();

-- ---------------------------------------------------------------- BAJADAS (molinete de salida)
CREATE TABLE bajadas_bus (
    id_bajada   UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    id_molinete UUID NOT NULL,
    id_bus      UUID NULL,
    id_estacion UUID NULL,
    id_visita   UUID NULL,
    cantidad    INTEGER NOT NULL DEFAULT 1,
    fecha_hora  TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_bajadas_molinete FOREIGN KEY (id_molinete) REFERENCES molinetes(id_molinete) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_bajadas_bus FOREIGN KEY (id_bus) REFERENCES buses(id_bus) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_bajadas_estacion FOREIGN KEY (id_estacion) REFERENCES estaciones(id_estacion) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_bajadas_visita FOREIGN KEY (id_visita) REFERENCES visitas_viaje(id_visita) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT chk_bajadas_cantidad CHECK (cantidad >= 1)
);
CREATE INDEX idx_bajadas_bus ON bajadas_bus (id_bus, fecha_hora);
CREATE INDEX idx_bajadas_estacion ON bajadas_bus (id_estacion, fecha_hora);
CREATE INDEX idx_bajadas_visita ON bajadas_bus (id_visita);
ALTER TABLE bajadas_bus ENABLE ROW LEVEL SECURITY;
ALTER TABLE mantenimientos_bus ENABLE ROW LEVEL SECURITY;

CREATE OR REPLACE FUNCTION fn_bajadas_en_bus() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
    v_tipo VARCHAR;
    v_bus UUID;
    v_actual RECORD;
BEGIN
    SELECT tipo, id_bus INTO v_tipo, v_bus FROM molinetes WHERE id_molinete = NEW.id_molinete;
    IF v_tipo IS DISTINCT FROM 'salida_bus' THEN
        RAISE EXCEPTION 'Las bajadas se cuentan en el molinete de salida del bus.';
    END IF;
    NEW.id_bus := v_bus;
    -- Si no se indica la estación (lo normal en el molinete real), es donde está detenido el bus.
    IF NEW.id_visita IS NULL AND NEW.id_estacion IS NULL THEN
        SELECT * INTO v_actual FROM fn_visita_actual_bus(v_bus);
        IF v_actual.id_visita IS NULL THEN
            RAISE EXCEPTION 'El bus no esta detenido en una estacion: no tiene un viaje en curso.';
        END IF;
        NEW.id_visita := v_actual.id_visita;
        NEW.id_estacion := COALESCE(NEW.id_estacion, v_actual.id_estacion);
    END IF;
    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_bajadas_en_bus
BEFORE INSERT ON bajadas_bus
FOR EACH ROW EXECUTE FUNCTION fn_bajadas_en_bus();

CREATE OR REPLACE FUNCTION fn_bajadas_restan() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
    v_placa VARCHAR;
BEGIN
    SELECT b.placa INTO v_placa
      FROM (SELECT id_bus, SUM(cantidad) AS n FROM nuevas GROUP BY id_bus) s
      JOIN buses b ON b.id_bus = s.id_bus
     WHERE s.n > b.pasajeros_a_bordo
     LIMIT 1;
    IF v_placa IS NOT NULL THEN
        RAISE EXCEPTION 'En el bus % el contador de salida marca mas bajadas que pasajeros a bordo.', v_placa;
    END IF;

    UPDATE buses b SET pasajeros_a_bordo = b.pasajeros_a_bordo - s.n
      FROM (SELECT id_bus, SUM(cantidad) AS n FROM nuevas GROUP BY id_bus) s
     WHERE b.id_bus = s.id_bus;
    UPDATE visitas_viaje vv SET bajaron = vv.bajaron + s.n
      FROM (SELECT id_visita, SUM(cantidad) AS n FROM nuevas WHERE id_visita IS NOT NULL GROUP BY id_visita) s
     WHERE vv.id_visita = s.id_visita;
    RETURN NULL;
END;
$$;

CREATE TRIGGER trg_bajadas_restan
AFTER INSERT ON bajadas_bus
REFERENCING NEW TABLE AS nuevas
FOR EACH STATEMENT EXECUTE FUNCTION fn_bajadas_restan();

-- ---------------------------------------------------------------- ALERTA DE BUS LLENO
ALTER TABLE alertas_sistema ADD COLUMN id_bus UUID NULL;
ALTER TABLE alertas_sistema ADD CONSTRAINT fk_alertas_bus FOREIGN KEY (id_bus) REFERENCES buses(id_bus)
    ON UPDATE CASCADE ON DELETE SET NULL;
ALTER TABLE alertas_sistema DROP CONSTRAINT alertas_sistema_tipo_alerta_check;
ALTER TABLE alertas_sistema ADD CONSTRAINT alertas_sistema_tipo_alerta_check
    CHECK (tipo_alerta IN ('refuerzo_bus', 'bus_lleno', 'bahia_ocupada', 'espera_minima', 'otro'));
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DELETE FROM alertas_sistema WHERE tipo_alerta = 'bus_lleno';
ALTER TABLE alertas_sistema DROP CONSTRAINT alertas_sistema_tipo_alerta_check;
ALTER TABLE alertas_sistema ADD CONSTRAINT alertas_sistema_tipo_alerta_check
    CHECK (tipo_alerta IN ('refuerzo_bus', 'bahia_ocupada', 'espera_minima', 'otro'));
ALTER TABLE alertas_sistema DROP CONSTRAINT fk_alertas_bus;
ALTER TABLE alertas_sistema DROP COLUMN id_bus;

DROP TABLE IF EXISTS bajadas_bus;
DROP FUNCTION IF EXISTS fn_bajadas_restan();
DROP FUNCTION IF EXISTS fn_bajadas_en_bus();

DROP TRIGGER IF EXISTS trg_transacciones_suben ON transacciones_pago;
DROP FUNCTION IF EXISTS fn_transacciones_suben();
DROP TRIGGER IF EXISTS trg_transacciones_en_bus ON transacciones_pago;
DROP FUNCTION IF EXISTS fn_transacciones_en_bus();
DROP FUNCTION IF EXISTS fn_visita_actual_bus(UUID);
DELETE FROM transacciones_pago WHERE id_bus IS NOT NULL;
DROP INDEX IF EXISTS idx_transacciones_bus;
DROP INDEX IF EXISTS idx_transacciones_estacion;
DROP INDEX IF EXISTS idx_transacciones_visita;
ALTER TABLE transacciones_pago DROP COLUMN id_visita, DROP COLUMN id_estacion, DROP COLUMN id_bus;

ALTER TABLE visitas_viaje DROP CONSTRAINT chk_visitas_pasajeros;
ALTER TABLE visitas_viaje DROP COLUMN subieron, DROP COLUMN bajaron;

DROP TRIGGER IF EXISTS trg_buses_molinetes ON buses;
DROP FUNCTION IF EXISTS fn_buses_molinetes();
DROP TRIGGER IF EXISTS trg_buses_ocupacion ON buses;
DROP FUNCTION IF EXISTS fn_buses_ocupacion();
ALTER TABLE buses DROP CONSTRAINT chk_buses_a_bordo;
ALTER TABLE buses DROP COLUMN pasajeros_a_bordo;

DELETE FROM molinetes WHERE tipo <> 'estacion';
ALTER TABLE molinetes DROP CONSTRAINT uq_molinetes_bus_tipo;
ALTER TABLE molinetes DROP CONSTRAINT chk_molinetes_ubicacion;
ALTER TABLE molinetes DROP CONSTRAINT chk_molinetes_tipo;
ALTER TABLE molinetes DROP CONSTRAINT fk_molinetes_bus;
ALTER TABLE molinetes DROP COLUMN tipo, DROP COLUMN id_bus;
ALTER TABLE molinetes ALTER COLUMN id_acceso SET NOT NULL;
SQL);
    }
};
