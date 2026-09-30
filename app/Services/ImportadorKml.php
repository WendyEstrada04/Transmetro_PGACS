<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * Lee un archivo KML o KMZ exportado de Google My Maps (el mapa oficial de cada línea en
 * muniguate.com/movilidadurbana) y obtiene el recorrido (líneas) y las estaciones (puntos).
 */
class ImportadorKml
{
    /** Distancia máxima (km) para considerar que dos tramos del recorrido se tocan. */
    private const UNION_KM = 0.05;

    /**
     * @return array{tramos: list<list<array{0: float, 1: float}>>, puntos: list<array{nombre: string, lat: float, lon: float}>}
     */
    public function leerArchivo(UploadedFile $archivo): array
    {
        $contenido = (string) file_get_contents($archivo->getRealPath());

        // Un KMZ es un ZIP que adentro trae el KML (normalmente doc.kml).
        if (str_starts_with($contenido, "PK\x03\x04")) {
            $zip = new ZipArchive;
            if ($zip->open($archivo->getRealPath()) !== true) {
                throw new RuntimeException('No se pudo abrir el archivo KMZ.');
            }
            $contenido = '';
            for ($i = 0; $i < $zip->numFiles; $i++) {
                if (str_ends_with(strtolower((string) $zip->getNameIndex($i)), '.kml')) {
                    $contenido = (string) $zip->getFromIndex($i);
                    break;
                }
            }
            $zip->close();
        }

        return $this->leer($contenido);
    }

    /**
     * @return array{tramos: list<list<array{0: float, 1: float}>>, puntos: list<array{nombre: string, lat: float, lon: float}>}
     */
    public function leer(string $kml): array
    {
        // LIBXML_NONET: nunca descarga nada de internet mientras lee el archivo.
        $xml = @simplexml_load_string($kml, SimpleXMLElement::class, LIBXML_NONET);
        if ($xml === false) {
            throw new RuntimeException('El archivo no es un KML válido.');
        }
        $xml->registerXPathNamespace('k', $xml->getNamespaces()[''] ?? 'http://www.opengis.net/kml/2.2');

        // Recorrido: líneas (LineString) y también figuras cerradas (Polygon), que es como la
        // Municipalidad dibujó algunas rutas circulares en su mapa (p. ej. la Línea 6).
        $tramos = [];
        $geometrias = array_merge(
            $xml->xpath('//k:LineString/k:coordinates') ?: [],
            $xml->xpath('//k:Polygon/k:outerBoundaryIs/k:LinearRing/k:coordinates') ?: [],
        );
        foreach ($geometrias as $coordenadas) {
            $puntos = $this->coordenadas((string) $coordenadas);
            if (count($puntos) >= 2) {
                $tramos[] = $puntos;
            }
        }

        $puntos = [];
        foreach ($xml->xpath('//k:Placemark[k:Point]') ?: [] as $marca) {
            $marca->registerXPathNamespace('k', $xml->getNamespaces()[''] ?? 'http://www.opengis.net/kml/2.2');
            $coordenada = $this->coordenadas((string) ($marca->xpath('k:Point/k:coordinates')[0] ?? ''));
            if ($coordenada) {
                $puntos[] = ['nombre' => trim((string) $marca->name), 'lat' => $coordenada[0][0], 'lon' => $coordenada[0][1]];
            }
        }

        if (! $tramos && ! $puntos) {
            throw new RuntimeException('El archivo no trae recorrido ni estaciones.');
        }

        return ['tramos' => $tramos, 'puntos' => $puntos];
    }

    /**
     * Une los tramos en un solo recorrido: empieza por el tramo más cercano a la primera estación
     * y va tomando el tramo cuyo extremo quede más cerca del final actual (dándole vuelta si hace falta).
     * Si hay empate, se respeta el sentido en que se dibujó cada tramo en el mapa.
     *
     * @param  list<list<array{0: float, 1: float}>>  $tramos
     * @param  array{0: float, 1: float}|null  $inicio
     * @return list<array{0: float, 1: float}>
     */
    public function encadenar(array $tramos, ?array $inicio = null): array
    {
        if (! $tramos) {
            return [];
        }

        // Primer tramo: el que tiene un extremo más cerca de la estación inicial.
        $actual = $inicio ?? $tramos[0][0];
        $recorrido = [];
        while ($tramos) {
            $mejor = null;
            foreach ($tramos as $i => $tramo) {
                $cerrado = count($tramo) > 2 && self::km($tramo[0], end($tramo)) < self::UNION_KM / 10;
                foreach ([false, true] as $alReves) {
                    if ($cerrado) {
                        // En una figura cerrada cualquier punto sirve de inicio: se mide al más cercano.
                        if ($alReves) {
                            continue;
                        }
                        $d = min(array_map(fn ($p) => self::km($actual, $p), $tramo));
                        if ($mejor === null || $d < $mejor[0]) {
                            $mejor = [$d, $i, false];
                        }

                        continue;
                    }
                    $extremo = $alReves ? end($tramo) : $tramo[0];
                    // Se prefiere el sentido en que se dibujó el tramo (pequeña penalización al darle vuelta).
                    $d = self::km($actual, $extremo) + ($alReves ? self::UNION_KM / 2 : 0);
                    if ($mejor === null || $d < $mejor[0]) {
                        $mejor = [$d, $i, $alReves];
                    }
                }
            }
            [, $i, $alReves] = $mejor;
            $tramo = $alReves ? array_reverse($tramos[$i]) : $tramos[$i];
            unset($tramos[$i]);

            // Figura cerrada (ruta circular): se "abre" en el punto más cercano y se recorre completa.
            if (count($tramo) > 2 && self::km($tramo[0], end($tramo)) < self::UNION_KM / 10) {
                array_pop($tramo);
                $cerca = 0;
                foreach ($tramo as $k => $punto) {
                    if (self::km($actual, $punto) < self::km($actual, $tramo[$cerca])) {
                        $cerca = $k;
                    }
                }
                $tramo = array_merge(array_slice($tramo, $cerca), array_slice($tramo, 0, $cerca));
                $tramo[] = $tramo[0];
            }

            // Si el tramo empieza donde terminó el anterior, no se repite ese punto.
            if ($recorrido && self::km(end($recorrido), $tramo[0]) < self::UNION_KM / 10) {
                array_shift($tramo);
            }
            array_push($recorrido, ...$tramo);
            $actual = end($recorrido);
        }

        return array_values($recorrido);
    }

    /** Busca la estación de la línea que corresponde al nombre de un punto del mapa. */
    public static function nombreNormalizado(string $nombre): string
    {
        // "San Sebastián - Líneas 1 y 2" → "sansebastian"; "Francos y Monroy" = "Francos Monroy".
        $nombre = preg_split('/\s[-–]\s/u', $nombre)[0];
        $nombre = Str::lower(Str::ascii($nombre));
        $nombre = preg_replace('/\b(y|estacion|transmetro)\b/', ' ', $nombre);

        return preg_replace('/[^a-z0-9]/', '', $nombre);
    }

    /** @return list<array{0: float, 1: float}> */
    private function coordenadas(string $texto): array
    {
        $puntos = [];
        foreach (preg_split('/\s+/', trim($texto)) ?: [] as $trio) {
            $partes = explode(',', $trio);
            if (count($partes) >= 2 && is_numeric($partes[0]) && is_numeric($partes[1])) {
                $puntos[] = [round((float) $partes[1], 6), round((float) $partes[0], 6)];
            }
        }

        return $puntos;
    }

    /** Distancia en km entre dos puntos [lat, lon]. */
    public static function km(array $a, array $b): float
    {
        [$la1, $lo1, $la2, $lo2] = array_map('deg2rad', [$a[0], $a[1], $b[0], $b[1]]);
        $h = sin(($la2 - $la1) / 2) ** 2 + cos($la1) * cos($la2) * sin(($lo2 - $lo1) / 2) ** 2;

        return 2 * 6371 * asin(min(1, sqrt($h)));
    }
}
