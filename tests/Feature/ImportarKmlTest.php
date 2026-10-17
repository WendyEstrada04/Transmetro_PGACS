<?php

use App\Services\ImportadorKml;

/*
 * Lectura de los mapas oficiales (KML de Google My Maps) con los que se cargaron los recorridos de las líneas.
 */

test('los nombres del mapa oficial se reconocen aunque cambien un poco', function (string $mapa, string $sistema) {
    expect(ImportadorKml::nombreNormalizado($mapa))->toBe(ImportadorKml::nombreNormalizado($sistema));
})->with([
    ['San Sebastián - Líneas 1 y 2', 'San Sebastián'],
    ['Francos Monroy  - Líneas 6 y 18', 'Francos y Monroy'],
    ['Plaza Barrios/FEGUA - Líneas 6 y 18', 'Plaza Barrios/FEGUA'],
    ['Colón - Líneas 6 y 18', 'Colón'],
    ['IGSS Zona 6', 'IGSS Zona 6'],
]);

test('los tramos dibujados al revés se voltean para que el recorrido sea continuo', function () {
    $recorrido = app(ImportadorKml::class)->encadenar([
        [[3.0, 0.0], [2.0, 0.0]],   // tramo del medio, dibujado al revés
        [[3.0, 0.0], [4.0, 0.0]],   // tramo final
        [[0.0, 0.0], [1.0, 0.0], [2.0, 0.0]], // tramo inicial
    ], [0.0, 0.0]);

    expect($recorrido)->toBe([[0.0, 0.0], [1.0, 0.0], [2.0, 0.0], [3.0, 0.0], [4.0, 0.0]]);
});

test('reconoce rutas dibujadas como figura cerrada (así viene la Línea 6 oficial)', function () {
    $kml = '<?xml version="1.0" encoding="UTF-8"?><kml xmlns="http://www.opengis.net/kml/2.2"><Document>'
        .'<Placemark><name>Línea 6</name><Polygon><outerBoundaryIs><LinearRing><tessellate>1</tessellate><coordinates>'
        .'-90.5116,14.6305,0 -90.5097,14.6303,0 -90.4875,14.6734,0 -90.5100,14.6310,0 -90.5116,14.6305,0'
        .'</coordinates></LinearRing></outerBoundaryIs></Polygon></Placemark></Document></kml>';

    $importador = app(ImportadorKml::class);
    $mapa = $importador->leer($kml);
    // Empieza en el punto más cercano a la estación 1 (Proyectos) y da la vuelta completa.
    $recorrido = $importador->encadenar($mapa['tramos'], [14.6733, -90.4874]);

    expect($recorrido)->toBe([
        [14.6734, -90.4875], [14.631, -90.51], [14.6305, -90.5116], [14.6303, -90.5097], [14.6734, -90.4875],
    ]);
});
