<?php

use App\Models\Linea;
use App\Services\ImportadorKml;
use Illuminate\Http\UploadedFile;

/*
 * Importar el recorrido y las estaciones del mapa oficial (KML/KMZ de Google My Maps).
 */

/** KML parecido al que exporta Google My Maps para la Línea 2 (los tramos vienen en otro orden que el del recorrido). */
function kmlLinea2(): string
{
    $punto = fn (string $nombre, float $lat, float $lon) => "<Placemark><name>{$nombre}</name><Point><coordinates>{$lon},{$lat},0</coordinates></Point></Placemark>";

    return '<?xml version="1.0" encoding="UTF-8"?><kml xmlns="http://www.opengis.net/kml/2.2"><Document><name>Transmetro - Línea 2</name>'
        .'<Folder><name>Recorrido</name>'
        // Tramo de regreso (bajando), escrito primero.
        .'<Placemark><name>Regreso</name><LineString><coordinates>-90.5098,14.6630,0 -90.5120,14.6562,0 -90.5133,14.6523,0 -90.5136,14.6459,0</coordinates></LineString></Placemark>'
        // Tramo de ida (subiendo), escrito después.
        .'<Placemark><name>Ida</name><LineString><coordinates>-90.5136,14.6459,0 -90.5127,14.6458,0 -90.5127,14.6533,0 -90.5116,14.6568,0 -90.5098,14.6630,0</coordinates></LineString></Placemark>'
        .'</Folder><Folder><name>Estaciones</name>'
        .$punto('San Sebastián - Líneas 1 y 2', 14.645912, -90.513612)
        .$punto('Simeón Cañas', 14.656801, -90.511601)
        .$punto('Hipódromo del Norte', 14.662999, -90.509801)
        .$punto('San José de la Montaña', 14.656203, -90.512003)
        .$punto('Otra cosa que no es estación', 14.650000, -90.500000)
        .'</Folder></Document></kml>';
}

beforeEach(function () {
    $this->linea = Linea::where('codigo_linea', 'L2')->firstOrFail();
});

test('se importa el recorrido oficial y las estaciones quedan en su ubicación exacta', function () {
    $this->actingAs($this->administrador())
        ->post(route('admin.lineas.trazo.importar', $this->linea), [
            'kml' => UploadedFile::fake()->createWithContent('Transmetro - Línea 2.kml', kmlLinea2()),
        ])
        ->assertRedirect(route('admin.lineas.trazo.edit', $this->linea))
        ->assertSessionHas('estado', fn ($m) => str_contains($m, '4 de 5 estaciones') && str_contains($m, 'Jocotenango'));

    $this->linea->refresh()->load('estaciones');
    $sanSebastian = $this->linea->estaciones->firstWhere('nombre', 'San Sebastián');

    // El recorrido empieza en San Sebastián, sube por la ida y baja por el regreso sin saltos.
    $trazo = $this->linea->trazo;
    expect($trazo[0])->toBe([14.6459, -90.5136])
        ->and($trazo[1])->toBe([14.6458, -90.5127])
        ->and(end($trazo))->toBe([14.6459, -90.5136])
        ->and(count($trazo))->toBe(8)
        ->and([(float) $sanSebastian->latitud, (float) $sanSebastian->longitud])->toBe([14.645912, -90.513612]);
});

test('también acepta el archivo KMZ que descarga Google My Maps', function () {
    $ruta = tempnam(sys_get_temp_dir(), 'kmz');
    $zip = new ZipArchive;
    $zip->open($ruta, ZipArchive::OVERWRITE);
    $zip->addFromString('doc.kml', kmlLinea2());
    $zip->close();

    $this->actingAs($this->administrador())
        ->post(route('admin.lineas.trazo.importar', $this->linea), [
            'kml' => new UploadedFile($ruta, 'Transmetro - Línea 2.kmz', null, null, true),
        ])
        ->assertSessionHasNoErrors();

    expect($this->linea->refresh()->tieneTrazo())->toBeTrue();
});

test('rechaza archivos que no son KML', function (string $nombre, string $contenido) {
    $this->actingAs($this->administrador())
        ->post(route('admin.lineas.trazo.importar', $this->linea), [
            'kml' => UploadedFile::fake()->createWithContent($nombre, $contenido),
        ])
        ->assertSessionHasErrors('kml');

    expect($this->linea->refresh()->trazo)->toBeNull();
})->with([
    'otra extensión' => ['mapa.pdf', '%PDF-1.4'],
    'KML dañado' => ['mapa.kml', '<kml><Document>'],
    'KML vacío' => ['mapa.kml', '<kml xmlns="http://www.opengis.net/kml/2.2"><Document></Document></kml>'],
]);

test('un operador no puede importar mapas', function () {
    $this->actingAs($this->operador())
        ->post(route('admin.lineas.trazo.importar', $this->linea), [
            'kml' => UploadedFile::fake()->createWithContent('l2.kml', kmlLinea2()),
        ])
        ->assertForbidden();
});

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
