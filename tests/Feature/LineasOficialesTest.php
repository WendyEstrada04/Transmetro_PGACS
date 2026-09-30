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

test('la Línea 6 tiene sus 16 estaciones en el orden oficial y su horario propio', function () {
    $linea = Linea::with('horarios')->where('codigo_linea', 'L6')->firstOrFail();

    expect($linea->nombre)->toBe('Proyectos – FEGUA')
        ->and($linea->estaciones()->orderBy('orden_parada')->pluck('nombre')->all())->toBe([
            'Proyectos', 'Proyectos 4-4', 'Cipresales', 'Quintanal', 'Corpus Christi', 'José Martí', 'Cerro del Carmen',
            'Santa Teresa', 'Capuchinas', 'Plaza Barrios/FEGUA', 'Francos y Monroy', 'Colón', 'Parroquia',
            'IGSS Zona 6', 'Centro Zona 6', 'Academia',
        ])
        ->and($linea->horarios->firstWhere('tipo_dia', 'lunes_viernes')->fin())->toBe('21:00')
        ->and($linea->horarios->firstWhere('tipo_dia', 'domingo_feriado')->inicio())->toBe('06:00');
});

test('sobre el amarillo de la Línea 6 la letra es oscura y sobre el morado es blanca', function () {
    expect(Linea::where('codigo_linea', 'L6')->firstOrFail()->colorTexto())->toBe('#111827')
        ->and(Linea::where('codigo_linea', 'L1')->firstOrFail()->colorTexto())->toBe('#ffffff');
});
