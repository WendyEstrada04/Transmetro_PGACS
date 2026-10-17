<?php

use App\Models\Acceso;
use App\Models\AlertaSistema;
use App\Models\BitacoraAuditoria;
use App\Models\Bus;
use App\Models\Estacion;
use App\Models\MantenimientoBus;
use App\Models\Molinete;
use App\Models\Operador;
use App\Models\TransaccionPago;
use App\Services\CobroPasaje;
use App\Services\ConteoPasajeros;
use Illuminate\Support\Facades\DB;

/*
 * Punto 16: "una PC de trabajo para cada estación de bus" con un operador que tiene el control de ella.
 * Cada operador está asignado a UNA estación: ve la gente que espera, los buses detenidos y en camino,
 * pide otro bus, atiende las alertas, controla los molinetes de los accesos y ve a los guardias.
 */

beforeEach(function () {
    $this->usuario = $this->operador();
    $this->estacion = $this->estacionDelOperador();   // San Sebastián
    $this->bus = $this->busDelOperador();              // un bus de la L1, que pasa por San Sebastián
});

/** Una estación distinta a la del operador. */
function otraEstacion(Estacion $suya): Estacion
{
    return Estacion::where('id_estacion', '!=', $suya->id_estacion)->orderBy('nombre')->firstOrFail();
}

// ---------- Asignación ----------

test('cada estación tiene un operador y el operador de prueba trabaja en San Sebastián', function () {
    expect($this->usuario->empleado->operador->estacion->nombre)->toBe('San Sebastián')
        ->and(Operador::whereNotNull('id_estacion_asignada')->count())->toBe(Estacion::count());
});

test('una estación no puede tener dos operadores', function () {
    $otro = Operador::where('id_empleado', '!=', $this->usuario->id_empleado)->firstOrFail();

    expect(errorDeBD(fn () => $otro->update(['id_estacion_asignada' => $this->estacion->id_estacion])))->toContain('uq_operadores_estacion');
});

// ---------- Pantalla ----------

test('el operador ve su estación, las personas que esperan, las líneas y las pestañas de trabajo', function () {
    $this->estacion->update(['aforo_actual' => 123]);

    $this->actingAs($this->usuario)->get('/operador')->assertOk()
        ->assertSee($this->estacion->nombre)
        ->assertSee('PC de la estación')
        ->assertSee($this->usuario->empleado->nombre_completo)
        ->assertSeeInOrder(['Esperando ahora', '123'])
        ->assertSee($this->estacion->lineas()->pluck('codigo_linea')->all())
        ->assertSeeInOrder(['Estación', 'Buses', 'Accesos y seguridad', 'Pasajeros']);
});

test('la pestaña de buses muestra los que están detenidos en la estación y los que vienen en camino', function () {
    $this->busEnEstacion($this->bus, $this->estacion, 7);

    $this->actingAs($this->usuario)->get('/operador')->assertOk()
        ->assertSeeInOrder(['En la estación ahora', $this->bus->placa, '7/'.$this->bus->capacidad_maxima.' a bordo', 'Vienen en camino']);
});

test('la pestaña de accesos muestra los molinetes y los guardias de la estación', function () {
    $guardia = $this->estacion->accesos()->with('guardias.empleado')->get()->flatMap->guardias->firstOrFail();

    $this->actingAs($this->usuario)->get('/operador')->assertOk()
        ->assertSee($guardia->empleado->nombre_completo)
        ->assertSee($this->estacion->accesos()->pluck('ubicacion_fisica')->all());
});

test('el administrador no puede ver la pantalla del operador', function () {
    $this->actingAs($this->administrador())->get('/operador')->assertForbidden();
});

test('un operador sin estación asignada no puede usar la pantalla', function () {
    $this->usuario->empleado->operador->update(['id_estacion_asignada' => null]);

    $this->actingAs($this->usuario)->get('/operador')->assertForbidden();
});

// ---------- Pedir otro bus y alertas (punto 14) ----------

test('el operador pide otro bus para su estación sin duplicar el pedido', function () {
    AlertaSistema::where('id_estacion', $this->estacion->id_estacion)->update(['estado' => 'atendida', 'fecha_cierre' => now()]);

    $this->actingAs($this->usuario)->post('/operador/refuerzo')
        ->assertRedirect(route('operador.inicio').'#estacion')->assertSessionHas('estado');
    expect(AlertaSistema::abiertas()->where('id_estacion', $this->estacion->id_estacion)->where('tipo_alerta', 'refuerzo_bus')->count())->toBe(1);

    $this->actingAs($this->usuario)->post('/operador/refuerzo');
    expect(AlertaSistema::abiertas()->where('id_estacion', $this->estacion->id_estacion)->count())->toBe(1);
});

test('el operador atiende las alertas de su estación, pero no las de otra', function () {
    $suya = AlertaSistema::create(['id_estacion' => $this->estacion->id_estacion, 'tipo_alerta' => 'otro', 'descripcion' => 'Prueba', 'estado' => 'abierta', 'fecha_creacion' => now()]);
    $ajena = AlertaSistema::create(['id_estacion' => otraEstacion($this->estacion)->id_estacion, 'tipo_alerta' => 'refuerzo_bus', 'descripcion' => 'Prueba', 'estado' => 'abierta', 'fecha_creacion' => now()]);

    $this->actingAs($this->usuario)->post(route('operador.alertas.atender', $suya))->assertSessionHas('estado');
    expect($suya->refresh()->estado)->toBe('atendida')->and($suya->fecha_cierre)->not->toBeNull();

    $this->actingAs($this->usuario)->post(route('operador.alertas.atender', $ajena))->assertForbidden();
    expect($ajena->refresh()->estado)->toBe('abierta');
    // Si ya la atendió la central mientras la pantalla seguía abierta, avisa sin mostrar un error.
    $this->actingAs($this->usuario)->post(route('operador.alertas.atender', $suya))
        ->assertRedirect(route('operador.inicio').'#estacion')->assertSessionHas('estado', 'Esa alerta ya fue atendida.');
});

// ---------- Molinetes de los accesos (punto 3) ----------

test('el operador cambia el estado del molinete de un acceso de su estación, pero no de otra', function () {
    $acceso = Acceso::where('id_estacion', $this->estacion->id_estacion)->firstOrFail();
    $ajeno = Acceso::where('id_estacion', otraEstacion($this->estacion)->id_estacion)->firstOrFail();

    $this->actingAs($this->usuario)->patch(route('operador.accesos.estado', $acceso), ['estado_operativo' => 'mantenimiento'])
        ->assertRedirect(route('operador.inicio').'#accesos')->assertSessionHas('estado');
    expect(Molinete::where('id_acceso', $acceso->id_acceso)->value('estado_operativo'))->toBe('mantenimiento')
        ->and(BitacoraAuditoria::where('tabla_afectada', 'accesos')->where('id_registro_afectado', $acceso->id_acceso)->exists())->toBeTrue();

    $this->actingAs($this->usuario)->patch(route('operador.accesos.estado', $ajeno), ['estado_operativo' => 'inactivo'])->assertForbidden();
    $this->actingAs($this->usuario)->patch(route('operador.accesos.estado', $acceso), ['estado_operativo' => 'roto'])->assertSessionHasErrors('estado_operativo');
});

// ---------- Contingencia: pase y bajadas manuales en un bus detenido en la estación (punto 18) ----------

test('si la tarjeta no se lee, el operador registra el pase a mano en el bus detenido en su estación', function () {
    $tarjeta = tarjetaDePrueba(['saldo' => 20]);
    $visita = $this->busEnEstacion($this->bus, $this->estacion, 10);

    $this->actingAs($this->usuario)->post('/operador/pases', [
        'id_bus' => $this->bus->id_bus, 'metodo_pago' => 'tarjeta_ciudadana',
        'numero_tarjeta' => $tarjeta->numero_tarjeta, 'motivo' => 'tarjeta_no_leida',
    ])->assertRedirect(route('operador.inicio').'#buses')->assertSessionHas('estado');

    $pase = TransaccionPago::where('id_tarjeta', $tarjeta->id_tarjeta)->sole();
    expect($pase->id_bus)->toBe($this->bus->id_bus)
        ->and($pase->id_estacion)->toBe($this->estacion->id_estacion)
        ->and($this->bus->refresh()->pasajeros_a_bordo)->toBe(11)
        ->and($visita->refresh()->subieron)->toBe(1)
        ->and((float) $tarjeta->refresh()->saldo)->toBe(20 - (float) $this->bus->linea->tarifa)
        ->and(BitacoraAuditoria::where('id_registro_afectado', $pase->id_transaccion)->value('datos_nuevos')['motivo'])->toBe('tarjeta_no_leida');
});

test('no se registra un pase en un bus que no está detenido en la estación del operador', function () {
    $this->busEnEstacion($this->bus, otraEstacion($this->estacion));

    $this->actingAs($this->usuario)->post('/operador/pases', [
        'id_bus' => $this->bus->id_bus, 'metodo_pago' => 'tarjeta_bancaria', 'motivo' => 'sistema_caido',
    ])->assertSessionHasErrors(['pase' => 'Ese bus no está detenido en tu estación.']);
});

test('con el sistema caído se registra un pase con tarjeta bancaria aunque el molinete no responda', function () {
    $this->busEnEstacion($this->bus, $this->estacion);
    $this->bus->molineteEntrada->update(['estado_operativo' => 'inactivo']);
    $antes = TransaccionPago::count();

    $this->actingAs($this->usuario)->post('/operador/pases', [
        'id_bus' => $this->bus->id_bus, 'metodo_pago' => 'tarjeta_bancaria', 'motivo' => 'sistema_caido',
    ])->assertSessionHasNoErrors();

    expect(TransaccionPago::count())->toBe($antes + 1);
});

test('el pase manual pide el bus, el motivo y el número de tarjeta', function () {
    $this->actingAs($this->usuario)->post('/operador/pases', ['metodo_pago' => 'tarjeta_ciudadana'])
        ->assertSessionHasErrors(['id_bus', 'motivo', 'numero_tarjeta' => 'Escribe el número impreso en la Tarjeta Ciudadana.']);
});

test('con el bus lleno el pase manual no entra y la estación ve la alerta de bus lleno', function () {
    AlertaSistema::query()->update(['estado' => 'cerrada', 'fecha_cierre' => now()]);
    $this->busEnEstacion($this->bus, $this->estacion, $this->bus->capacidad_maxima);

    $this->actingAs($this->usuario)->post('/operador/pases', ['id_bus' => $this->bus->id_bus, 'metodo_pago' => 'tarjeta_bancaria', 'motivo' => 'sistema_caido'])
        ->assertSessionHasErrors(['pase']);

    expect(AlertaSistema::abiertas()->where('tipo_alerta', 'bus_lleno')->where('id_estacion', $this->estacion->id_estacion)->exists())->toBeTrue();
    $this->actingAs($this->usuario)->get('/operador')->assertOk()
        ->assertSee('Bus lleno')->assertSee('Enviar otro bus')->assertSee('lleno');
});

test('si el contador de salida falla, el operador registra las bajadas a mano', function () {
    $visita = $this->busEnEstacion($this->bus, $this->estacion, 12);

    $this->actingAs($this->usuario)->post('/operador/bajadas', ['id_bus_bajada' => $this->bus->id_bus, 'cantidad' => 5, 'motivo_bajada' => 'contador_fallo'])
        ->assertRedirect(route('operador.inicio').'#buses')->assertSessionHasNoErrors();

    expect($this->bus->refresh()->pasajeros_a_bordo)->toBe(7)
        ->and($visita->refresh()->bajaron)->toBe(5)
        ->and(BitacoraAuditoria::where('tabla_afectada', 'bajadas_bus')->latest('fecha_registro')->value('datos_nuevos')['motivo'])->toBe('contador_fallo');

    $this->actingAs($this->usuario)->post('/operador/bajadas', ['id_bus_bajada' => $this->bus->id_bus, 'cantidad' => 8, 'motivo_bajada' => 'contador_fallo'])
        ->assertSessionHasErrors(['cantidad']);
});

test('la pestaña de pasajeros muestra los cobros hechos en la estación', function () {
    $this->busEnEstacion($this->bus, $this->estacion, 4);
    app(CobroPasaje::class)->cobrar($this->bus->molineteEntrada, 'tarjeta_bancaria');

    $this->actingAs($this->usuario)->get('/operador')->assertOk()
        ->assertSee('Últimos cobros en la estación')
        ->assertSeeInOrder(['Últimos cobros en la estación', $this->bus->placa, 'Tarjeta bancaria']);
});

// ---------- Fallas de los buses (las atiende el taller desde la administración) ----------

test('el operador reporta la falla de un bus que pasa por su estación', function () {
    $this->actingAs($this->usuario)->post('/operador/fallas', [
        'id_bus_falla' => $this->bus->id_bus, 'categoria' => 'frenos', 'prioridad' => 'media', 'descripcion' => 'Ruido al frenar',
    ])->assertRedirect(route('operador.inicio').'#buses')->assertSessionHas('estado');

    $reporte = MantenimientoBus::where('id_bus', $this->bus->id_bus)->where('descripcion', 'like', 'Ruido al frenar%')->sole();
    expect($reporte->tipo)->toBe('falla')
        ->and($reporte->estado)->toBe('reportado')
        ->and($reporte->id_empleado_reporta)->toBe($this->usuario->id_empleado)
        ->and($reporte->descripcion)->toContain('San Sebastián')
        ->and($this->bus->refresh()->estado_logico)->not->toBe('en_mantenimiento');

    $this->actingAs($this->usuario)->get('/operador')->assertSeeInOrder(['Fallas que reportaste', $this->bus->placa, 'Reportado']);
});

test('una falla de prioridad alta saca el bus de servicio', function () {
    $this->actingAs($this->usuario)->post('/operador/fallas', [
        'id_bus_falla' => $this->bus->id_bus, 'categoria' => 'motor', 'prioridad' => 'alta', 'descripcion' => 'El motor se apaga solo',
    ])->assertSessionHas('estado', fn ($t) => str_contains($t, 'fuera de servicio'));

    expect($this->bus->refresh()->estado_logico)->toBe('en_mantenimiento');
});

test('solo se reportan fallas de buses de las líneas que pasan por la estación', function () {
    $lineas = $this->estacion->lineas()->pluck('lineas.id_linea');
    $ajeno = Bus::whereNotIn('id_linea_asignada', $lineas)->whereNotNull('id_linea_asignada')->firstOrFail();

    $this->actingAs($this->usuario)->post('/operador/fallas', [
        'id_bus_falla' => $ajeno->id_bus, 'categoria' => 'motor', 'prioridad' => 'media', 'descripcion' => 'Prueba de falla',
    ])->assertSessionHasErrors('id_bus_falla');

    $this->actingAs($this->usuario)->post('/operador/fallas', ['categoria' => 'alas', 'prioridad' => 'urgente', 'descripcion' => ''])
        ->assertSessionHasErrors(['id_bus_falla', 'categoria', 'prioridad', 'descripcion']);
});

// ---------- Taller: el administrador lleva el mantenimiento en la ficha del bus ----------

test('el administrador pasa una falla al taller y al resolverla el bus vuelve a estar disponible', function () {
    $admin = $this->administrador();
    $this->actingAs($this->usuario)->post('/operador/fallas', [
        'id_bus_falla' => $this->bus->id_bus, 'categoria' => 'motor', 'prioridad' => 'alta', 'descripcion' => 'Pierde potencia',
    ]);
    $reporte = MantenimientoBus::where('id_bus', $this->bus->id_bus)->where('descripcion', 'like', 'Pierde potencia%')->sole();

    $this->actingAs($admin)->get(route('admin.buses.edit', $this->bus))->assertOk()
        ->assertSee('Mantenimiento')->assertSee('Pierde potencia')->assertSee($this->usuario->empleado->nombre_completo);

    $this->actingAs($admin)->patch(route('admin.mantenimientos.update', $reporte), ['estado' => 'en_proceso'])
        ->assertRedirect(route('admin.buses.edit', $this->bus).'#mantenimiento');
    expect($reporte->refresh()->estado)->toBe('en_proceso')->and($this->bus->refresh()->estado_logico)->toBe('en_mantenimiento');

    $this->actingAs($admin)->patch(route('admin.mantenimientos.update', $reporte), ['estado' => 'resuelto'])
        ->assertSessionHas('estado', fn ($t) => str_contains($t, 'disponible'));
    expect($reporte->refresh()->estado)->toBe('resuelto')
        ->and($reporte->fecha_cierre)->not->toBeNull()
        ->and($this->bus->refresh()->estado_logico)->toBe('en_espera');

    $this->actingAs($admin)->patch(route('admin.mantenimientos.update', $reporte), ['estado' => 'resuelto'])
        ->assertSessionHas('estado', 'Ese reporte ya está resuelto.');
});

test('el administrador registra un mantenimiento ya realizado y el operador no entra al taller', function () {
    $this->actingAs($this->administrador())->post(route('admin.buses.mantenimientos.store', $this->bus), [
        'tipo' => 'preventivo', 'categoria' => 'motor', 'prioridad' => 'media', 'descripcion' => 'Cambio de aceite', 'kilometraje' => 120500,
    ])->assertSessionHasNoErrors();

    $registro = MantenimientoBus::where('descripcion', 'Cambio de aceite')->sole();
    expect($registro->estado)->toBe('resuelto')->and($registro->fecha_cierre)->not->toBeNull();

    $this->actingAs($this->usuario)->post(route('admin.buses.mantenimientos.store', $this->bus), [
        'tipo' => 'preventivo', 'categoria' => 'motor', 'prioridad' => 'media', 'descripcion' => 'No debería',
    ])->assertForbidden();
    expect(DB::table('mantenimientos_bus')->where('descripcion', 'No debería')->exists())->toBeFalse();
});

test('si la central ya envió el bus por una alerta de bus lleno, el botón avisa sin error', function () {
    $alerta = AlertaSistema::create(app(ConteoPasajeros::class)->filaAlerta($this->bus, $this->estacion->id_estacion, $this->estacion->nombre, 20, now()));
    $alerta->update(['estado' => 'atendida', 'fecha_cierre' => now()]);   // la atendió la central

    $this->actingAs($this->usuario)->post(route('operador.alertas.atender', $alerta))
        ->assertRedirect(route('operador.inicio').'#estacion')
        ->assertSessionHas('estado', 'Esa alerta ya fue atendida: la central ya envió otro bus.');
});
