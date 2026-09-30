<?php

use App\Models\Bus;
use App\Models\Estacion;
use App\Models\Linea;
use App\Models\Tarjeta;

/*
 * Todas las pantallas cargan sin errores con los datos de prueba (prueba de humo).
 */

test('las pantallas del administrador cargan', function (string $ruta) {
    $this->actingAs($this->administrador())->get($ruta)->assertOk();
})->with([
    '/admin',
    '/admin/municipios',
    '/admin/municipios/create',
    '/admin/estaciones',
    '/admin/estaciones/create',
    '/admin/lineas',
    '/admin/lineas/create',
    '/admin/buses',
    '/admin/buses/create',
    '/admin/personal',
    '/admin/personal/create',
    '/admin/tarjetas',
    '/admin/tarjetas/create',
    '/mapa',
]);

test('las pantallas de detalle del administrador cargan', function () {
    $admin = $this->administrador();
    $linea = Linea::where('codigo_linea', 'L1')->firstOrFail();
    $estacion = Estacion::where('nombre', 'Plaza Barrios')->firstOrFail();

    $this->actingAs($admin)->get(route('admin.lineas.show', $linea))->assertOk()->assertSee('L1');
    $this->actingAs($admin)->get(route('admin.lineas.edit', $linea))->assertOk();
    $this->actingAs($admin)->get(route('admin.estaciones.show', $estacion))->assertOk()->assertSee('Plaza Barrios');
    $this->actingAs($admin)->get(route('admin.estaciones.edit', $estacion))->assertOk();
    $this->actingAs($admin)->get(route('admin.buses.edit', Bus::firstOrFail()))->assertOk();
    $this->actingAs($admin)->get(route('admin.tarjetas.show', Tarjeta::firstOrFail()))->assertOk();
});

test('el panel del operador muestra su estación', function () {
    $this->actingAs($this->operador())->get('/operador')
        ->assertOk()
        ->assertSee('Plaza Barrios');
});

test('el mapa del operador carga', function () {
    $this->actingAs($this->operador())->get('/mapa')->assertOk();
});
