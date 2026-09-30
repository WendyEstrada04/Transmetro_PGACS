<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Trazo de la ruta: la línea que sigue las calles por donde pasa el bus en el mapa.
 * Se guarda como una lista de puntos [latitud, longitud] (JSONB). Si está vacío,
 * el mapa une las estaciones en línea recta.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE lineas
    ADD COLUMN trazo JSONB NULL,
    ADD CONSTRAINT chk_lineas_trazo CHECK (trazo IS NULL OR jsonb_typeof(trazo) = 'array');
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE lineas
    DROP CONSTRAINT IF EXISTS chk_lineas_trazo,
    DROP COLUMN IF EXISTS trazo;
SQL);
    }
};
