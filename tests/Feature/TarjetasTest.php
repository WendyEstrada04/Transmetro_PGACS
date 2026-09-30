<?php

use App\Models\Recarga;
use App\Models\Tarjeta;

/*
 * Punto 18: emisión y recarga de tarjetas desde la administración.
 */

test('al emitir una Tarjeta Ciudadana trae 5 viajes gratis y su recarga inicial', function () {
    $this->actingAs($this->administrador())
        ->post('/admin/tarjetas', [
            'numero_tarjeta' => 'tcnueva001',
            'tipo_tarjeta' => 'ciudadana',
            'saldo_inicial' => 25,
            'metodo_pago' => 'efectivo',
        ])
        ->assertRedirect();

    $tarjeta = Tarjeta::where('numero_tarjeta', 'TCNUEVA001')->firstOrFail();

    expect($tarjeta->viajes_gratis_restantes)->toBe(Tarjeta::VIAJES_GRATIS)
        ->and((float) $tarjeta->costo_emision)->toBe((float) Tarjeta::COSTO_EMISION)
        ->and((float) $tarjeta->saldo)->toBe(25.0)
        ->and($tarjeta->recargas()->count())->toBe(1);
});

test('una tarjeta de adulto mayor pide nombre y DPI del titular', function () {
    $this->actingAs($this->administrador())
        ->post('/admin/tarjetas', ['numero_tarjeta' => 'TCAM001', 'tipo_tarjeta' => 'adulto_mayor'])
        ->assertSessionHasErrors(['nombre_titular', 'dpi_titular']);
});

test('no se puede repetir el número de tarjeta', function () {
    $existente = Tarjeta::firstOrFail();

    $this->actingAs($this->administrador())
        ->post('/admin/tarjetas', ['numero_tarjeta' => $existente->numero_tarjeta, 'tipo_tarjeta' => 'ciudadana'])
        ->assertSessionHasErrors('numero_tarjeta');
});

test('la recarga suma al saldo', function () {
    $tarjeta = tarjetaDePrueba(['saldo' => 3]);

    $this->actingAs($this->administrador())
        ->post("/admin/tarjetas/{$tarjeta->id_tarjeta}/recargas", ['monto' => 50, 'metodo_pago' => 'debito'])
        ->assertSessionHas('estado');

    expect((float) $tarjeta->refresh()->saldo)->toBe(53.0);
});

test('la recarga debe estar entre Q5 y Q200', function (float $monto) {
    $tarjeta = tarjetaDePrueba();

    $this->actingAs($this->administrador())
        ->post("/admin/tarjetas/{$tarjeta->id_tarjeta}/recargas", ['monto' => $monto, 'metodo_pago' => 'efectivo'])
        ->assertSessionHasErrors('monto');

    expect(Recarga::where('id_tarjeta', $tarjeta->id_tarjeta)->count())->toBe(0);
})->with([1, 250]);

test('una tarjeta bloqueada no se puede recargar', function () {
    $tarjeta = tarjetaDePrueba(['estado' => 'bloqueada']);

    $this->actingAs($this->administrador())
        ->post("/admin/tarjetas/{$tarjeta->id_tarjeta}/recargas", ['monto' => 20, 'metodo_pago' => 'efectivo'])
        ->assertSessionHasErrors('bd');

    expect((float) $tarjeta->refresh()->saldo)->toBe(0.0);
});
