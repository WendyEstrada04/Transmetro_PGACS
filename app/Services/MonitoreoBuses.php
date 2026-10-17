<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Posición en vivo de los buses en ruta (solo monitoreo).
 *
 * Para cada bus con un viaje en curso: la última estación a la que llegó, la próxima de su ruta y
 * cuánto lleva del tramo entre las dos. Mientras no pasa su hora de salida está detenido en la estación;
 * después avanza por el tramo en el tiempo que la simulación le da a esa distancia. Todo en una sola consulta.
 */
class MonitoreoBuses
{
    private const CONSULTA = <<<'SQL'
        WITH ult AS (
            SELECT DISTINCT ON (v.id_bus) v.id_bus, v.id_viaje, v.id_linea, vv.id_estacion, vv.orden_visita, vv.hora_llegada, vv.hora_salida,
                   vv.subieron, vv.bajaron
              FROM viajes v
              JOIN visitas_viaje vv ON vv.id_viaje = v.id_viaje
             WHERE v.estado = 'en_curso'
             ORDER BY v.id_bus, vv.orden_visita DESC
        )
        SELECT u.id_viaje, u.orden_visita, b.id_bus, b.placa, b.capacidad_maxima, b.ocupacion_actual_pct, b.pasajeros_a_bordo, u.subieron, u.bajaron,
               l.codigo_linea, l.color,
               u.id_estacion AS desde, u.hora_llegada, u.hora_salida,
               n.id_estacion AS hacia, n.distancia_previa_km
          FROM ult u
          JOIN buses b ON b.id_bus = u.id_bus
          JOIN lineas l ON l.id_linea = u.id_linea
          JOIN linea_estacion la ON la.id_linea = u.id_linea AND la.id_estacion = u.id_estacion
          LEFT JOIN LATERAL (
                SELECT lp.id_estacion, lp.distancia_previa_km
                  FROM linea_estacion lp
                 WHERE lp.id_linea = u.id_linea AND (lp.orden_parada > la.orden_parada OR l.ruta_circular)
                 ORDER BY lp.orden_parada <= la.orden_parada, lp.orden_parada
                 LIMIT 1) n ON true
         ORDER BY l.codigo_linea, b.placa
        SQL;

    /** @return array<int, array<string, mixed>> */
    public function posiciones(?string $idBusPropio = null): array
    {
        return collect(DB::select(self::CONSULTA))->map(function ($f) use ($idBusPropio) {
            $salida = Carbon::parse($f->hora_salida);
            // El mismo tiempo de tramo que usa la simulación para decidir cuándo llega el bus.
            $segundos = SimuladorOperacion::segundosTramo((float) $f->distancia_previa_km, $salida,
                SimuladorOperacion::claveTramo($f->id_viaje, (int) $f->orden_visita));

            return [
                'id' => $f->id_bus,
                'placa' => $f->placa,
                'linea' => $f->codigo_linea,
                'color' => $f->color,
                'ocupacion' => (int) round((float) $f->ocupacion_actual_pct),
                // Lo que marcan los molinetes del bus: a bordo = subidas − bajadas; y en su última parada.
                'pasajeros' => (int) $f->pasajeros_a_bordo,
                'capacidad' => (int) $f->capacidad_maxima,
                'lleno' => (int) $f->pasajeros_a_bordo >= (int) $f->capacidad_maxima,
                'subieron' => (int) $f->subieron,
                'bajaron' => (int) $f->bajaron,
                'desde' => $f->desde,
                'hacia' => $f->hacia,
                'llegada' => Carbon::parse($f->hora_llegada)->format('H:i'),
                // El navegador calcula el avance en cada cuadro: sale a esta hora y tarda estos segundos.
                'salida' => $salida->getTimestampMs(),
                'duracion' => (int) round($segundos * 1000),
                'propio' => $f->id_bus === $idBusPropio,
            ];
        })->values()->all();
    }

    /** Hora del servidor en milisegundos (el navegador la usa para corregir la diferencia de reloj). */
    public static function ahoraMs(): int
    {
        return Carbon::now()->getTimestampMs();
    }
}
