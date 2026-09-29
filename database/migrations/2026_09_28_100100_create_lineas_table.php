<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 2. LINEAS
 * Puntos 11 y 13: la línea pertenece a un municipio y se conoce su distancia total.
 * distancia_total_km se recalcula automáticamente (trigger trg_linea_estacion_distancia)
 * como la suma de las distancias entre sus paradas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lineas', function (Blueprint $table) {
            $table->uuid('id_linea')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('id_municipio');
            $table->string('codigo_linea', 20);
            $table->decimal('distancia_total_km', 6, 2)->default(0);
            $table->enum('estado', ['activa', 'inactiva', 'mantenimiento'])->default('activa');

            $table->unique('codigo_linea', 'uq_lineas_codigo');
            $table->foreign('id_municipio', 'fk_lineas_municipio')
                ->references('id_municipio')->on('municipios')
                ->cascadeOnUpdate()->restrictOnDelete();
        });

        DB::statement('ALTER TABLE lineas ADD CONSTRAINT chk_lineas_distancia CHECK (distancia_total_km >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('lineas');
    }
};
