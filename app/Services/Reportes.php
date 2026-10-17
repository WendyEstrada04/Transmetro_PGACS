<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Reportes del punto 15: "Reportes de Estaciones, Líneas y buses asignados a ella".
 *
 * Cada reporte se obtiene con una consulta SQL directa (PostgreSQL) para que la misma consulta se
 * pueda copiar en el documento del proyecto ("dejar constancia de los querys en el documento").
 * La app muestra la consulta en cada reporte y el archivo docs/Consultas_Reportes.sql las reúne.
 *
 * Filtros: los parámetros se escriben como :nombre en el SQL y se envían por separado (consulta
 * preparada), nunca pegados al texto, para evitar inyección SQL (OWASP A03).
 */
class Reportes
{
    /** Orden natural de las líneas: L1, L2, L6, L7, L12, L13, L18, R5. */
    private const ORDEN_LINEAS = "substring(l.codigo_linea from '^[A-Za-z]*'), coalesce(nullif(regexp_replace(l.codigo_linea, '[^0-9]', '', 'g'), '')::int, 0)";

    /** @return array<string, array{seccion: string, agrupar: ?string, horizontal: bool, titulo: string, descripcion: string, puntos: string, filtros: list<string>, columnas: array<string, array{0: string, 1: string}>, sql: string}> */
    public static function definiciones(): array
    {
        $orden = self::ORDEN_LINEAS;

        return [
            'estaciones' => [
                'seccion' => 'Lo que pide el enunciado',
                'agrupar' => null,
                'horizontal' => true,
                'titulo' => 'Estaciones',
                'descripcion' => 'Estaciones con municipio, líneas, aforo, accesos, agentes de seguridad y parqueos.',
                'puntos' => 'Puntos 2, 3, 6, 10, 11 y 14',
                'filtros' => ['linea', 'municipio'],
                'columnas' => [
                    'estacion' => ['Estación', 'texto'],
                    'municipio' => ['Municipio', 'texto'],
                    'direccion' => ['Dirección', 'texto'],
                    'lineas' => ['Líneas', 'texto'],
                    'aforo_maximo' => ['Aforo máx.', 'numero'],
                    'aforo_actual' => ['Personas', 'numero'],
                    'ocupacion_pct' => ['Ocupación', 'pct'],
                    'estado_aforo' => ['Estado', 'estado'],
                    'accesos' => ['Accesos', 'numero'],
                    'guardias' => ['Guardias', 'numero'],
                    'parqueos' => ['Parqueos', 'numero'],
                ],
                'sql' => <<<SQL
SELECT e.nombre AS estacion,
       m.nombre AS municipio,
       e.direccion,
       string_agg(l.codigo_linea, ', ' ORDER BY {$orden}) AS lineas,
       e.aforo_maximo,
       e.aforo_actual,
       round(e.aforo_actual * 100.0 / e.aforo_maximo, 1) AS ocupacion_pct,
       CASE WHEN e.aforo_actual >= 1.5 * e.aforo_maximo THEN 'Requiere refuerzo' ELSE 'Normal' END AS estado_aforo,
       (SELECT count(*) FROM accesos a WHERE a.id_estacion = e.id_estacion) AS accesos,
       (SELECT count(*) FROM seguridad s
          JOIN accesos a ON a.id_acceso = s.id_acceso_asignado
         WHERE a.id_estacion = e.id_estacion) AS guardias,
       (SELECT count(*) FROM parqueos p WHERE p.id_estacion = e.id_estacion) AS parqueos
  FROM estaciones e
  JOIN municipios m ON m.id_municipio = e.id_municipio
  LEFT JOIN linea_estacion le ON le.id_estacion = e.id_estacion
  LEFT JOIN lineas l ON l.id_linea = le.id_linea
 WHERE (CAST(:linea AS varchar) IS NULL
        OR e.id_estacion IN (SELECT x.id_estacion
                               FROM linea_estacion x
                               JOIN lineas y ON y.id_linea = x.id_linea
                              WHERE y.codigo_linea = :linea))
   AND (CAST(:municipio AS varchar) IS NULL OR m.nombre = :municipio)
 GROUP BY e.id_estacion, m.nombre
 ORDER BY e.nombre
SQL,
            ],

            'lineas' => [
                'seccion' => 'Lo que pide el enunciado',
                'agrupar' => null,
                'horizontal' => true,
                'titulo' => 'Líneas',
                'descripcion' => 'Líneas con municipio, estaciones, distancia, accesos, flota asignada y cumplimiento de la flota autorizada.',
                'puntos' => 'Puntos 4, 5, 8, 11 y 13',
                'filtros' => ['linea', 'municipio'],
                'columnas' => [
                    'codigo' => ['Línea', 'texto'],
                    'nombre' => ['Nombre', 'texto'],
                    'municipio' => ['Municipio', 'texto'],
                    'estaciones' => ['Estaciones', 'numero'],
                    'distancia_total_km' => ['Distancia', 'km'],
                    'accesos' => ['Accesos', 'numero'],
                    'buses_asignados' => ['Buses', 'numero'],
                    'minimo_buses' => ['Mínimo', 'numero'],
                    'maximo_buses' => ['Máximo', 'numero'],
                    'regla_buses' => ['Flota autorizada', 'estado'],
                    'tarifa' => ['Tarifa', 'moneda'],
                    'estado' => ['Estado', 'texto'],
                ],
                'sql' => <<<SQL
SELECT l.codigo_linea AS codigo,
       l.nombre,
       m.nombre AS municipio,
       count(le.id_estacion) AS estaciones,
       l.distancia_total_km,
       (SELECT count(*) FROM accesos a
          JOIN linea_estacion x ON x.id_estacion = a.id_estacion
         WHERE x.id_linea = l.id_linea) AS accesos,
       (SELECT count(*) FROM buses b WHERE b.id_linea_asignada = l.id_linea) AS buses_asignados,
       count(le.id_estacion) AS minimo_buses,
       2 * count(le.id_estacion) AS maximo_buses,
       CASE WHEN (SELECT count(*) FROM buses b WHERE b.id_linea_asignada = l.id_linea)
                 BETWEEN count(le.id_estacion) AND 2 * count(le.id_estacion)
            THEN 'Cumple' ELSE 'No cumple' END AS regla_buses,
       l.tarifa,
       l.estado
  FROM lineas l
  JOIN municipios m ON m.id_municipio = l.id_municipio
  LEFT JOIN linea_estacion le ON le.id_linea = l.id_linea
 WHERE (CAST(:linea AS varchar) IS NULL OR l.codigo_linea = :linea)
   AND (CAST(:municipio AS varchar) IS NULL OR m.nombre = :municipio)
 GROUP BY l.id_linea, m.nombre
 ORDER BY {$orden}
SQL,
            ],

            'buses' => [
                'seccion' => 'Lo que pide el enunciado',
                'agrupar' => 'linea',
                'horizontal' => true,
                'titulo' => 'Buses asignados a cada línea',
                'descripcion' => 'Unidades de cada línea con su tipo, capacidad, pasajeros a bordo, ocupación, estado, parqueo y piloto.',
                'puntos' => 'Puntos 4, 6, 7, 9 y 15',
                'filtros' => ['linea'],
                'columnas' => [
                    'linea' => ['Línea', 'texto'],
                    'placa' => ['Placa', 'texto'],
                    'tipo_unidad' => ['Tipo de unidad', 'texto'],
                    'capacidad_maxima' => ['Capacidad', 'numero'],
                    'pasajeros_a_bordo' => ['A bordo', 'numero'],
                    'ocupacion_actual_pct' => ['Ocupación', 'pct'],
                    'espera_extra' => ['Espera extra', 'texto'],
                    'estado_logico' => ['Estado', 'texto'],
                    'parqueo' => ['Parqueo', 'texto'],
                    'piloto' => ['Piloto', 'texto'],
                    'licencia' => ['Licencia', 'texto'],
                ],
                'sql' => <<<SQL
SELECT coalesce(l.codigo_linea, 'Sin línea') AS linea,
       b.placa,
       CASE b.tipo_unidad WHEN 'biarticulado' THEN 'Biarticulado' WHEN 'articulado' THEN 'Articulado'
            WHEN 'convencional' THEN 'Convencional' ELSE 'Eléctrico TuBus' END AS tipo_unidad,
       b.capacidad_maxima,
       b.pasajeros_a_bordo,
       b.ocupacion_actual_pct,
       CASE WHEN l.id_linea IS NOT NULL AND b.ocupacion_actual_pct < 25
            THEN '+5 min por estación' ELSE '—' END AS espera_extra,
       b.estado_logico,
       e.nombre || ' · ' || p.codigo_bahia AS parqueo,
       emp.nombre_completo AS piloto,
       pi.tipo_licencia || ' ' || pi.numero_licencia AS licencia
  FROM buses b
  LEFT JOIN lineas l ON l.id_linea = b.id_linea_asignada
  LEFT JOIN parqueos p ON p.id_bus_asignado = b.id_bus
  LEFT JOIN estaciones e ON e.id_estacion = p.id_estacion
  LEFT JOIN pilotos pi ON pi.id_bus_asignado = b.id_bus
  LEFT JOIN empleados emp ON emp.id_empleado = pi.id_empleado
 WHERE (CAST(:linea AS varchar) IS NULL OR l.codigo_linea = :linea)
 ORDER BY (l.id_linea IS NULL), {$orden}, b.placa
SQL,
            ],

            'recorridos' => [
                'seccion' => 'Operación',
                'agrupar' => 'linea',
                'horizontal' => false,
                'titulo' => 'Recorridos y distancias',
                'descripcion' => 'Estaciones de cada línea en orden de recorrido, con distancia entre paradas y acumulada.',
                'puntos' => 'Puntos 1, 12 y 13',
                'filtros' => ['linea'],
                'columnas' => [
                    'linea' => ['Línea', 'texto'],
                    'orden_parada' => ['Orden', 'numero'],
                    'estacion' => ['Estación', 'texto'],
                    'direccion' => ['Dirección', 'texto'],
                    'distancia_previa_km' => ['Desde la anterior', 'km'],
                    'km_acumulado' => ['Acumulado', 'km'],
                ],
                'sql' => <<<SQL
SELECT l.codigo_linea AS linea,
       le.orden_parada,
       e.nombre AS estacion,
       e.direccion,
       le.distancia_previa_km,
       sum(le.distancia_previa_km) OVER (PARTITION BY l.id_linea ORDER BY le.orden_parada) AS km_acumulado
  FROM linea_estacion le
  JOIN lineas l ON l.id_linea = le.id_linea
  JOIN estaciones e ON e.id_estacion = le.id_estacion
 WHERE (CAST(:linea AS varchar) IS NULL OR l.codigo_linea = :linea)
 ORDER BY {$orden}, le.orden_parada
SQL,
            ],

            'visitas' => [
                'seccion' => 'Operación',
                'agrupar' => 'linea',
                'horizontal' => true,
                'titulo' => 'Viajes de las unidades',
                'descripcion' => 'Paradas de cada viaje con hora de llegada y salida, tiempo detenido, ocupación, descensos y abordajes.',
                'puntos' => 'Puntos 1 y 15',
                'filtros' => ['linea'],
                'columnas' => [
                    'linea' => ['Línea', 'texto'],
                    'bus' => ['Bus', 'texto'],
                    'inicio_viaje' => ['Inicio del viaje', 'fecha'],
                    'estado_viaje' => ['Viaje', 'texto'],
                    'orden_visita' => ['Visita', 'numero'],
                    'estacion' => ['Estación', 'texto'],
                    'hora_llegada' => ['Llegada', 'hora'],
                    'hora_salida' => ['Salida', 'hora'],
                    'minutos_detenido' => ['Minutos', 'numero'],
                    'ocupacion_al_llegar_pct' => ['Ocupación', 'pct'],
                    'bajaron' => ['Bajaron', 'numero'],
                    'subieron' => ['Subieron', 'numero'],
                ],
                'sql' => <<<'SQL'
SELECT l.codigo_linea AS linea,
       b.placa AS bus,
       v.fecha_hora_inicio AS inicio_viaje,
       v.estado AS estado_viaje,
       vv.orden_visita,
       e.nombre AS estacion,
       vv.hora_llegada,
       vv.hora_salida,
       round(extract(epoch FROM (vv.hora_salida - vv.hora_llegada)) / 60, 1) AS minutos_detenido,
       vv.ocupacion_al_llegar_pct,
       vv.bajaron,
       vv.subieron
  FROM visitas_viaje vv
  JOIN viajes v ON v.id_viaje = vv.id_viaje
  JOIN buses b ON b.id_bus = v.id_bus
  JOIN lineas l ON l.id_linea = v.id_linea
  JOIN estaciones e ON e.id_estacion = vv.id_estacion
 WHERE (CAST(:linea AS varchar) IS NULL OR l.codigo_linea = :linea)
 ORDER BY v.fecha_hora_inicio DESC, b.placa, vv.orden_visita
 LIMIT 500
SQL,
            ],

            'pasajeros' => [
                'seccion' => 'Operación',
                'agrupar' => 'linea',
                'horizontal' => false,
                'titulo' => 'Abordajes y descensos por estación',
                'descripcion' => 'Abordajes, descensos y recaudación del día por línea y estación, y abordajes de los últimos 7 días.',
                'puntos' => 'Puntos 1, 14, 15 y 18',
                'filtros' => ['linea'],
                'columnas' => [
                    'linea' => ['Línea', 'texto'],
                    'orden_parada' => ['Orden', 'numero'],
                    'estacion' => ['Estación', 'texto'],
                    'subieron_hoy' => ['Subieron hoy', 'numero'],
                    'bajaron_hoy' => ['Bajaron hoy', 'numero'],
                    'recaudado_hoy' => ['Recaudado hoy', 'moneda'],
                    'subieron_7_dias' => ['Subieron (7 días)', 'numero'],
                ],
                'sql' => <<<SQL
WITH hoy AS (SELECT (now() AT TIME ZONE 'America/Guatemala')::date AS dia)
SELECT l.codigo_linea AS linea,
       le.orden_parada,
       e.nombre AS estacion,
       (SELECT count(*) FROM transacciones_pago t
         WHERE t.id_estacion = e.id_estacion AND t.id_linea = l.id_linea
           AND t.fecha_hora >= (SELECT dia FROM hoy)) AS subieron_hoy,
       (SELECT coalesce(sum(bj.cantidad), 0) FROM bajadas_bus bj
          JOIN visitas_viaje vv ON vv.id_visita = bj.id_visita
          JOIN viajes v ON v.id_viaje = vv.id_viaje
         WHERE bj.id_estacion = e.id_estacion AND v.id_linea = l.id_linea
           AND bj.fecha_hora >= (SELECT dia FROM hoy)) AS bajaron_hoy,
       (SELECT coalesce(sum(t.monto), 0) FROM transacciones_pago t
         WHERE t.id_estacion = e.id_estacion AND t.id_linea = l.id_linea
           AND t.fecha_hora >= (SELECT dia FROM hoy)) AS recaudado_hoy,
       (SELECT count(*) FROM transacciones_pago t
         WHERE t.id_estacion = e.id_estacion AND t.id_linea = l.id_linea
           AND t.fecha_hora >= (SELECT dia FROM hoy) - 6) AS subieron_7_dias
  FROM linea_estacion le
  JOIN lineas l ON l.id_linea = le.id_linea
  JOIN estaciones e ON e.id_estacion = le.id_estacion
 WHERE (CAST(:linea AS varchar) IS NULL OR l.codigo_linea = :linea)
 ORDER BY {$orden}, le.orden_parada
SQL,
            ],

            'cobros' => [
                'seccion' => 'Operación',
                'agrupar' => null,
                'horizontal' => true,
                'titulo' => 'Verificación de cobros',
                'descripcion' => 'Comprueba que cada persona que subió hoy a un bus pagó su pasaje: personas que subieron contra pagos registrados en el molinete de entrada, y lo cobrado contra la tarifa.',
                'puntos' => 'Puntos 15 y 18',
                'filtros' => ['linea'],
                'columnas' => [
                    'linea' => ['Línea', 'texto'],
                    'subieron' => ['Subieron', 'numero'],
                    'pagos' => ['Pagos', 'numero'],
                    'sin_pago' => ['Sin pago', 'numero'],
                    'tarjeta_ciudadana' => ['Tarjeta Ciudadana', 'numero'],
                    'tarjeta_bancaria' => ['Tarjeta bancaria', 'numero'],
                    'gratis' => ['Viajes gratis', 'numero'],
                    'cobrado' => ['Cobrado', 'moneda'],
                    'esperado' => ['Según la tarifa', 'moneda'],
                    'verificacion' => ['Verificación', 'estado'],
                ],
                'sql' => <<<SQL
WITH hoy AS (SELECT (now() AT TIME ZONE 'America/Guatemala')::date AS dia),
-- Personas que subieron en cada parada de hoy (lo cuenta la visita del bus a la estación)
visitas AS (
    SELECT vj.id_linea, vv.id_visita, vv.subieron
      FROM visitas_viaje vv
      JOIN viajes vj ON vj.id_viaje = vv.id_viaje
     WHERE vv.hora_llegada::date = (SELECT dia FROM hoy)
),
-- Pagos del molinete de entrada hechos en esas paradas
pagos AS (
    SELECT v.id_linea,
           count(t.*) AS pagos,
           count(t.*) FILTER (WHERE t.metodo_pago = 'tarjeta_ciudadana') AS tarjeta_ciudadana,
           count(t.*) FILTER (WHERE t.metodo_pago = 'tarjeta_bancaria') AS tarjeta_bancaria,
           count(t.*) FILTER (WHERE t.viaje_gratis) AS gratis,
           coalesce(sum(t.monto), 0) AS cobrado
      FROM visitas v
      JOIN transacciones_pago t ON t.id_visita = v.id_visita
     GROUP BY v.id_linea
)
SELECT l.codigo_linea AS linea,
       coalesce(s.subieron, 0) AS subieron,
       coalesce(p.pagos, 0) AS pagos,
       coalesce(s.subieron, 0) - coalesce(p.pagos, 0) AS sin_pago,
       coalesce(p.tarjeta_ciudadana, 0) AS tarjeta_ciudadana,
       coalesce(p.tarjeta_bancaria, 0) AS tarjeta_bancaria,
       coalesce(p.gratis, 0) AS gratis,
       coalesce(p.cobrado, 0) AS cobrado,
       (coalesce(p.pagos, 0) - coalesce(p.gratis, 0)) * l.tarifa AS esperado,
       CASE WHEN coalesce(s.subieron, 0) = coalesce(p.pagos, 0)
             AND coalesce(p.cobrado, 0) = (coalesce(p.pagos, 0) - coalesce(p.gratis, 0)) * l.tarifa
            THEN 'Cuadra' ELSE 'No cuadra' END AS verificacion
  FROM lineas l
  LEFT JOIN (SELECT id_linea, sum(subieron) AS subieron FROM visitas GROUP BY id_linea) s ON s.id_linea = l.id_linea
  LEFT JOIN pagos p ON p.id_linea = l.id_linea
 WHERE (CAST(:linea AS varchar) IS NULL OR l.codigo_linea = :linea)
 ORDER BY {$orden}
SQL,
            ],
        ];
    }

    /** Cómo se muestran en el reporte los estados que la base guarda como claves. */
    public const ETIQUETAS = [
        'activa' => 'Activa', 'inactiva' => 'Inactiva', 'mantenimiento' => 'En mantenimiento',
        'en_ruta' => 'En ruta', 'en_espera' => 'En espera', 'en_mantenimiento' => 'En mantenimiento', 'fuera_de_servicio' => 'Fuera de servicio',
        'en_curso' => 'En curso', 'finalizado' => 'Finalizado',
    ];

    /**
     * Cifras clave del reporte (van arriba de la tabla, también al imprimir).
     *
     * @param  list<array<string, mixed>>  $filas
     * @return list<array{0: string, 1: string, 2?: string}> [título, valor, nota]
     */
    public static function resumen(string $clave, array $filas): array
    {
        $c = collect($filas);
        $n = fn ($v) => number_format((float) $v);

        return match ($clave) {
            'estaciones' => [
                ['Estaciones', $n($c->count())],
                ['Personas esperando', $n($c->sum('aforo_actual')), 'de '.$n($c->sum('aforo_maximo')).' de aforo'],
                ['Requieren refuerzo', $n($c->where('estado_aforo', 'Requiere refuerzo')->count()), 'al 150 % de su aforo o más'],
                ['Accesos', $n($c->sum('accesos')), $n($c->sum('guardias')).' agentes de seguridad'],
            ],
            'lineas' => [
                ['Líneas', $n($c->count())],
                ['Paradas', $n($c->sum('estaciones')), 'las de transbordo cuentan en cada línea'],
                ['Distancia total', number_format((float) $c->sum('distancia_total_km'), 2).' km'],
                ['Buses asignados', $n($c->sum('buses_asignados')), $c->where('regla_buses', 'Cumple')->count().' de '.$c->count().' líneas con la flota autorizada'],
            ],
            'buses' => [
                ['Buses', $n($c->count()), $c->where('linea', 'Sin línea')->count().' sin línea asignada'],
                ['Capacidad total', $n($c->sum('capacidad_maxima')), 'pasajeros'],
                ['A bordo ahora', $n($c->sum('pasajeros_a_bordo'))],
                ['En ruta', $n($c->where('estado_logico', 'en_ruta')->count()), $c->whereNull('piloto')->count().' sin piloto asignado'],
            ],
            'recorridos' => [
                ['Líneas', $n($c->pluck('linea')->unique()->count())],
                ['Paradas', $n($c->count())],
                ['Distancia total', number_format((float) $c->groupBy('linea')->sum(fn ($g) => (float) $g->max('km_acumulado')), 2).' km'],
            ],
            'visitas' => [
                ['Viajes', $n($c->map(fn ($f) => $f['bus'].$f['inicio_viaje'])->unique()->count())],
                ['Paradas registradas', $n($c->count())],
                ['Subieron', $n($c->sum('subieron'))],
                ['Bajaron', $n($c->sum('bajaron'))],
            ],
            'cobros' => [
                ['Subieron hoy', $n($c->sum('subieron')), 'según las paradas de los buses'],
                ['Pagos registrados', $n($c->sum('pagos')), $n($c->sum('gratis')).' viajes gratis'],
                ['Sin pago', $n($c->sum('sin_pago')), $c->where('verificacion', 'Cuadra')->count().' de '.$c->count().' líneas cuadran'],
                ['Cobrado hoy', 'Q'.number_format((float) $c->sum('cobrado'), 2), 'según la tarifa: Q'.number_format((float) $c->sum('esperado'), 2)],
            ],
            'pasajeros' => [
                ['Subieron hoy', $n($c->sum('subieron_hoy'))],
                ['Bajaron hoy', $n($c->sum('bajaron_hoy'))],
                ['Recaudado hoy', 'Q'.number_format((float) $c->sum('recaudado_hoy'), 2)],
                ['Subieron en 7 días', $n($c->sum('subieron_7_dias'))],
            ],
            default => [],
        };
    }

    /** @return array{seccion: string, agrupar: ?string, horizontal: bool, titulo: string, descripcion: string, puntos: string, filtros: list<string>, columnas: array, sql: string} */
    public static function definicion(string $clave): array
    {
        return self::definiciones()[$clave] ?? throw new InvalidArgumentException("No existe el reporte {$clave}.");
    }

    /**
     * Ejecuta el reporte con los filtros indicados (null = sin filtro).
     *
     * @param  array<string, string|null>  $filtros
     * @return list<array<string, mixed>>
     */
    public function ejecutar(string $clave, array $filtros = []): array
    {
        [$sql, $valores] = self::preparar(self::definicion($clave)['sql'], $filtros);

        return array_map(fn ($fila) => (array) $fila, DB::select($sql, $valores));
    }

    /**
     * Cambia los parámetros :nombre por ? (en el mismo orden) para enviarlos como consulta preparada.
     *
     * @return array{0: string, 1: list<string|null>}
     */
    public static function preparar(string $sql, array $filtros): array
    {
        $valores = [];
        $sql = preg_replace_callback('/(?<!:):([a-z_]+)/', function ($m) use (&$valores, $filtros) {
            $valores[] = isset($filtros[$m[1]]) && $filtros[$m[1]] !== '' ? (string) $filtros[$m[1]] : null;

            return '?';
        }, $sql);

        return [$sql, $valores];
    }

    /** Todas las consultas en un solo archivo SQL (para el documento del proyecto). */
    public static function archivoSql(): string
    {
        $texto = "-- Sistema de Control Transmetro — Consultas SQL de los reportes (punto 15)\n"
            ."-- PostgreSQL. Los parámetros :linea y :municipio son filtros opcionales (NULL = todos).\n"
            ."-- Este archivo se genera con: php artisan transmetro:consultas-reportes\n";
        foreach (self::definiciones() as $clave => $r) {
            $texto .= "\n-- ".str_repeat('=', 70)."\n-- {$r['titulo']} ({$r['puntos']})\n-- {$r['descripcion']}\n"
                .'-- '.str_repeat('=', 70)."\n{$r['sql']};\n";
        }

        return $texto;
    }
}
