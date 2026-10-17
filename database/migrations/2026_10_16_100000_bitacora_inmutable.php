<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bitácora de auditoría (RF-17, RNF-07):
 *  - La fecha se guarda con microsegundos y con la hora real de cada operación (clock_timestamp), para
 *    ordenar bien varias operaciones hechas en el mismo segundo o en la misma transacción.
 *  - Índice por fecha para la pantalla de consulta (de lo más reciente a lo más antiguo).
 *  - La bitácora no se puede modificar ni borrar (trg_bitacora_inmutable). Solo se permite que la base
 *    ponga en NULL el usuario o el empleado cuando estos se eliminan (ON DELETE SET NULL de sus llaves foráneas).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE bitacora_auditoria ALTER COLUMN fecha_registro TYPE TIMESTAMP(6), ALTER COLUMN fecha_registro SET DEFAULT clock_timestamp()');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_bitacora_fecha ON bitacora_auditoria (fecha_registro DESC)');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION fn_bitacora_inmutable() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'La bitácora de auditoría no se puede eliminar.' USING ERRCODE = 'P0001';
                END IF;
                IF NEW.id_bitacora IS DISTINCT FROM OLD.id_bitacora
                   OR NEW.tabla_afectada IS DISTINCT FROM OLD.tabla_afectada
                   OR NEW.id_registro_afectado IS DISTINCT FROM OLD.id_registro_afectado
                   OR NEW.accion IS DISTINCT FROM OLD.accion
                   OR NEW.datos_previos IS DISTINCT FROM OLD.datos_previos
                   OR NEW.datos_nuevos IS DISTINCT FROM OLD.datos_nuevos
                   OR NEW.fecha_registro IS DISTINCT FROM OLD.fecha_registro
                   OR (NEW.id_usuario IS DISTINCT FROM OLD.id_usuario AND NEW.id_usuario IS NOT NULL)
                   OR (NEW.id_empleado_usuario IS DISTINCT FROM OLD.id_empleado_usuario AND NEW.id_empleado_usuario IS NOT NULL) THEN
                    RAISE EXCEPTION 'La bitácora de auditoría no se puede modificar.' USING ERRCODE = 'P0001';
                END IF;
                RETURN NEW;
            END;
            $$;

            DROP TRIGGER IF EXISTS trg_bitacora_inmutable ON bitacora_auditoria;
            CREATE TRIGGER trg_bitacora_inmutable
                BEFORE UPDATE OR DELETE ON bitacora_auditoria
                FOR EACH ROW EXECUTE FUNCTION fn_bitacora_inmutable();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_bitacora_inmutable ON bitacora_auditoria; DROP FUNCTION IF EXISTS fn_bitacora_inmutable();');
        DB::statement('DROP INDEX IF EXISTS idx_bitacora_fecha');
        DB::statement('ALTER TABLE bitacora_auditoria ALTER COLUMN fecha_registro TYPE TIMESTAMP(0), ALTER COLUMN fecha_registro SET DEFAULT CURRENT_TIMESTAMP');
    }
};
