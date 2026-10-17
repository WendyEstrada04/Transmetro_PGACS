<?php

use App\Models\BitacoraAuditoria;
use App\Models\Recarga;
use App\Models\Tarjeta;

/*
 * Tarjeta Ciudadana (Ciudad Integrada) — Vecino, Adulto mayor y Persona con discapacidad.
 * El administrador del Transmetro consulta las tarjetas y controla su estado (bloquear, desbloquear, dar de baja);
 * la emisión, la afiliación, la reposición y las recargas se hacen en POCAE y en los puntos de recarga.
 */

test('el administrador no emite ni recarga tarjetas desde el sistema', function () {
    $tarjeta = tarjetaDePrueba(['saldo' => 3]);
    $admin = $this->administrador();

    $this->actingAs($admin)->get('/admin/tarjetas/create')->assertNotFound();
    $this->actingAs($admin)->post('/admin/tarjetas', ['numero_tarjeta' => '9900400001', 'tipo_tarjeta' => 'vecino'])->assertStatus(405);
    $this->actingAs($admin)->post("/admin/tarjetas/{$tarjeta->id_tarjeta}/recargas", ['monto' => 50, 'metodo_pago' => 'debito'])->assertNotFound();

    $this->actingAs($admin)->get(route('admin.tarjetas.index'))->assertOk()->assertDontSee('Emitir tarjeta');
    $this->actingAs($admin)->get(route('admin.tarjetas.show', $tarjeta))->assertOk()
        ->assertDontSee('name="monto"', false)->assertDontSee('Reponer tarjeta')->assertDontSee('Afiliar tarjeta')
        ->assertSee('Control de la tarjeta')->assertSee('Bloquear tarjeta')->assertSee('Dar de baja');

    expect((float) $tarjeta->refresh()->saldo)->toBe(3.0)
        ->and(Tarjeta::where('numero_tarjeta', '9900400001')->exists())->toBeFalse();
});

test('bloquear una tarjeta pide el motivo, lo guarda con la fecha y queda en la bitácora', function () {
    $tarjeta = tarjetaDePrueba(['saldo' => 20]);
    $admin = $this->administrador();

    $this->actingAs($admin)->patch(route('admin.tarjetas.bloquear', $tarjeta), [])->assertSessionHasErrors('motivo');
    $this->actingAs($admin)->patch(route('admin.tarjetas.bloquear', $tarjeta), ['motivo' => 'robo_extravio', 'observacion' => 'Denuncia por teléfono'])
        ->assertSessionHasNoErrors()->assertSessionHas('estado');

    $tarjeta->refresh();
    expect($tarjeta->estado)->toBe('bloqueada')
        ->and($tarjeta->motivo_estado)->toBe('Robo o extravío reportado — Denuncia por teléfono')
        ->and($tarjeta->fecha_estado)->not->toBeNull()
        ->and((float) $tarjeta->saldo)->toBe(20.0)
        ->and(BitacoraAuditoria::where('id_registro_afectado', $tarjeta->id_tarjeta)->where('tabla_afectada', 'tarjetas')->exists())->toBeTrue();

    $this->actingAs($admin)->get(route('admin.tarjetas.show', $tarjeta))->assertOk()
        ->assertSee('Desbloquear tarjeta')->assertSee('Robo o extravío reportado');
});

test('una tarjeta bloqueada no paga ni se recarga hasta que se desbloquea', function () {
    $tarjeta = tarjetaDePrueba(['saldo' => 20]);
    $admin = $this->administrador();
    $this->actingAs($admin)->patch(route('admin.tarjetas.bloquear', $tarjeta), ['motivo' => 'uso_indebido']);

    expect(errorDeBD(fn () => Recarga::create(['id_tarjeta' => $tarjeta->id_tarjeta, 'monto' => 10, 'metodo_pago' => 'efectivo'])))
        ->toContain('no está activa');

    $this->actingAs($admin)->patch(route('admin.tarjetas.desbloquear', $tarjeta))->assertSessionHasNoErrors();
    expect($tarjeta->refresh()->estado)->toBe('activa');
    Recarga::create(['id_tarjeta' => $tarjeta->id_tarjeta, 'monto' => 10, 'metodo_pago' => 'efectivo']);
    expect(Recarga::where('id_tarjeta', $tarjeta->id_tarjeta)->count())->toBe(1);

    // Solo se desbloquean las bloqueadas.
    $this->actingAs($admin)->patch(route('admin.tarjetas.desbloquear', $tarjeta))->assertSessionHasErrors('bd');
});

test('dar de baja una tarjeta es definitivo', function () {
    $tarjeta = tarjetaDePrueba(['saldo' => 15]);
    $admin = $this->administrador();

    $this->actingAs($admin)->patch(route('admin.tarjetas.baja', $tarjeta), ['motivo_baja' => 'otro'])->assertSessionHasErrors('motivo_baja');
    $this->actingAs($admin)->patch(route('admin.tarjetas.baja', $tarjeta), ['motivo_baja' => 'fraude'])->assertSessionHasNoErrors();

    expect($tarjeta->refresh()->estado)->toBe('baja')
        ->and($tarjeta->motivo_estado)->toBe('Fraude comprobado');

    $this->actingAs($admin)->patch(route('admin.tarjetas.desbloquear', $tarjeta))->assertSessionHasErrors('bd');
    $this->actingAs($admin)->patch(route('admin.tarjetas.bloquear', $tarjeta), ['motivo' => 'robo_extravio'])->assertSessionHasErrors('bd');
    $this->actingAs($admin)->patch(route('admin.tarjetas.baja', $tarjeta), ['motivo_baja' => 'fraude'])->assertSessionHasErrors('bd');
    $this->actingAs($admin)->get(route('admin.tarjetas.show', $tarjeta))->assertOk()
        ->assertSee('Estado definitivo')->assertDontSee('Bloquear tarjeta');

    // La base de datos tampoco deja reactivarla.
    expect(errorDeBD(fn () => $tarjeta->update(['estado' => 'activa'])))->toContain('dada de baja');
});

test('la lista filtra por estado y cuenta cuántas tarjetas hay en cada uno', function () {
    $bloqueadas = Tarjeta::where('estado', 'bloqueada')->orderBy('numero_tarjeta')->get();
    $activa = Tarjeta::where('estado', 'activa')->orderBy('numero_tarjeta')->firstOrFail();
    expect($bloqueadas)->not->toBeEmpty()->and(Tarjeta::where('estado', 'baja')->exists())->toBeTrue();

    $respuesta = $this->actingAs($this->administrador())->get(route('admin.tarjetas.index', ['estado' => 'bloqueada']))->assertOk()
        ->assertSee('Bloqueadas')->assertSee('Dadas de baja')->assertDontSee($activa->numeroFormateado());
    $bloqueadas->each(fn ($t) => $respuesta->assertSee($t->numeroFormateado()));
});

test('la ficha muestra la tarjeta por los dos lados con su número impreso y el código QR', function () {
    $tarjeta = Tarjeta::where('tipo_tarjeta', 'adulto_mayor')->firstOrFail();

    $this->actingAs($this->administrador())->get(route('admin.tarjetas.show', $tarjeta))->assertOk()
        ->assertSee('ADULTO MAYOR')
        ->assertSee('Nº '.$tarjeta->numeroFormateado())
        ->assertSee('data-qr="'.$tarjeta->numero_tarjeta.'"', false)
        ->assertSee('js/qrcode.js')
        ->assertSee('Viajes de cortesía que le quedan hoy');
});

test('la base de datos exige titular a las tarjetas personalizadas y el formato del número', function () {
    expect(errorDeBD(fn () => Tarjeta::create(['numero_tarjeta' => '9922223333', 'tipo_tarjeta' => 'adulto_mayor', 'afiliada' => true])))
        ->toContain('chk_tarjetas_titular')
        ->and(errorDeBD(fn () => Tarjeta::create(['numero_tarjeta' => 'TC123', 'tipo_tarjeta' => 'vecino'])))
        ->toContain('chk_tarjetas_numero');
});

test('la búsqueda encuentra la tarjeta escribiendo el número como va impreso', function () {
    $tarjeta = Tarjeta::orderBy('numero_tarjeta')->firstOrFail();

    $this->actingAs($this->administrador())->get(route('admin.tarjetas.index', ['buscar' => $tarjeta->numeroFormateado()]))
        ->assertOk()->assertSee($tarjeta->numeroFormateado());
});

test('el DPI del titular tiene exactamente 13 dígitos', function () {
    expect(errorDeBD(fn () => tarjetaDePrueba(['tipo_tarjeta' => 'discapacidad', 'afiliada' => true, 'nombre_titular' => 'X', 'dpi_titular' => '12345'])))
        ->toContain('chk_tarjetas_dpi');
});
