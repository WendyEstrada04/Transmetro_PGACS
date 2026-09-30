<?php

namespace Database\Seeders\Datos;

use App\Services\ImportadorKml;

/**
 * Lee el mapa oficial de una línea (archivo KML de Google My Maps de muniguate.com/movilidadurbana,
 * guardado en database/seeders/Datos/mapas) y obtiene:
 *  - el recorrido (trazo) empezando en la estación terminal indicada;
 *  - las estaciones con su dirección y ubicación exacta, ORDENADAS según el recorrido;
 *  - la distancia de cada estación a la anterior medida sobre el recorrido (punto 13).
 */
class MapasOficiales
{
    /**
     * @param  array{mapa: string, inicio: string, renombrar?: array<string, string>, direcciones?: array<string, string>}  $def
     * @return array{trazo: list<array{0: float, 1: float}>, circular: bool, estaciones: list<array{0: string, 1: ?string, 2: float, 3: float, 4: float}>}
     */
    public static function leer(array $def): array
    {
        $importador = new ImportadorKml;
        $mapa = $importador->leer((string) file_get_contents(__DIR__.'/mapas/'.$def['mapa']));

        // Estaciones del mapa, sin repetir (un mismo punto puede venir en dos carpetas del mapa).
        $estaciones = [];
        foreach ($mapa['puntos'] as $punto) {
            $nombre = self::nombre($punto['nombre'], $def['renombrar'] ?? []);
            $clave = ImportadorKml::nombreNormalizado($nombre);
            $direccion = $def['direcciones'][$nombre] ?? self::direccion($punto['descripcion'] ?? '');
            $estaciones[$clave] ??= [$nombre, $direccion, $punto['lat'], $punto['lon']];
        }

        $inicio = $estaciones[ImportadorKml::nombreNormalizado($def['inicio'])] ?? reset($estaciones);
        $trazo = $importador->encadenar($mapa['tramos'], [$inicio[2], $inicio[3]]);
        $circular = count($trazo) > 2 && ImportadorKml::km($trazo[0], end($trazo)) < 0.15;

        // Posición de cada estación a lo largo del recorrido (km desde el inicio) para ordenarlas.
        // El mapa oficial no siempre está dibujado en el sentido en que circula el bus: se revisan las
        // estaciones "X (dir. Y)" y, si con el recorrido al revés quedan mejor, se le da vuelta.
        $posiciones = self::posiciones($estaciones, $inicio, $trazo);
        $alReves = array_reverse($trazo);
        $posicionesAlReves = self::posiciones($estaciones, $inicio, $alReves);
        if (self::sentidoCorrecto($posicionesAlReves, $estaciones) > self::sentidoCorrecto($posiciones, $estaciones)) {
            [$trazo, $posiciones] = [$alReves, $posicionesAlReves];
        }
        foreach ($estaciones as $clave => $e) {
            $estaciones[$clave][4] = $posiciones['km'][$clave];
        }
        uasort($estaciones, fn ($a, $b) => $a[4] <=> $b[4]);

        return ['trazo' => $trazo, 'circular' => $circular, 'estaciones' => array_values($estaciones), 'largo' => $posiciones['largo']];
    }

    public static function nombre(string $nombre, array $renombrar = []): string
    {
        $nombre = trim(preg_replace('/\s+/u', ' ', $nombre));

        return $renombrar[$nombre] ?? ImportadorKml::nombreLimpio($nombre);
    }

    private static function direccion(string $descripcion): ?string
    {
        $texto = trim(preg_replace('/\s+/u', ' ', strip_tags(str_ireplace(['<br>', '<br/>', '<br />'], ', ', $descripcion))));

        return $texto === '' ? null : mb_substr(rtrim($texto, '.'), 0, 200);
    }

    /** @return array{km: array<string, float>, largo: float} */
    private static function posiciones(array $estaciones, array $inicio, array $trazo): array
    {
        $acumulado = [0.0];
        for ($i = 1; $i < count($trazo); $i++) {
            $acumulado[$i] = $acumulado[$i - 1] + ImportadorKml::km($trazo[$i - 1], $trazo[$i]);
        }
        $km = [];
        foreach ($estaciones as $clave => $e) {
            $km[$clave] = $e === $inicio ? 0.0 : self::posicion([$e[2], $e[3]], $trazo, $acumulado);
        }

        return ['km' => $km, 'largo' => (float) end($acumulado)];
    }

    /** Cuántas estaciones "X (dir. Y)" quedan antes de Y en el sentido del recorrido. */
    private static function sentidoCorrecto(array $posiciones, array $estaciones): int
    {
        $largo = max($posiciones['largo'], 0.001);
        $aciertos = 0;
        foreach ($estaciones as $clave => $e) {
            if (! preg_match('/\(dir\. (.+)\)$/u', $e[0], $m)) {
                continue;
            }
            $destino = ImportadorKml::nombreNormalizado($m[1]);
            if (! isset($posiciones['km'][$destino])) {
                continue;
            }
            $adelante = fmod($posiciones['km'][$destino] - $posiciones['km'][$clave] + $largo, $largo);
            $aciertos += $adelante < $largo / 2 ? 1 : 0;
        }

        return $aciertos;
    }

    /** Km recorridos desde el inicio hasta el punto del trazo más cercano a la estación. */
    private static function posicion(array $p, array $trazo, array $acumulado): float
    {
        $mejor = [INF, 0.0];
        for ($i = 1; $i < count($trazo); $i++) {
            [$a, $b] = [$trazo[$i - 1], $trazo[$i]];
            // Proyección sobre el segmento (en coordenadas planas locales, suficiente a esta escala).
            $cos = cos(deg2rad($p[0]));
            $ax = ($b[1] - $a[1]) * $cos;
            $ay = $b[0] - $a[0];
            $px = ($p[1] - $a[1]) * $cos;
            $py = $p[0] - $a[0];
            $largo = $ax * $ax + $ay * $ay;
            $t = $largo > 0 ? max(0, min(1, ($px * $ax + $py * $ay) / $largo)) : 0;
            $cerca = [$a[0] + ($b[0] - $a[0]) * $t, $a[1] + ($b[1] - $a[1]) * $t];
            $d = ImportadorKml::km($p, $cerca);
            if ($d < $mejor[0] - 1e-9) {
                $mejor = [$d, $acumulado[$i - 1] + ImportadorKml::km($a, $cerca)];
            }
        }

        return $mejor[1];
    }
}
