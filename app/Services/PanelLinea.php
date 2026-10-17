<?php

namespace App\Services;

use App\Models\HorarioLinea;
use App\Models\Linea;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Datos del panel lateral de una línea en la pantalla principal del administrador:
 * pasajeros, estaciones con su operador y sus guardias, buses con su chofer, alertas, horario y municipio.
 *
 * Todo sale de UNA sola consulta (cada viaje a la base remota de Supabase cuesta ~100–150 ms):
 * PostgreSQL arma cada parte como JSON y PHP solo la reparte.
 */
class PanelLinea
{
    private const CONSULTA = <<<'SQL'
        WITH p AS (SELECT ?::uuid AS id, ?::timestamp AS hoy),
        est AS (
            SELECT e.id_estacion, e.nombre, e.direccion, e.latitud, e.longitud, e.aforo_actual, e.aforo_maximo, le.orden_parada,
                   (SELECT json_agg(json_build_object('nombre', em.nombre_completo, 'placa', s.numero_placa_asignada,
                                                      'acceso', a.ubicacion_fisica) ORDER BY em.nombre_completo)
                      FROM seguridad s
                      JOIN accesos a ON a.id_acceso = s.id_acceso_asignado
                      JOIN empleados em ON em.id_empleado = s.id_empleado
                     WHERE a.id_estacion = e.id_estacion AND em.activo) AS guardias,
                   (SELECT eo.nombre_completo FROM operadores op
                      JOIN empleados eo ON eo.id_empleado = op.id_empleado AND eo.activo
                     WHERE op.id_estacion_asignada = e.id_estacion) AS operador,
                   (SELECT count(*) FROM alertas_sistema al WHERE al.id_estacion = e.id_estacion AND al.estado = 'abierta') AS alertas,
                   (SELECT count(*) FROM linea_estacion x WHERE x.id_estacion = e.id_estacion) AS lineas,
                   -- Hoy en esta estación y en esta línea: subidas (molinete de entrada) y bajadas (molinete de salida).
                   (SELECT count(*) FROM transacciones_pago t
                     WHERE t.id_estacion = e.id_estacion AND t.id_linea = (SELECT id FROM p) AND t.fecha_hora >= (SELECT hoy FROM p)) AS subieron,
                   (SELECT coalesce(sum(bj.cantidad), 0) FROM bajadas_bus bj
                      JOIN visitas_viaje vb ON vb.id_visita = bj.id_visita
                      JOIN viajes vj ON vj.id_viaje = vb.id_viaje
                     WHERE bj.id_estacion = e.id_estacion AND vj.id_linea = (SELECT id FROM p) AND bj.fecha_hora >= (SELECT hoy FROM p)) AS bajaron
              FROM linea_estacion le
              JOIN estaciones e ON e.id_estacion = le.id_estacion
             WHERE le.id_linea = (SELECT id FROM p)
        ),
        bus AS (
            SELECT b.id_bus, b.placa, b.tipo_unidad, b.capacidad_maxima, b.ocupacion_actual_pct, b.pasajeros_a_bordo, b.estado_logico,
                   uv.subieron AS ultima_subieron, uv.bajaron AS ultima_bajaron,
                   em.nombre_completo AS piloto, pi.tipo_licencia,
                   pq.codigo_bahia, pe.nombre AS parqueo_estacion,
                   (SELECT es.nombre
                      FROM viajes v
                      JOIN visitas_viaje vv ON vv.id_viaje = v.id_viaje
                      JOIN estaciones es ON es.id_estacion = vv.id_estacion
                     WHERE v.id_bus = b.id_bus AND v.estado = 'en_curso'
                     ORDER BY vv.orden_visita DESC LIMIT 1) AS ultima_parada,
                   (SELECT ep.nombre
                      FROM viajes v
                      JOIN visitas_viaje vv ON vv.id_viaje = v.id_viaje
                      JOIN linea_estacion la ON la.id_linea = v.id_linea AND la.id_estacion = vv.id_estacion
                      JOIN linea_estacion lp ON lp.id_linea = v.id_linea
                      JOIN lineas ln ON ln.id_linea = v.id_linea
                      JOIN estaciones ep ON ep.id_estacion = lp.id_estacion
                     WHERE v.id_bus = b.id_bus AND v.estado = 'en_curso'
                       AND vv.orden_visita = (SELECT max(x.orden_visita) FROM visitas_viaje x WHERE x.id_viaje = v.id_viaje)
                       AND (lp.orden_parada > la.orden_parada OR ln.ruta_circular)
                     ORDER BY lp.orden_parada <= la.orden_parada, lp.orden_parada LIMIT 1) AS proxima_parada,
                   CASE b.estado_logico WHEN 'en_ruta' THEN 0 WHEN 'en_espera' THEN 1 ELSE 2 END AS orden
              FROM buses b
              LEFT JOIN pilotos pi ON pi.id_bus_asignado = b.id_bus
              LEFT JOIN empleados em ON em.id_empleado = pi.id_empleado
              LEFT JOIN parqueos pq ON pq.id_bus_asignado = b.id_bus
              LEFT JOIN estaciones pe ON pe.id_estacion = pq.id_estacion
              LEFT JOIN LATERAL (
                    SELECT vv.subieron, vv.bajaron
                      FROM viajes v JOIN visitas_viaje vv ON vv.id_viaje = v.id_viaje
                     WHERE v.id_bus = b.id_bus AND v.estado = 'en_curso'
                     ORDER BY vv.orden_visita DESC LIMIT 1) uv ON true
             WHERE b.id_linea_asignada = (SELECT id FROM p)
        ),
        pas AS (
            SELECT count(*) FILTER (WHERE t.fecha_hora >= (SELECT hoy FROM p)) AS hoy,
                   coalesce(sum(t.monto) FILTER (WHERE t.fecha_hora >= (SELECT hoy FROM p)), 0) AS recaudado_hoy,
                   count(*) AS mes,
                   coalesce(sum(t.monto), 0) AS recaudado_mes
              FROM transacciones_pago t
             WHERE t.id_linea = (SELECT id FROM p) AND t.fecha_hora >= (SELECT hoy FROM p) - interval '29 days'
        ),
        semana AS (
            SELECT g::date AS dia, coalesce(c.n, 0) AS pases
              FROM generate_series((SELECT hoy FROM p) - interval '6 days', (SELECT hoy FROM p), interval '1 day') g
              LEFT JOIN (SELECT x.fecha_hora::date AS dia, count(*) AS n
                           FROM transacciones_pago x
                          WHERE x.id_linea = (SELECT id FROM p) AND x.fecha_hora >= (SELECT hoy FROM p) - interval '6 days'
                          GROUP BY 1) c ON c.dia = g::date
        )
        SELECT (SELECT coalesce(json_agg(est ORDER BY orden_parada), '[]') FROM est) AS estaciones,
               (SELECT coalesce(json_agg(bus ORDER BY orden, placa), '[]') FROM bus) AS buses,
               (SELECT row_to_json(pas) FROM pas) AS pasajeros,
               (SELECT json_agg(semana ORDER BY dia) FROM semana) AS semana,
               (SELECT count(*) FROM viajes v WHERE v.id_linea = (SELECT id FROM p) AND v.estado = 'en_curso') AS viajes,
               (SELECT coalesce(json_agg(a ORDER BY a.fecha_creacion DESC), '[]')
                  FROM (SELECT al.id_alerta, al.id_estacion, al.id_bus, al.tipo_alerta, al.descripcion, al.fecha_creacion
                          FROM alertas_sistema al
                         WHERE al.estado = 'abierta' AND al.id_estacion IN (SELECT id_estacion FROM est)) a) AS alertas,
               (SELECT coalesce(json_agg(h), '[]')
                  FROM (SELECT id_horario, id_linea, tipo_dia, hora_inicio, hora_fin FROM horarios_linea WHERE id_linea = (SELECT id FROM p)) h) AS horarios,
               (SELECT m.nombre FROM municipios m JOIN lineas l ON l.id_municipio = m.id_municipio WHERE l.id_linea = (SELECT id FROM p)) AS municipio
        SQL;

    public function datos(Linea $linea): array
    {
        // "Hoy" con la hora de Guatemala (la base remota puede estar en UTC).
        $fila = DB::selectOne(self::CONSULTA, [$linea->id_linea, now()->startOfDay()->toDateTimeString()]);
        $json = fn ($valor) => json_decode($valor ?? '[]', true) ?: [];

        // El horario llega en la misma consulta: se lo damos a la línea para enServicio() y horarioDelDia().
        $linea->setRelation('horarios', HorarioLinea::hydrate($json($fila->horarios)));

        $estaciones = collect($json($fila->estaciones))->map(function ($e) {
            $e = (object) $e;
            $e->aforo_actual = (int) $e->aforo_actual;
            $e->aforo_maximo = max(1, (int) $e->aforo_maximo);
            $e->pct = round($e->aforo_actual * 100 / $e->aforo_maximo);
            $e->guardias = $e->guardias ?? [];
            $e->operador ??= null;
            $e->alertas = (int) $e->alertas;
            $e->transbordo = (int) $e->lineas > 1;
            $e->subieron = (int) $e->subieron;
            $e->bajaron = (int) $e->bajaron;

            return $e;
        });

        $buses = collect($json($fila->buses))->map(function ($b) {
            $b = (object) $b;
            $b->ocupacion_actual_pct = (float) $b->ocupacion_actual_pct;
            $b->pasajeros_a_bordo = (int) $b->pasajeros_a_bordo;
            $b->lleno = $b->estado_logico === 'en_ruta' && $b->pasajeros_a_bordo >= (int) $b->capacidad_maxima;

            return $b;
        });

        $nombres = $estaciones->pluck('nombre', 'id_estacion');
        $alertas = collect($json($fila->alertas))->map(fn ($a) => (object) ($a + [
            'estacion' => $nombres[$a['id_estacion']] ?? '',
        ]))->each(fn ($a) => $a->fecha_creacion = Carbon::parse($a->fecha_creacion));

        return [
            'linea' => $linea,
            'municipio' => $fila->municipio,
            'estaciones' => $estaciones,
            'buses' => $buses,
            'alertas' => $alertas,
            'pasajeros' => $this->pasajeros($json($fila->pasajeros), $json($fila->semana), (int) $fila->viajes)
                + ['bajaronHoy' => (int) $estaciones->sum('bajaron'), 'aBordo' => (int) $buses->sum('pasajeros_a_bordo'),
                    'llenos' => $buses->where('lleno', true)->count()],
            'personal' => $this->personal($estaciones, $buses),
            'flota' => $this->flota($estaciones, $buses),
            'silueta' => $this->silueta($linea, $estaciones),
            'aforo' => [
                'actual' => (int) $estaciones->sum('aforo_actual'),
                'maximo' => (int) $estaciones->sum('aforo_maximo'),
            ],
        ];
    }

    /** Pasajes cobrados al subir a los buses de la línea: hoy, últimos 30 días y la serie de los últimos 7 días. */
    private function pasajeros(array $p, array $semana, int $viajes): array
    {
        $semana = collect($semana)->map(fn ($d) => ['dia' => Carbon::parse($d['dia']), 'pases' => (int) $d['pases']]);

        return [
            'hoy' => (int) ($p['hoy'] ?? 0),
            'recaudadoHoy' => (float) ($p['recaudado_hoy'] ?? 0),
            'mes' => (int) ($p['mes'] ?? 0),
            'recaudadoMes' => (float) ($p['recaudado_mes'] ?? 0),
            'semana' => $semana,
            'maximoSemana' => max(1, (int) $semana->max('pases')),
            'viajesEnCurso' => $viajes,
        ];
    }

    /**
     * Personal de la línea, sin consultas extra:
     *  - equipos: cada bus con su chofer,
     *  - seguridad: cada estación con su operador (punto 16) y sus guardias (por acceso).
     */
    private function personal(Collection $estaciones, Collection $buses): array
    {
        return [
            'equipos' => $buses->sortBy('placa')->values(),
            'seguridad' => $estaciones->map(fn ($e) => (object) [
                'estacion' => $e->nombre,
                'orden' => $e->orden_parada,
                'operador' => $e->operador,
                'guardias' => collect($e->guardias),
            ]),
            'choferes' => $buses->filter(fn ($b) => $b->piloto)->count(),
            'operadores' => $estaciones->filter(fn ($e) => $e->operador)->count(),
            'guardias' => $estaciones->sum(fn ($e) => count($e->guardias)),
        ];
    }

    /** Punto 5: la flota debe estar entre n y 2n buses (n = estaciones de la línea). */
    private function flota(Collection $estaciones, Collection $buses): array
    {
        $n = $estaciones->count();
        $total = $buses->count();
        $porEstado = $buses->countBy('estado_logico');
        $enRuta = $buses->where('estado_logico', 'en_ruta');

        return [
            'total' => $total,
            'minimo' => $n,
            'maximo' => 2 * $n,
            'dentroDelRango' => $total >= $n && $total <= 2 * $n,
            'enRuta' => (int) ($porEstado['en_ruta'] ?? 0),
            'enEspera' => (int) ($porEstado['en_espera'] ?? 0),
            'fueraDeServicio' => (int) (($porEstado['fuera_de_servicio'] ?? 0) + ($porEstado['en_mantenimiento'] ?? 0)),
            'ocupacionPromedio' => $enRuta->isEmpty() ? 0 : round($enRuta->avg('ocupacion_actual_pct')),
        ];
    }

    /**
     * Dibujo de la ruta para la cabecera del panel: el trazo real (o las estaciones unidas)
     * proyectado a un recuadro de 240 × 150, con su largo para animar que “se dibuje”.
     */
    private function silueta(Linea $linea, Collection $estaciones): array
    {
        $paradas = $estaciones->map(fn ($e) => [(float) $e->latitud, (float) $e->longitud])->values()->all();
        $puntos = $linea->tieneTrazo() ? array_values($linea->trazo) : $paradas;
        if (count($puntos) < 2) {
            return ['d' => '', 'largo' => 0, 'paradas' => []];
        }

        // Como máximo ~160 puntos: suficiente para la forma y liviano para el navegador.
        $paso = max(1, intdiv(count($puntos), 160));
        $puntos = array_values(array_filter($puntos, fn ($p, $i) => $i % $paso === 0 || $i === count($puntos) - 1, ARRAY_FILTER_USE_BOTH));

        $todos = array_merge($puntos, $paradas);
        [$latMin, $latMax] = [min(array_column($todos, 0)), max(array_column($todos, 0))];
        [$lonMin, $lonMax] = [min(array_column($todos, 1)), max(array_column($todos, 1))];
        $coseno = cos(deg2rad(($latMin + $latMax) / 2));
        [$ancho, $alto, $margen] = [240, 150, 10];
        $escala = min(($ancho - 2 * $margen) / max(($lonMax - $lonMin) * $coseno, 1e-9), ($alto - 2 * $margen) / max($latMax - $latMin, 1e-9));
        $dx = ($ancho - ($lonMax - $lonMin) * $coseno * $escala) / 2;
        $dy = ($alto - ($latMax - $latMin) * $escala) / 2;
        $proyectar = fn ($p) => [round($dx + ($p[1] - $lonMin) * $coseno * $escala, 1), round($dy + ($latMax - $p[0]) * $escala, 1)];

        $xy = array_map($proyectar, $puntos);
        $largo = 0;
        foreach ($xy as $i => [$x, $y]) {
            $largo += $i ? hypot($x - $xy[$i - 1][0], $y - $xy[$i - 1][1]) : 0;
        }

        return [
            'd' => 'M'.implode(' L', array_map(fn ($p) => "{$p[0]} {$p[1]}", $xy)),
            'largo' => (int) ceil($largo),
            'paradas' => array_map($proyectar, $paradas),
        ];
    }
}
