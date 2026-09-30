<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ajustes con la información oficial del Transmetro (Municipalidad de Guatemala):
 *  - horarios_linea: horario de servicio por línea (lunes a viernes, sábado, domingo y feriados).
 *  - lineas: nombre, tipo de servicio y tarifa (Q1.00 paradas continuas, Q2.00 directo).
 *  - tarjetas: Tarjeta Ciudadana (ciudadana, adulto mayor, discapacidad), titular, costo de
 *    emisión (Q20) y viajes gratis incluidos (5).
 *  - recargas: de Q5 a Q200 (efectivo, débito, crédito o en línea).
 *  - transacciones_pago: en el molinete solo Tarjeta Ciudadana o tarjeta bancaria sin contacto;
 *    se guarda la línea (por la tarifa) y si fue un viaje gratis.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
-- 1. Horarios de servicio por línea (inicio y fin en las estaciones extremo)
CREATE TABLE horarios_linea (
    id_horario  UUID NOT NULL DEFAULT gen_random_uuid(),
    id_linea    UUID NOT NULL,
    tipo_dia    VARCHAR(20) NOT NULL,
    hora_inicio TIME NOT NULL,
    hora_fin    TIME NOT NULL,
    CONSTRAINT horarios_linea_pkey PRIMARY KEY (id_horario),
    CONSTRAINT uq_horarios_linea_dia UNIQUE (id_linea, tipo_dia),
    CONSTRAINT fk_horarios_linea FOREIGN KEY (id_linea) REFERENCES lineas (id_linea) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT chk_horarios_tipo_dia CHECK (tipo_dia IN ('lunes_viernes', 'sabado', 'domingo_feriado')),
    CONSTRAINT chk_horarios_rango CHECK (hora_fin > hora_inicio)
);
ALTER TABLE horarios_linea ENABLE ROW LEVEL SECURITY;

-- 2. Líneas: nombre, tipo de servicio y tarifa (Q1.00 paradas continuas, Q2.00 directo)
ALTER TABLE lineas
    ADD COLUMN nombre VARCHAR(100) NULL,
    ADD COLUMN tipo_servicio VARCHAR(20) NOT NULL DEFAULT 'paradas_continuas',
    ADD COLUMN tarifa DECIMAL(5,2) NOT NULL DEFAULT 1.00,
    ADD CONSTRAINT chk_lineas_tipo_servicio CHECK (tipo_servicio IN ('paradas_continuas', 'directo')),
    ADD CONSTRAINT chk_lineas_tarifa CHECK (tarifa >= 0);

-- 3. Tarjeta Ciudadana: tipo, titular (tarjetas personalizadas), costo de emisión y viajes gratis
ALTER TABLE tarjetas
    ADD COLUMN tipo_tarjeta VARCHAR(20) NOT NULL DEFAULT 'ciudadana',
    ADD COLUMN nombre_titular VARCHAR(200) NULL,
    ADD COLUMN dpi_titular VARCHAR(13) NULL,
    ADD COLUMN costo_emision DECIMAL(6,2) NOT NULL DEFAULT 20.00,
    ADD COLUMN viajes_gratis_restantes INTEGER NOT NULL DEFAULT 0,
    ADD CONSTRAINT chk_tarjetas_tipo CHECK (tipo_tarjeta IN ('ciudadana', 'adulto_mayor', 'discapacidad')),
    ADD CONSTRAINT chk_tarjetas_titular CHECK (tipo_tarjeta = 'ciudadana' OR (nombre_titular IS NOT NULL AND dpi_titular IS NOT NULL)),
    ADD CONSTRAINT chk_tarjetas_viajes_gratis CHECK (viajes_gratis_restantes >= 0),
    ADD CONSTRAINT chk_tarjetas_costo CHECK (costo_emision >= 0);

-- 4. Recargas: de Q5 a Q200; también en línea
UPDATE recargas SET monto = LEAST(GREATEST(monto, 5), 200);
ALTER TABLE recargas DROP CONSTRAINT IF EXISTS chk_recargas_monto;
ALTER TABLE recargas ADD CONSTRAINT chk_recargas_monto CHECK (monto BETWEEN 5 AND 200);
ALTER TABLE recargas DROP CONSTRAINT IF EXISTS recargas_metodo_pago_check;
ALTER TABLE recargas ADD CONSTRAINT recargas_metodo_pago_check CHECK (metodo_pago IN ('efectivo', 'debito', 'credito', 'en_linea'));

-- 5. Pagos en molinete: solo Tarjeta Ciudadana o tarjeta bancaria sin contacto (no efectivo)
ALTER TABLE transacciones_pago DROP CONSTRAINT IF EXISTS chk_transacciones_tarjeta;
ALTER TABLE transacciones_pago DROP CONSTRAINT IF EXISTS transacciones_pago_metodo_pago_check;
ALTER TABLE transacciones_pago DROP CONSTRAINT IF EXISTS chk_transacciones_monto;
UPDATE transacciones_pago
   SET metodo_pago = CASE WHEN metodo_pago = 'tarjeta_transmetro' THEN 'tarjeta_ciudadana' ELSE 'tarjeta_bancaria' END;
ALTER TABLE transacciones_pago
    ADD COLUMN id_linea UUID NULL,
    ADD COLUMN viaje_gratis BOOLEAN NOT NULL DEFAULT false,
    ADD CONSTRAINT fk_transacciones_linea FOREIGN KEY (id_linea) REFERENCES lineas (id_linea) ON UPDATE CASCADE ON DELETE SET NULL,
    ADD CONSTRAINT transacciones_pago_metodo_pago_check CHECK (metodo_pago IN ('tarjeta_ciudadana', 'tarjeta_bancaria')),
    ADD CONSTRAINT chk_transacciones_tarjeta CHECK (metodo_pago <> 'tarjeta_ciudadana' OR id_tarjeta IS NOT NULL),
    ADD CONSTRAINT chk_transacciones_monto CHECK (monto >= 0 AND (viaje_gratis OR monto > 0));
CREATE INDEX idx_transacciones_linea ON transacciones_pago (id_linea);
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP INDEX IF EXISTS idx_transacciones_linea;
ALTER TABLE transacciones_pago DROP CONSTRAINT IF EXISTS chk_transacciones_monto;
ALTER TABLE transacciones_pago DROP CONSTRAINT IF EXISTS chk_transacciones_tarjeta;
ALTER TABLE transacciones_pago DROP CONSTRAINT IF EXISTS transacciones_pago_metodo_pago_check;
ALTER TABLE transacciones_pago DROP CONSTRAINT IF EXISTS fk_transacciones_linea;
DELETE FROM transacciones_pago WHERE viaje_gratis;
ALTER TABLE transacciones_pago DROP COLUMN IF EXISTS viaje_gratis, DROP COLUMN IF EXISTS id_linea;
UPDATE transacciones_pago SET metodo_pago = CASE WHEN metodo_pago = 'tarjeta_ciudadana' THEN 'tarjeta_transmetro' ELSE 'debito' END;
ALTER TABLE transacciones_pago
    ADD CONSTRAINT transacciones_pago_metodo_pago_check CHECK (metodo_pago IN ('tarjeta_transmetro', 'credito', 'debito', 'efectivo')),
    ADD CONSTRAINT chk_transacciones_tarjeta CHECK (metodo_pago <> 'tarjeta_transmetro' OR id_tarjeta IS NOT NULL),
    ADD CONSTRAINT chk_transacciones_monto CHECK (monto > 0);

ALTER TABLE recargas DROP CONSTRAINT IF EXISTS recargas_metodo_pago_check;
UPDATE recargas SET metodo_pago = 'efectivo' WHERE metodo_pago = 'en_linea';
ALTER TABLE recargas ADD CONSTRAINT recargas_metodo_pago_check CHECK (metodo_pago IN ('efectivo', 'credito', 'debito'));
ALTER TABLE recargas DROP CONSTRAINT IF EXISTS chk_recargas_monto;
ALTER TABLE recargas ADD CONSTRAINT chk_recargas_monto CHECK (monto > 0);

ALTER TABLE tarjetas
    DROP CONSTRAINT IF EXISTS chk_tarjetas_costo,
    DROP CONSTRAINT IF EXISTS chk_tarjetas_viajes_gratis,
    DROP CONSTRAINT IF EXISTS chk_tarjetas_titular,
    DROP CONSTRAINT IF EXISTS chk_tarjetas_tipo,
    DROP COLUMN IF EXISTS viajes_gratis_restantes,
    DROP COLUMN IF EXISTS costo_emision,
    DROP COLUMN IF EXISTS dpi_titular,
    DROP COLUMN IF EXISTS nombre_titular,
    DROP COLUMN IF EXISTS tipo_tarjeta;

ALTER TABLE lineas
    DROP CONSTRAINT IF EXISTS chk_lineas_tarifa,
    DROP CONSTRAINT IF EXISTS chk_lineas_tipo_servicio,
    DROP COLUMN IF EXISTS tarifa,
    DROP COLUMN IF EXISTS tipo_servicio,
    DROP COLUMN IF EXISTS nombre;

DROP TABLE IF EXISTS horarios_linea;
SQL);
    }
};
