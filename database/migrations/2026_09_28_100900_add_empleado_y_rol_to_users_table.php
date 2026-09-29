<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vincula los usuarios que inician sesión en la aplicación web con su empleado
 * (por ejemplo, el operador de una estación) y define su rol en el sistema.
 * Un administrador de la municipalidad puede no ser empleado (id_empleado nulo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('rol', ['administrador', 'operador'])->default('operador');
            $table->uuid('id_empleado')->nullable();

            $table->unique('id_empleado', 'uq_users_empleado');
            $table->foreign('id_empleado', 'fk_users_empleado')
                ->references('id_empleado')->on('empleados')
                ->cascadeOnUpdate()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign('fk_users_empleado');
            $table->dropUnique('uq_users_empleado');
            $table->dropColumn(['id_empleado', 'rol']);
        });
    }
};
