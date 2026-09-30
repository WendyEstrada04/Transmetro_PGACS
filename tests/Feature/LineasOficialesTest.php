<?php

use App\Models\Estacion;
use App\Models\Linea;

/*
 * Datos oficiales de las líneas (muniguate.com/movilidadurbana).
 */

test('la Línea 2 tiene sus 5 estaciones en el orden oficial', function () {
    $linea = Linea::where('codigo_linea', 'L2')->firstOrFail();

    expect($linea->nombre)->toBe('Hipódromo del Norte – San Sebastián')
        ->and($linea->ruta_circular)->toBeTrue()
        ->and($linea->estaciones()->orderBy('orden_parada')->pluck('nombre')->all())->toBe([
            'San Sebastián', 'Simeón Cañas', 'Hipódromo del Norte', 'San José de la Montaña', 'Jocotenango',
        ]);
});

test('San Sebastián es estación de transbordo entre la Línea 1 y la Línea 2', function () {
    $estacion = Estacion::where('nombre', 'San Sebastián')->firstOrFail();

    expect($estacion->lineas()->pluck('codigo_linea')->sort()->values()->all())->toBe(['L1', 'L2']);
});

test('cada línea tiene entre n y 2n buses (n = número de estaciones)', function () {
    foreach (Linea::withCount(['estaciones', 'buses'])->get() as $linea) {
        expect($linea->buses_count)
            ->toBeGreaterThanOrEqual($linea->estaciones_count)
            ->toBeLessThanOrEqual(2 * $linea->estaciones_count);
    }
});

test('el mapa muestra la Línea 2 con su color oficial', function () {
    $this->actingAs($this->administrador())->get('/mapa')
        ->assertOk()
        ->assertSee('Hipódromo del Norte')
        ->assertSee('#9F85BC');
});
