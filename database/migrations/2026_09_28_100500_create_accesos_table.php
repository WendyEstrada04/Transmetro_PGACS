<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 6. ACCESOS
 * Punto 3: una estación tiene varios accesos; un acceso pertenece a una sola estación.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accesos', function (Blueprint $table) {
            $table->uuid('id_acceso')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('id_estacion');
            $table->string('ubicacion_fisica', 100);

            $table->index('id_estacion', 'idx_accesos_estacion');
            $table->foreign('id_estacion', 'fk_accesos_estacion')
                ->references('id_estacion')->on('estaciones')
                ->cascadeOnUpdate()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accesos');
    }
};
