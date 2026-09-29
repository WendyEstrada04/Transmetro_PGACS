<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 12. BITACORA_AUDITORIA
 * Registra quién cambió qué. id_usuario apunta a la tabla users (quien inició sesión,
 * incluidos administradores que no son empleados); id_empleado_usuario se conserva
 * del modelo original.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bitacora_auditoria', function (Blueprint $table) {
            $table->uuid('id_bitacora')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('id_usuario')->nullable();
            $table->uuid('id_empleado_usuario')->nullable();
            $table->string('tabla_afectada', 100);
            $table->string('id_registro_afectado', 36)->nullable();
            $table->enum('accion', ['INSERT', 'UPDATE', 'DELETE']);
            $table->jsonb('datos_previos')->nullable();
            $table->jsonb('datos_nuevos')->nullable();
            $table->timestamp('fecha_registro')->useCurrent();

            $table->index(['tabla_afectada', 'id_registro_afectado'], 'idx_bitacora_tabla');
            $table->foreign('id_usuario', 'fk_bitacora_usuario')
                ->references('id')->on('users')
                ->cascadeOnUpdate()->nullOnDelete();
            $table->foreign('id_empleado_usuario', 'fk_bitacora_empleado')
                ->references('id_empleado')->on('empleados')
                ->cascadeOnUpdate()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bitacora_auditoria');
    }
};
