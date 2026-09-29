<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1. MUNICIPIOS
 * Punto 11 del enunciado: cada línea y estación pertenece a una municipalidad.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('municipios', function (Blueprint $table) {
            $table->uuid('id_municipio')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('codigo_iso', 10);
            $table->string('nombre', 150);
            $table->boolean('activo')->default(true);

            $table->unique('codigo_iso', 'uq_municipios_codigo_iso');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('municipios');
    }
};
