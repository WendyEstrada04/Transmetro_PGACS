<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El DPI de Guatemala tiene exactamente 13 dígitos (se escribe 1234 56789 0101).
 * La base de datos lo exige para los empleados y para el titular de una Tarjeta Ciudadana.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
UPDATE empleados SET dpi = regexp_replace(dpi, '\D', '', 'g') WHERE dpi ~ '\D';
UPDATE tarjetas SET dpi_titular = regexp_replace(dpi_titular, '\D', '', 'g') WHERE dpi_titular ~ '\D';
ALTER TABLE empleados ADD CONSTRAINT chk_empleados_dpi CHECK (dpi ~ '^[0-9]{13}$');
ALTER TABLE tarjetas ADD CONSTRAINT chk_tarjetas_dpi CHECK (dpi_titular IS NULL OR dpi_titular ~ '^[0-9]{13}$');
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE tarjetas DROP CONSTRAINT IF EXISTS chk_tarjetas_dpi;
ALTER TABLE empleados DROP CONSTRAINT IF EXISTS chk_empleados_dpi;
SQL);
    }
};
