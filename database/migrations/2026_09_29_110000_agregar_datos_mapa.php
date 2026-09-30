<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Datos para el mapa de rutas: color oficial de cada línea, ruta circular
 * (la línea regresa a su estación de inicio) y dirección de cada estación.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
-- Datos para el mapa de rutas: color oficial de la línea, si la ruta es circular
-- (regresa a la estación de inicio) y la dirección de cada estación.
ALTER TABLE lineas
    ADD COLUMN color VARCHAR(7) NOT NULL DEFAULT '#155240',
    ADD COLUMN ruta_circular BOOLEAN NOT NULL DEFAULT false,
    ADD CONSTRAINT chk_lineas_color CHECK (color ~ '^#[0-9A-Fa-f]{6}$');

ALTER TABLE estaciones
    ADD COLUMN direccion VARCHAR(200) NULL;
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE estaciones DROP COLUMN IF EXISTS direccion;
ALTER TABLE lineas
    DROP CONSTRAINT IF EXISTS chk_lineas_color,
    DROP COLUMN IF EXISTS ruta_circular,
    DROP COLUMN IF EXISTS color;
SQL);
    }
};
