<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 13. VIAJES y VISITAS_VIAJE
 * Punto 1: registrar las visitas que cada unidad realiza en el recorrido.
 * Punto 15: con hora_llegada/hora_salida se comprueba la espera de 5 minutos
 * cuando el bus va por debajo del 25 % de su capacidad.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('viajes', function (Blueprint $table) {
            $table->uuid('id_viaje')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('id_bus');
            $table->uuid('id_linea');
            $table->timestamp('fecha_hora_inicio')->useCurrent();
            $table->timestamp('fecha_hora_fin')->nullable();
            $table->enum('estado', ['en_curso', 'finalizado', 'cancelado'])->default('en_curso');

            $table->index('id_bus', 'idx_viajes_bus');
            $table->index('id_linea', 'idx_viajes_linea');
            $table->foreign('id_bus', 'fk_viajes_bus')
                ->references('id_bus')->on('buses')
                ->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign('id_linea', 'fk_viajes_linea')
                ->references('id_linea')->on('lineas')
                ->cascadeOnUpdate()->restrictOnDelete();
        });

        Schema::create('visitas_viaje', function (Blueprint $table) {
            $table->uuid('id_visita')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('id_viaje');
            $table->uuid('id_estacion');
            $table->integer('orden_visita');
            $table->timestamp('hora_llegada')->nullable();
            $table->timestamp('hora_salida')->nullable();
            $table->decimal('ocupacion_al_llegar_pct', 5, 2)->nullable();

            $table->unique(['id_viaje', 'orden_visita'], 'uq_visitas_viaje_orden');
            $table->index(['id_estacion', 'hora_llegada'], 'idx_visitas_estacion');
            $table->foreign('id_viaje', 'fk_visitas_viaje')
                ->references('id_viaje')->on('viajes')
                ->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreign('id_estacion', 'fk_visitas_estacion')
                ->references('id_estacion')->on('estaciones')
                ->cascadeOnUpdate()->restrictOnDelete();
        });

        DB::statement('ALTER TABLE viajes ADD CONSTRAINT chk_viajes_fechas CHECK (fecha_hora_fin IS NULL OR fecha_hora_fin >= fecha_hora_inicio)');
        DB::statement('ALTER TABLE visitas_viaje ADD CONSTRAINT chk_visitas_orden CHECK (orden_visita >= 1)');
        DB::statement('ALTER TABLE visitas_viaje ADD CONSTRAINT chk_visitas_horas CHECK (hora_salida IS NULL OR hora_llegada IS NULL OR hora_salida >= hora_llegada)');
        DB::statement('ALTER TABLE visitas_viaje ADD CONSTRAINT chk_visitas_ocupacion CHECK (ocupacion_al_llegar_pct IS NULL OR ocupacion_al_llegar_pct BETWEEN 0 AND 100)');
    }

    public function down(): void
    {
        Schema::dropIfExists('visitas_viaje');
        Schema::dropIfExists('viajes');
    }
};
