<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 7. MOLINETES
 * Cada acceso tiene un molinete propio (1:1 → UNIQUE en id_acceso).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('molinetes', function (Blueprint $table) {
            $table->uuid('id_molinete')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('id_acceso');
            $table->string('ip_address', 45);
            $table->enum('estado_operativo', ['activo', 'inactivo', 'mantenimiento'])->default('activo');

            $table->unique('id_acceso', 'uq_molinetes_acceso');
            $table->foreign('id_acceso', 'fk_molinetes_acceso')
                ->references('id_acceso')->on('accesos')
                ->cascadeOnUpdate()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('molinetes');
    }
};
