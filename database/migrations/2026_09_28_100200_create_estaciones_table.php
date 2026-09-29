<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 3. ESTACIONES
 * Cada estación pertenece a un municipio (punto 11). Las coordenadas se guardan como
 * latitud/longitud (en MySQL eran un POINT; así no se depende de PostGIS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estaciones', function (Blueprint $table) {
            $table->uuid('id_estacion')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('id_municipio');
            $table->string('nombre', 150);
            $table->integer('aforo_maximo');
            $table->integer('aforo_actual')->default(0);
            $table->decimal('latitud', 9, 6);
            $table->decimal('longitud', 9, 6);

            $table->foreign('id_municipio', 'fk_estaciones_municipio')
                ->references('id_municipio')->on('municipios')
                ->cascadeOnUpdate()->restrictOnDelete();
        });

        DB::statement('ALTER TABLE estaciones ADD CONSTRAINT chk_estaciones_aforo CHECK (aforo_maximo > 0 AND aforo_actual >= 0)');
        DB::statement('ALTER TABLE estaciones ADD CONSTRAINT chk_estaciones_coordenadas CHECK (latitud BETWEEN -90 AND 90 AND longitud BETWEEN -180 AND 180)');
    }

    public function down(): void
    {
        Schema::dropIfExists('estaciones');
    }
};
