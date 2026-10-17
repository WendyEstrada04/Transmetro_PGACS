<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rendimiento de la base de datos.
 *
 * El compilador JIT de PostgreSQL está pensado para consultas analíticas muy largas. En consultas cortas
 * como las del sistema (paneles con varias subconsultas) gasta más tiempo compilando que ejecutando:
 * el panel de una línea tardaba ~200 ms solo en compilar. Se apaga para el usuario de la aplicación.
 * Si el servidor no lo permite (sin privilegios), se deja como está.
 *
 * Además se agrega un índice para las cuentas del día por línea y estación (subidas de pasajeros).
 */
return new class extends Migration
{
    public function up(): void
    {
        try {
            // Dentro de un punto de guardado: si falla, la migración sigue sin abortar la transacción.
            DB::transaction(fn () => DB::statement('ALTER ROLE CURRENT_USER SET jit = off'));
        } catch (Throwable) {
            // Sin permiso para cambiarlo: no es necesario para que el sistema funcione.
        }

        DB::statement('CREATE INDEX IF NOT EXISTS idx_transacciones_linea_fecha ON transacciones_pago (id_linea, fecha_hora)');
    }

    public function down(): void
    {
        try {
            DB::transaction(fn () => DB::statement('ALTER ROLE CURRENT_USER RESET jit'));
        } catch (Throwable) {
        }
        DB::statement('DROP INDEX IF EXISTS idx_transacciones_linea_fecha');
    }
};
