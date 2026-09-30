<?php

use App\Models\Linea;
use App\Models\Molinete;
use App\Services\CobroPasaje;
use Illuminate\Validation\ValidationException;

/*
 * Punto 18: cobro del pasaje con Tarjeta Ciudadana o tarjeta bancaria sin contacto.
 */

beforeEach(function () {
    $this->cobro = app(CobroPasaje::class);
    $this->linea = Linea::where('codigo_linea', 'L1')->firstOrFail();
    $this->molinete = Molinete::where('estado_operativo', 'activo')->firstOrFail();
});

test('los 5 viajes gratis se usan antes que el saldo', function () {
    $tarjeta = tarjetaDePrueba(['viajes_gratis_restantes' => 5, 'saldo' => 10]);

    foreach (range(1, 5) as $viaje) {
        $pago = $this->cobro->cobrar($this->molinete, $this->linea, 'tarjeta_ciudadana', $tarjeta->numero_tarjeta);
        expect($pago->viaje_gratis)->toBeTrue()->and((float) $pago->monto)->toBe(0.0);
    }

    $tarjeta->refresh();
    expect($tarjeta->viajes_gratis_restantes)->toBe(0)
        ->and((float) $tarjeta->saldo)->toBe(10.0);

    // El sexto viaje ya se descuenta del saldo con la tarifa de la línea.
    $pago = $this->cobro->cobrar($this->molinete, $this->linea, 'tarjeta_ciudadana', $tarjeta->numero_tarjeta);

    expect($pago->viaje_gratis)->toBeFalse()
        ->and((float) $pago->monto)->toBe((float) $this->linea->tarifa)
        ->and((float) $tarjeta->refresh()->saldo)->toBe(10 - (float) $this->linea->tarifa);
});

test('el número de tarjeta se acepta en minúsculas y con espacios', function () {
    $tarjeta = tarjetaDePrueba(['saldo' => 5]);

    $pago = $this->cobro->cobrar($this->molinete, $this->linea, 'tarjeta_ciudadana', '  '.strtolower($tarjeta->numero_tarjeta).' ');

    expect($pago->id_tarjeta)->toBe($tarjeta->id_tarjeta);
});

test('sin saldo suficiente no se cobra ni se registra el pase', function () {
    $tarjeta = tarjetaDePrueba(['saldo' => 0.50]);
    $pagosAntes = $tarjeta->transacciones()->count();

    expect(fn () => $this->cobro->cobrar($this->molinete, $this->linea, 'tarjeta_ciudadana', $tarjeta->numero_tarjeta))
        ->toThrow(ValidationException::class, 'Saldo insuficiente');

    expect((float) $tarjeta->refresh()->saldo)->toBe(0.5)
        ->and($tarjeta->transacciones()->count())->toBe($pagosAntes);
});

test('una tarjeta bloqueada es rechazada', function () {
    $tarjeta = tarjetaDePrueba(['saldo' => 50, 'estado' => 'bloqueada']);

    expect(fn () => $this->cobro->cobrar($this->molinete, $this->linea, 'tarjeta_ciudadana', $tarjeta->numero_tarjeta))
        ->toThrow(ValidationException::class, 'bloqueada');
});

test('una tarjeta que no existe es rechazada', function () {
    expect(fn () => $this->cobro->cobrar($this->molinete, $this->linea, 'tarjeta_ciudadana', 'NOEXISTE123'))
        ->toThrow(ValidationException::class, 'No existe una tarjeta');
});

test('con tarjeta bancaria se cobra la tarifa sin usar Tarjeta Ciudadana', function () {
    $pago = $this->cobro->cobrar($this->molinete, $this->linea, 'tarjeta_bancaria');

    expect($pago->id_tarjeta)->toBeNull()
        ->and($pago->metodo_pago)->toBe('tarjeta_bancaria')
        ->and((float) $pago->monto)->toBe((float) $this->linea->tarifa);
});

test('no se cobra en un molinete fuera de servicio', function () {
    $this->molinete->estado_operativo = 'mantenimiento';

    expect(fn () => $this->cobro->cobrar($this->molinete, $this->linea, 'tarjeta_bancaria'))
        ->toThrow(ValidationException::class, 'no está activo');
});

test('no se cobra en una línea inactiva', function () {
    $this->linea->estado = 'inactiva';

    expect(fn () => $this->cobro->cobrar($this->molinete, $this->linea, 'tarjeta_bancaria'))
        ->toThrow(ValidationException::class, 'no está activa');
});
