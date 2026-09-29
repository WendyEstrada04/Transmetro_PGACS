<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 5. BUSES
 * Punto 4: un bus está en una sola línea o en ninguna (FK nullable).
 * Punto 14: capacidad máxima de pasajeros. Punto 15: ocupación actual (%).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buses', function (Blueprint $table) {
            $table->uuid('id_bus')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('placa', 15);
            $table->integer('capacidad_maxima');
            $table->uuid('id_linea_asignada')->nullable();
            $table->decimal('ocupacion_actual_pct', 5, 2)->default(0);
            $table->enum('estado_logico', ['en_ruta', 'en_espera', 'fuera_de_servicio', 'en_mantenimiento'])
                ->default('fuera_de_servicio');

            $table->unique('placa', 'uq_buses_placa');
            $table->index('id_linea_asignada', 'idx_buses_linea');
            $table->foreign('id_linea_asignada', 'fk_buses_linea')
                ->references('id_linea')->on('lineas')
                ->cascadeOnUpdate()->nullOnDelete();
        });

        DB::statement('ALTER TABLE buses ADD CONSTRAINT chk_buses_capacidad CHECK (capacidad_maxima > 0)');
        DB::statement('ALTER TABLE buses ADD CONSTRAINT chk_buses_ocupacion CHECK (ocupacion_actual_pct BETWEEN 0 AND 100)');
    }

    public function down(): void
    {
        Schema::dropIfExists('buses');
    }
};
