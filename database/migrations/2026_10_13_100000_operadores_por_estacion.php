<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Operador por estación (punto 16: "una PC de trabajo para cada estación de bus" con un operador que la controla).
 *
 * Cada operador queda asignado a UNA estación y cada estación tiene como máximo un operador. Desde la PC de su
 * estación ve cuánta gente espera, los buses detenidos y en camino, pide otro bus, atiende las alertas de la
 * estación, controla los molinetes de sus accesos y ve a los guardias de turno.
 * Antes el operador acompañaba a un bus (id_bus_asignado): se reemplaza por id_estacion_asignada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operadores', function (Blueprint $table) {
            $table->uuid('id_estacion_asignada')->nullable();
            $table->unique('id_estacion_asignada', 'uq_operadores_estacion');
            $table->foreign('id_estacion_asignada', 'fk_operadores_estacion')
                ->references('id_estacion')->on('estaciones')
                ->cascadeOnUpdate()->nullOnDelete();
        });

        // Los operadores que ya existen pasan a una estación que todavía no tenga operador (en orden alfabético).
        // Los que sobren quedan sin estación hasta que el administrador les asigne una.
        DB::statement(<<<'SQL'
            WITH ops AS (SELECT id_empleado, row_number() OVER (ORDER BY id_empleado) AS n FROM operadores),
                 est AS (SELECT id_estacion, row_number() OVER (ORDER BY nombre) AS n FROM estaciones)
            UPDATE operadores o SET id_estacion_asignada = est.id_estacion
              FROM ops JOIN est ON est.n = ops.n
             WHERE o.id_empleado = ops.id_empleado
            SQL);

        Schema::table('operadores', function (Blueprint $table) {
            $table->dropForeign('fk_operadores_bus');
            $table->dropUnique('uq_operadores_bus');
            $table->dropColumn('id_bus_asignado');
        });
    }

    public function down(): void
    {
        Schema::table('operadores', function (Blueprint $table) {
            $table->uuid('id_bus_asignado')->nullable();
            $table->unique('id_bus_asignado', 'uq_operadores_bus');
            $table->foreign('id_bus_asignado', 'fk_operadores_bus')
                ->references('id_bus')->on('buses')
                ->cascadeOnUpdate()->nullOnDelete();
        });

        Schema::table('operadores', function (Blueprint $table) {
            $table->dropForeign('fk_operadores_estacion');
            $table->dropUnique('uq_operadores_estacion');
            $table->dropColumn('id_estacion_asignada');
        });
    }
};
