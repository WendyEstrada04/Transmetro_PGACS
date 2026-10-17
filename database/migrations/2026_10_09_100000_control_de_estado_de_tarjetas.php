<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Control de estado de la Tarjeta Ciudadana desde el sistema del Transmetro.
 *
 * La emisión, la afiliación, la reposición y las recargas las hacen los puntos de atención (POCAE)
 * y los puntos de recarga; el administrador del Transmetro solo consulta las tarjetas y controla su estado:
 *  - bloquear (robo o extravío, uso indebido, …) y desbloquear;
 *  - dar de baja: la tarjeta queda anulada para siempre.
 *
 * tarjetas:
 *  - estado nuevo 'baja' (dada de baja).
 *  - motivo_estado y fecha_estado: por qué y cuándo cambió su estado por última vez.
 * Reglas en la base de datos:
 *  - trg_tarjetas_estado_final: una tarjeta dada de baja o reemplazada ya no cambia de estado;
 *    al cambiar el estado se registra la fecha.
 *  - trg_transacciones_tarjeta_activa / trg_recargas_tarjeta_activa: solo se paga y se recarga con tarjetas activas.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE tarjetas DROP CONSTRAINT tarjetas_estado_check;
ALTER TABLE tarjetas ADD CONSTRAINT tarjetas_estado_check
    CHECK (estado IN ('activa', 'bloqueada', 'vencida', 'reemplazada', 'baja'));

ALTER TABLE tarjetas ADD COLUMN motivo_estado VARCHAR(200) NULL;
ALTER TABLE tarjetas ADD COLUMN fecha_estado TIMESTAMP(0) WITHOUT TIME ZONE NULL;

UPDATE tarjetas SET motivo_estado = 'Robo o extravío reportado', fecha_estado = now()::timestamp(0)
 WHERE estado = 'bloqueada' AND motivo_estado IS NULL;
UPDATE tarjetas SET motivo_estado = 'Reposición: el saldo pasó a una tarjeta nueva'
 WHERE estado = 'reemplazada' AND motivo_estado IS NULL;

CREATE OR REPLACE FUNCTION fn_tarjetas_estado_final() RETURNS trigger AS $$
BEGIN
    IF NEW.estado IS DISTINCT FROM OLD.estado THEN
        IF OLD.estado IN ('baja', 'reemplazada') THEN
            RAISE EXCEPTION 'La tarjeta % está %: su estado ya no se puede cambiar.', OLD.numero_tarjeta,
                CASE OLD.estado WHEN 'baja' THEN 'dada de baja' ELSE 'reemplazada' END;
        END IF;
        IF NEW.fecha_estado IS NOT DISTINCT FROM OLD.fecha_estado THEN
            NEW.fecha_estado := now()::timestamp(0);
        END IF;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_tarjetas_estado_final BEFORE UPDATE OF estado ON tarjetas
    FOR EACH ROW EXECUTE FUNCTION fn_tarjetas_estado_final();

CREATE OR REPLACE FUNCTION fn_tarjeta_activa() RETURNS trigger AS $$
DECLARE
    v_estado VARCHAR;
    v_numero VARCHAR;
BEGIN
    IF NEW.id_tarjeta IS NULL THEN
        RETURN NEW;
    END IF;
    SELECT estado, numero_tarjeta INTO v_estado, v_numero FROM tarjetas WHERE id_tarjeta = NEW.id_tarjeta;
    IF v_estado IS DISTINCT FROM 'activa' THEN
        RAISE EXCEPTION 'La tarjeta % no está activa (%): no se puede usar.', v_numero, v_estado;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_transacciones_tarjeta_activa BEFORE INSERT ON transacciones_pago
    FOR EACH ROW EXECUTE FUNCTION fn_tarjeta_activa();
CREATE TRIGGER trg_recargas_tarjeta_activa BEFORE INSERT ON recargas
    FOR EACH ROW EXECUTE FUNCTION fn_tarjeta_activa();
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS trg_recargas_tarjeta_activa ON recargas;
DROP TRIGGER IF EXISTS trg_transacciones_tarjeta_activa ON transacciones_pago;
DROP FUNCTION IF EXISTS fn_tarjeta_activa();
DROP TRIGGER IF EXISTS trg_tarjetas_estado_final ON tarjetas;
DROP FUNCTION IF EXISTS fn_tarjetas_estado_final();
UPDATE tarjetas SET estado = 'bloqueada' WHERE estado = 'baja';
ALTER TABLE tarjetas DROP COLUMN fecha_estado, DROP COLUMN motivo_estado;
ALTER TABLE tarjetas DROP CONSTRAINT tarjetas_estado_check;
ALTER TABLE tarjetas ADD CONSTRAINT tarjetas_estado_check CHECK (estado IN ('activa', 'bloqueada', 'vencida', 'reemplazada'));
SQL);
    }
};
