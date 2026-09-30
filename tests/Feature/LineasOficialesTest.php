<?php

use App\Models\Estacion;
use App\Models\Linea;
use Illuminate\Support\Facades\DB;

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
            // Orden según el recorrido del mapa oficial: sale de Proyectos, baja por la 15 avenida z6 y la 10a. avenida z1
            // hasta FEGUA, sube por la 11 avenida y regresa por la 16 avenida z6 y La Pedrera (Cipresales y Proyectos 4-4).
            'Proyectos', 'Quintanal', 'Corpus Christi', 'José Martí', 'Cerro del Carmen', 'Santa Teresa', 'Capuchinas',
            'Plaza Barrios/FEGUA', 'Francos Monroy', 'Colón', 'Parroquia', 'IGSS Zona 6', 'Centro Zona 6', 'Academia',
            'Cipresales', 'Proyectos 4-4',
        ])
        ->and($linea->horarios->firstWhere('tipo_dia', 'lunes_viernes')->fin())->toBe('21:00')
        ->and($linea->horarios->firstWhere('tipo_dia', 'domingo_feriado')->inicio())->toBe('06:00');
});

test('sobre el amarillo de la Línea 6 la letra es oscura y sobre el morado es blanca', function () {
    expect(Linea::where('codigo_linea', 'L6')->firstOrFail()->colorTexto())->toBe('#111827')
        ->and(Linea::where('codigo_linea', 'L1')->firstOrFail()->colorTexto())->toBe('#ffffff');
});

test('se cargan las 8 rutas oficiales con su recorrido del mapa de la Municipalidad', function () {
    $lineas = Linea::withCount('estaciones')->orderBy('codigo_linea')->get()->keyBy('codigo_linea');

    expect($lineas->keys()->sort()->values()->all())->toBe(['L1', 'L12', 'L13', 'L18', 'L2', 'L6', 'L7', 'R5'])
        ->and($lineas->every(fn ($l) => $l->tieneTrazo()))->toBeTrue()
        ->and($lineas['L7']->estaciones_count)->toBe(29)
        ->and($lineas['L12']->estaciones_count)->toBe(18)
        ->and($lineas['L13']->estaciones_count)->toBe(22)
        ->and($lineas['L18']->estaciones_count)->toBe(13)
        ->and($lineas['R5']->estaciones_count)->toBe(14);
});

test('las estaciones de transbordo de las líneas 6 y 18 son las mismas', function () {
    foreach (['Plaza Barrios/FEGUA', 'Francos Monroy', 'Colón', 'Cerro del Carmen', 'Capuchinas'] as $nombre) {
        expect(Estacion::where('nombre', $nombre)->firstOrFail()->lineas()->pluck('codigo_linea')->sort()->values()->all())
            ->toBe(['L18', 'L6']);
    }
});

test('las estaciones de Villa Nueva quedan en su municipio', function () {
    foreach (['Centra Sur', 'Monte María', 'Javier'] as $nombre) {
        expect(Estacion::with('municipio')->where('nombre', $nombre)->firstOrFail()->municipio->nombre)->toBe('Villa Nueva');
    }
});

test('la distancia total de cada línea es la suma de sus tramos', function () {
    foreach (Linea::all() as $linea) {
        $suma = (float) DB::table('linea_estacion')->where('id_linea', $linea->id_linea)->sum('distancia_previa_km');
        expect((float) $linea->distancia_total_km)->toEqualWithDelta($suma, 0.01)->toBeGreaterThan(1);
    }
});

test('cada línea tiene el horario oficial de la tabla de la Municipalidad', function (string $codigo, string $tipo, string $inicio, string $fin) {
    $horario = Linea::where('codigo_linea', $codigo)->firstOrFail()->horarios()->where('tipo_dia', $tipo)->firstOrFail();

    expect([$horario->inicio(), $horario->fin()])->toBe([$inicio, $fin]);
})->with([
    ['L1', 'lunes_viernes', '05:00', '20:00'],
    ['L2', 'lunes_viernes', '05:30', '20:00'],
    ['L7', 'domingo_feriado', '06:00', '20:00'],
    ['L12', 'domingo_feriado', '04:30', '21:00'],
    ['L13', 'sabado', '05:00', '20:00'],
    ['L18', 'lunes_viernes', '05:00', '21:00'],
    ['R5', 'domingo_feriado', '06:00', '18:00'],
]);

test('la Ruta 5 sigue el orden oficial: sube por el Bulevar La Asunción y regresa por la zona 5', function () {
    $nombres = Linea::where('codigo_linea', 'R5')->firstOrFail()->estaciones()->pluck('nombre')->all();

    expect($nombres[0])->toBe('Parque Colón')
        ->and($nombres[7])->toBe('Puente de la Penitenciaría')
        ->and(end($nombres))->toBe('Matamoros')
        ->and(array_search('Cipreses (dir. Puente de la Penitenciaría)', $nombres))->toBeLessThan(array_search('Puente de la Penitenciaría', $nombres))
        ->and(array_search('Cipreses (dir. Parque Colón)', $nombres))->toBeGreaterThan(array_search('Puente de la Penitenciaría', $nombres));
});
