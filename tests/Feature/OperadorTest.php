<?php

use App\Models\AlertaSistema;
use App\Models\BitacoraAuditoria;
use App\Models\Estacion;
use App\Models\TransaccionPago;

/*
 * Punto 16: panel del operador de estación (aforo, alertas y pases por molinete).
 */

beforeEach(function () {
    $this->usuario = $this->operador();
    $this->estacion = $this->usuario->empleado->operador->estacion;
});

test('el operador registra un pase con Tarjeta Ciudadana', function () {
    $tarjeta = tarjetaDePrueba(['saldo' => 20]);
    $acceso = $this->estacion->accesos()->whereHas('molinete')->firstOrFail();
    $linea = $this->estacion->lineas()->firstOrFail();

    $this->actingAs($this->usuario)->from('/operador')
        ->post('/operador/pases', [
            'id_acceso' => $acceso->id_acceso,
            'id_linea' => $linea->id_linea,
            'metodo_pago' => 'tarjeta_ciudadana',
            'numero_tarjeta' => $tarjeta->numero_tarjeta,
        ])
        ->assertRedirect('/operador')
        ->assertSessionHas('estado');

    expect(TransaccionPago::where('id_tarjeta', $tarjeta->id_tarjeta)->count())->toBe(1)
        ->and((float) $tarjeta->refresh()->saldo)->toBe(20 - (float) $linea->tarifa);
});

test('el operador no puede usar un acceso de otra estación', function () {
    $otraEstacion = Estacion::where('id_estacion', '!=', $this->estacion->id_estacion)->firstOrFail();

    $this->actingAs($this->usuario)
        ->post('/operador/pases', [
            'id_acceso' => $otraEstacion->accesos()->value('id_acceso'),
            'id_linea' => $this->estacion->lineas()->value('lineas.id_linea'),
            'metodo_pago' => 'tarjeta_bancaria',
        ])
        ->assertSessionHasErrors('id_acceso');
});

test('pedir Tarjeta Ciudadana sin número muestra un error claro', function () {
    $this->actingAs($this->usuario)
        ->post('/operador/pases', [
            'id_acceso' => $this->estacion->accesos()->value('id_acceso'),
            'id_linea' => $this->estacion->lineas()->value('lineas.id_linea'),
            'metodo_pago' => 'tarjeta_ciudadana',
        ])
        ->assertSessionHasErrors(['numero_tarjeta' => 'Escribe el número de la Tarjeta Ciudadana.']);
});

test('actualizar el aforo por encima del 150 % crea la alerta y queda en bitácora', function () {
    $this->estacion->update(['aforo_actual' => 0]);
    AlertaSistema::where('id_estacion', $this->estacion->id_estacion)->update(['estado' => 'atendida']);
    $nuevo = (int) ceil($this->estacion->aforo_maximo * 1.6);

    $this->actingAs($this->usuario)->post('/operador/aforo', ['aforo_actual' => $nuevo])
        ->assertSessionHas('estado', 'Aforo actualizado.');

    expect($this->estacion->refresh()->aforo_actual)->toBe($nuevo)
        ->and(AlertaSistema::where('id_estacion', $this->estacion->id_estacion)->where('estado', 'abierta')->count())->toBe(1)
        ->and(BitacoraAuditoria::where('id_registro_afectado', $this->estacion->id_estacion)->where('id_usuario', $this->usuario->id)->exists())->toBeTrue();
});

test('el aforo no acepta números negativos', function () {
    $this->actingAs($this->usuario)->post('/operador/aforo', ['aforo_actual' => -5])
        ->assertSessionHasErrors('aforo_actual');
});

test('el operador atiende una alerta de su estación', function () {
    $alerta = AlertaSistema::where('id_estacion', $this->estacion->id_estacion)->where('estado', 'abierta')->firstOrFail();

    $this->actingAs($this->usuario)->post("/operador/alertas/{$alerta->id_alerta}/atender")
        ->assertSessionHas('estado');

    expect($alerta->refresh()->estado)->toBe('atendida')
        ->and($alerta->fecha_cierre)->not->toBeNull();
});

test('el operador no puede atender alertas de otra estación', function () {
    $otra = Estacion::where('id_estacion', '!=', $this->estacion->id_estacion)->firstOrFail();
    $alerta = AlertaSistema::create([
        'id_estacion' => $otra->id_estacion, 'tipo_alerta' => 'refuerzo_bus',
        'descripcion' => 'Prueba', 'estado' => 'abierta',
    ]);

    $this->actingAs($this->usuario)->post("/operador/alertas/{$alerta->id_alerta}/atender")->assertForbidden();

    expect($alerta->refresh()->estado)->toBe('abierta');
});
