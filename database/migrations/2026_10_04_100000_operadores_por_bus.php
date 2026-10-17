<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Operadores por bus y mantenimiento de los buses.
 *
 * Cada operador queda asignado a UN bus (y por lo tanto a su chofer): desde su pantalla sigue el
 * recorrido del bus, su mantenimiento, los pasajeros y las estaciones de la línea. Ya no se asignan
 * a una estación. La seguridad sigue siendo por estación (por cada acceso).
 *
 * mantenimientos_bus guarda el historial de fallas reportadas y mantenimientos realizados.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operadores', function (Blueprint $table) {
            $table->uuid('id_bus_asignado')->nullable();
            $table->unique('id_bus_asignado', 'uq_operadores_bus');
            $table->foreign('id_bus_asignado', 'fk_operadores_bus')
                ->references('id_bus')->on('buses')
                ->cascadeOnUpdate()->nullOnDelete();
        });

        // Los operadores que ya existen pasan a un bus con chofer que todavía no tenga operador
        // (en el orden de las placas). Los que sobren quedan sin bus hasta que se les asigne uno.
        DB::statement(<<<'SQL'
            WITH ops AS (SELECT id_empleado, row_number() OVER (ORDER BY id_empleado) AS n FROM operadores),
                 bs AS (SELECT b.id_bus, row_number() OVER (ORDER BY b.placa) AS n
                          FROM buses b JOIN pilotos p ON p.id_bus_asignado = b.id_bus)
            UPDATE operadores o SET id_bus_asignado = bs.id_bus
              FROM ops JOIN bs ON bs.n = ops.n
             WHERE o.id_empleado = ops.id_empleado
            SQL);

        Schema::table('operadores', function (Blueprint $table) {
            $table->dropForeign('fk_operadores_estacion');
            $table->dropIndex('idx_operadores_estacion');
            $table->dropColumn('id_estacion_asignada');
        });

        Schema::create('mantenimientos_bus', function (Blueprint $table) {
            $table->uuid('id_mantenimiento')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('id_bus');
            $table->uuid('id_empleado_reporta')->nullable();
            $table->enum('tipo', ['falla', 'preventivo', 'correctivo']);
            $table->string('categoria', 30);
            $table->text('descripcion');
            $table->enum('prioridad', ['baja', 'media', 'alta'])->default('media');
            $table->enum('estado', ['reportado', 'en_proceso', 'resuelto'])->default('reportado');
            $table->integer('kilometraje')->nullable();
            $table->timestamp('fecha_reporte')->useCurrent();
            $table->timestamp('fecha_cierre')->nullable();

            $table->index(['id_bus', 'fecha_reporte'], 'idx_mantenimientos_bus');
            $table->foreign('id_bus', 'fk_mantenimientos_bus')
                ->references('id_bus')->on('buses')
                ->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreign('id_empleado_reporta', 'fk_mantenimientos_empleado')
                ->references('id_empleado')->on('empleados')
                ->cascadeOnUpdate()->nullOnDelete();
        });

        DB::statement('ALTER TABLE mantenimientos_bus ADD CONSTRAINT chk_mantenimientos_km CHECK (kilometraje IS NULL OR kilometraje >= 0)');
        DB::statement("ALTER TABLE mantenimientos_bus ADD CONSTRAINT chk_mantenimientos_cierre CHECK ((estado = 'resuelto') = (fecha_cierre IS NOT NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('mantenimientos_bus');

        Schema::table('operadores', function (Blueprint $table) {
            $table->uuid('id_estacion_asignada')->nullable();
            $table->index('id_estacion_asignada', 'idx_operadores_estacion');
            $table->foreign('id_estacion_asignada', 'fk_operadores_estacion')
                ->references('id_estacion')->on('estaciones')
                ->cascadeOnUpdate()->nullOnDelete();
        });

        Schema::table('operadores', function (Blueprint $table) {
            $table->dropForeign('fk_operadores_bus');
            $table->dropUnique('uq_operadores_bus');
            $table->dropColumn('id_bus_asignado');
        });
    }
};
