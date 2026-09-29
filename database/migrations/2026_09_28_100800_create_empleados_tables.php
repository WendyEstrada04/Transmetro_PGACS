<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 9. EMPLEADOS y sus subtipos PILOTOS, SEGURIDAD y OPERADORES.
 * Punto 9: de los pilotos se guarda historial educativo, residencia y comunicación.
 * Punto 10: cada acceso tiene al menos un guardia de seguridad.
 * Punto 16: cada estación tiene una PC de trabajo con un operador.
 *
 * Los campos *_encriptado son TEXT porque Laravel los cifra con el cast 'encrypted'
 * (AES-256) antes de guardarlos; en MySQL eran BLOB.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('empleados', function (Blueprint $table) {
            $table->uuid('id_empleado')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('dpi', 20);
            $table->string('nombre_completo', 200);
            $table->date('fecha_nacimiento');
            $table->enum('rol', ['piloto', 'seguridad', 'operador']);
            $table->text('contacto_encriptado');
            $table->text('direccion_encriptada');
            $table->boolean('activo')->default(true);

            $table->unique('dpi', 'uq_empleados_dpi');
        });

        Schema::create('pilotos', function (Blueprint $table) {
            $table->uuid('id_empleado')->primary();
            $table->string('numero_licencia', 30);
            $table->string('tipo_licencia', 10);
            $table->integer('anios_experiencia')->default(0);
            $table->string('nivel_educativo', 50);
            $table->text('historial_educativo_encriptado')->nullable();
            $table->uuid('id_bus_asignado')->nullable();

            $table->unique('numero_licencia', 'uq_pilotos_licencia');
            $table->unique('id_bus_asignado', 'uq_pilotos_bus');
            $table->foreign('id_empleado', 'fk_pilotos_empleado')
                ->references('id_empleado')->on('empleados')
                ->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreign('id_bus_asignado', 'fk_pilotos_bus')
                ->references('id_bus')->on('buses')
                ->cascadeOnUpdate()->nullOnDelete();
        });

        Schema::create('seguridad', function (Blueprint $table) {
            $table->uuid('id_empleado')->primary();
            $table->string('permisos_vigentes', 100);
            $table->string('constancia_antecedentes_penales', 100);
            $table->string('numero_placa_asignada', 15)->nullable();
            $table->uuid('id_acceso_asignado')->nullable();

            $table->index('id_acceso_asignado', 'idx_seguridad_acceso');
            $table->foreign('id_empleado', 'fk_seguridad_empleado')
                ->references('id_empleado')->on('empleados')
                ->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreign('id_acceso_asignado', 'fk_seguridad_acceso')
                ->references('id_acceso')->on('accesos')
                ->cascadeOnUpdate()->nullOnDelete();
        });

        Schema::create('operadores', function (Blueprint $table) {
            $table->uuid('id_empleado')->primary();
            $table->string('nivel_estudios', 50);
            $table->integer('anios_experiencia_monitoreo')->default(0);
            $table->uuid('id_estacion_asignada')->nullable();

            $table->index('id_estacion_asignada', 'idx_operadores_estacion');
            $table->foreign('id_empleado', 'fk_operadores_empleado')
                ->references('id_empleado')->on('empleados')
                ->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreign('id_estacion_asignada', 'fk_operadores_estacion')
                ->references('id_estacion')->on('estaciones')
                ->cascadeOnUpdate()->nullOnDelete();
        });

        DB::statement('ALTER TABLE pilotos ADD CONSTRAINT chk_pilotos_experiencia CHECK (anios_experiencia >= 0)');
        DB::statement('ALTER TABLE operadores ADD CONSTRAINT chk_operadores_experiencia CHECK (anios_experiencia_monitoreo >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('operadores');
        Schema::dropIfExists('seguridad');
        Schema::dropIfExists('pilotos');
        Schema::dropIfExists('empleados');
    }
};
