<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * NUEVO — Punto 18 del enunciado: "Métodos de pago, tarjeta de acceso".
 * TARJETAS: la tarjeta Transmetro del pasajero con su saldo.
 * RECARGAS: cada vez que se le carga saldo a una tarjeta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tarjetas', function (Blueprint $table) {
            $table->uuid('id_tarjeta')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('numero_tarjeta', 20);
            $table->decimal('saldo', 8, 2)->default(0);
            $table->enum('estado', ['activa', 'bloqueada', 'vencida'])->default('activa');
            $table->timestamp('fecha_emision')->useCurrent();

            $table->unique('numero_tarjeta', 'uq_tarjetas_numero');
        });

        Schema::create('recargas', function (Blueprint $table) {
            $table->uuid('id_recarga')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('id_tarjeta');
            $table->decimal('monto', 8, 2);
            $table->enum('metodo_pago', ['efectivo', 'credito', 'debito']);
            $table->timestamp('fecha_hora')->useCurrent();

            $table->index('id_tarjeta', 'idx_recargas_tarjeta');
            $table->foreign('id_tarjeta', 'fk_recargas_tarjeta')
                ->references('id_tarjeta')->on('tarjetas')
                ->cascadeOnUpdate()->restrictOnDelete();
        });

        DB::statement('ALTER TABLE tarjetas ADD CONSTRAINT chk_tarjetas_saldo CHECK (saldo >= 0)');
        DB::statement('ALTER TABLE recargas ADD CONSTRAINT chk_recargas_monto CHECK (monto > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('recargas');
        Schema::dropIfExists('tarjetas');
    }
};
