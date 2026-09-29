<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 10. ALERTAS_SISTEMA
 * Punto 14: alerta para que llegue otro bus cuando la estación se satura.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alertas_sistema', function (Blueprint $table) {
            $table->uuid('id_alerta')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('id_estacion');
            $table->enum('tipo_alerta', ['refuerzo_bus', 'bahia_ocupada', 'espera_minima', 'otro']);
            $table->text('descripcion');
            $table->enum('estado', ['abierta', 'atendida', 'cerrada'])->default('abierta');
            $table->timestamp('fecha_creacion')->useCurrent();
            $table->timestamp('fecha_cierre')->nullable();

            $table->index('estado', 'idx_alertas_estado');
            $table->foreign('id_estacion', 'fk_alertas_estacion')
                ->references('id_estacion')->on('estaciones')
                ->cascadeOnUpdate()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alertas_sistema');
    }
};
