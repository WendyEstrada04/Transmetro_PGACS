<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 4. LINEA_ESTACION (tabla asociativa M:N)
 * Puntos 1, 2 y 12: una línea es una serie de estaciones en orden; una estación puede
 * estar en varias líneas; distancia_previa_km = distancia desde la parada anterior.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('linea_estacion', function (Blueprint $table) {
            $table->uuid('id_linea');
            $table->uuid('id_estacion');
            $table->integer('orden_parada');
            $table->decimal('distancia_previa_km', 6, 2)->default(0);

            $table->primary(['id_linea', 'id_estacion']);
            $table->unique(['id_linea', 'orden_parada'], 'uq_linea_orden');
            $table->index('id_estacion', 'idx_linea_estacion_estacion');
            $table->foreign('id_linea', 'fk_linea_estacion_linea')
                ->references('id_linea')->on('lineas')
                ->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreign('id_estacion', 'fk_linea_estacion_estacion')
                ->references('id_estacion')->on('estaciones')
                ->cascadeOnUpdate()->restrictOnDelete();
        });

        DB::statement('ALTER TABLE linea_estacion ADD CONSTRAINT chk_linea_estacion_orden CHECK (orden_parada >= 1)');
        DB::statement('ALTER TABLE linea_estacion ADD CONSTRAINT chk_linea_estacion_distancia CHECK (distancia_previa_km >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('linea_estacion');
    }
};
