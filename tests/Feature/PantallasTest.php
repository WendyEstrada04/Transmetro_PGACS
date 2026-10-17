<?php

use App\Models\Bus;
use App\Models\Estacion;
use App\Models\Linea;
use App\Models\Tarjeta;
use App\Models\Viaje;
use App\Services\SimuladorOperacion;

/*
 * Todas las pantallas cargan sin errores con los datos de prueba (prueba de humo).
 */

test('las pantallas del administrador cargan', function (string $ruta) {
    $this->actingAs($this->administrador())->get($ruta)->assertOk();
})->with([
    '/admin',
    '/admin/municipios',
    '/admin/estaciones',
    '/admin/lineas',
    '/admin/buses',
    '/admin/buses/create',
    '/admin/personal',
    '/admin/personal/create',
    '/admin/tarjetas',
    '/admin/tarjetas?estado=bloqueada',
    '/mapa',
]);

test('las pantallas de detalle del administrador cargan', function () {
    $admin = $this->administrador();
    $linea = Linea::where('codigo_linea', 'L1')->firstOrFail();
    $estacion = Estacion::where('nombre', 'San Sebastián')->firstOrFail();

    $this->actingAs($admin)->get(route('admin.lineas.show', $linea))->assertOk()->assertSee('L1');
    $this->actingAs($admin)->get(route('admin.estaciones.show', $estacion))->assertOk()->assertSee('San Sebastián');
    $this->actingAs($admin)->get(route('admin.buses.edit', Bus::firstOrFail()))->assertOk();
    $this->actingAs($admin)->get(route('admin.tarjetas.show', Tarjeta::firstOrFail()))->assertOk();
});

test('el panel del operador muestra su estación', function () {
    $this->actingAs($this->operador())->get('/operador')
        ->assertOk()
        ->assertSee('PC de la estación')
        ->assertSee('San Sebastián');
});

test('el mapa del operador abre con las líneas de su estación, la resalta y no tiene el botón de la simulación', function () {
    $this->actingAs($this->operador())->get('/mapa')->assertOk()
        ->assertSee('Tu estación: <b class="text-noche">San Sebastián</b>', false)
        ->assertSee('"L1","L2"', false)
        ->assertDontSee('id="boton-simulacion"', false);

    $this->actingAs($this->administrador())->get('/mapa')->assertOk()
        ->assertSee('id="boton-simulacion"', false)
        ->assertDontSee('Tu estación: <b', false);
});

test('el monitoreo en vivo da la posición de cada bus en ruta', function () {
    $respuesta = $this->actingAs($this->operador())->getJson(route('mapa.buses'))->assertOk()
        ->assertJsonStructure(['ahora', 'buses' => [['id', 'placa', 'linea', 'color', 'desde', 'hacia', 'salida', 'duracion', 'ocupacion', 'propio']]]);

    $buses = collect($respuesta->json('buses'));
    expect($buses)->not->toBeEmpty()
        ->and($buses->where('propio', true))->toBeEmpty()   // el operador trabaja por estación: no tiene un bus propio
        ->and($buses->every(fn ($b) => $b['duracion'] >= 1000))->toBeTrue();
});

test('el bus va de su última estación a la siguiente de su ruta', function () {
    $bus = $this->busDelOperador();
    $recorrido = $bus->linea->estaciones()->get()->values();
    Viaje::where('id_bus', $bus->id_bus)->delete();
    $viaje = Viaje::create(['id_bus' => $bus->id_bus, 'id_linea' => $bus->id_linea_asignada, 'fecha_hora_inicio' => now(), 'estado' => 'en_curso']);
    $viaje->visitas()->create(['id_estacion' => $recorrido[0]->id_estacion, 'orden_visita' => 1, 'hora_llegada' => now()->subMinutes(3), 'hora_salida' => now()->subMinutes(2), 'ocupacion_al_llegar_pct' => 40]);
    $ultima = $viaje->visitas()->create(['id_estacion' => $recorrido[1]->id_estacion, 'orden_visita' => 2, 'hora_llegada' => now()->subMinute(), 'hora_salida' => now(), 'ocupacion_al_llegar_pct' => 40]);

    $tm001 = collect($this->actingAs($this->administrador())->getJson(route('mapa.buses'))->json('buses'))->firstWhere('placa', $this->busDelOperador()->placa);
    expect($tm001['desde'])->toBe($recorrido[1]->id_estacion)
        ->and($tm001['hacia'])->toBe($recorrido[2]->id_estacion)
        // El mismo tiempo de tramo (con tráfico) que usa la simulación para decidir cuándo llega.
        ->and($tm001['duracion'])->toBe(1000 * SimuladorOperacion::segundosTramo((float) $recorrido[2]->pivot->distancia_previa_km,
            $ultima->refresh()->hora_salida, SimuladorOperacion::claveTramo($viaje->id_viaje, 2)));
});

test('la pantalla de monitoreo carga para el administrador y el operador', function () {
    $this->actingAs($this->operador())->get('/mapa')->assertOk()->assertSee('Monitoreo en vivo')->assertSee('id="mapa-vivo"', false);
    $this->actingAs($this->administrador())->get('/mapa')->assertOk()->assertSee('Monitoreo en vivo');
});
