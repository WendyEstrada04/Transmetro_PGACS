<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 11. TRANSACCIONES_PAGO
 * Punto 18: cada pago registrado en un molinete. Si se paga con tarjeta Transmetro,
 * se guarda qué tarjeta se usó (id_tarjeta).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transacciones_pago', function (Blueprint $table) {
            $table->uuid('id_transaccion')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('id_molinete');
            $table->uuid('id_tarjeta')->nullable();
            $table->decimal('monto', 8, 2);
            $table->enum('metodo_pago', ['tarjeta_transmetro', 'credito', 'debito', 'efectivo']);
            $table->timestamp('fecha_hora')->useCurrent();

            $table->index('fecha_hora', 'idx_transacciones_fecha');
            $table->index('id_tarjeta', 'idx_transacciones_tarjeta');
            $table->foreign('id_molinete', 'fk_transacciones_molinete')
                ->references('id_molinete')->on('molinetes')
                ->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign('id_tarjeta', 'fk_transacciones_tarjeta')
                ->references('id_tarjeta')->on('tarjetas')
                ->cascadeOnUpdate()->restrictOnDelete();
        });

        DB::statement('ALTER TABLE transacciones_pago ADD CONSTRAINT chk_transacciones_monto CHECK (monto > 0)');
        DB::statement("ALTER TABLE transacciones_pago ADD CONSTRAINT chk_transacciones_tarjeta CHECK (metodo_pago <> 'tarjeta_transmetro' OR id_tarjeta IS NOT NULL)");
    }

    public function down(): void
    {
        Schema::dropIfExists('transacciones_pago');
    }
};
