<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 8. PARQUEOS
 * Puntos 6 y 7: algunas estaciones tienen parqueos; cada bus tiene un parqueo, puede
 * cambiar de parqueo pero no quedar sin él (procedimiento sp_cambiar_parqueo_bus) y
 * nunca ocupa dos parqueos a la vez (UNIQUE en id_bus_asignado).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parqueos', function (Blueprint $table) {
            $table->uuid('id_parqueo')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('id_estacion');
            $table->string('codigo_bahia', 20);
            $table->uuid('id_bus_asignado')->nullable();

            $table->unique('id_bus_asignado', 'uq_parqueos_bus');
            $table->unique(['id_estacion', 'codigo_bahia'], 'uq_parqueos_estacion_bahia');
            $table->foreign('id_estacion', 'fk_parqueos_estacion')
                ->references('id_estacion')->on('estaciones')
                ->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign('id_bus_asignado', 'fk_parqueos_bus')
                ->references('id_bus')->on('buses')
                ->cascadeOnUpdate()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parqueos');
    }
};
