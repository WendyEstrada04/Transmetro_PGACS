-- Sistema de Control Transmetro — Consultas SQL de los reportes (punto 15)
-- PostgreSQL. Los parámetros :linea y :municipio son filtros opcionales (NULL = todos).
-- Este archivo se genera con: php artisan transmetro:consultas-reportes

-- ======================================================================
-- Estaciones (Puntos 2, 3, 6, 10, 11 y 14)
-- Estaciones con municipio, líneas, aforo, accesos, agentes de seguridad y parqueos.
-- ======================================================================
SELECT e.nombre AS estacion,
       m.nombre AS municipio,
       e.direccion,
       string_agg(l.codigo_linea, ', ' ORDER BY substring(l.codigo_linea from '^[A-Za-z]*'), coalesce(nullif(regexp_replace(l.codigo_linea, '[^0-9]', '', 'g'), '')::int, 0)) AS lineas,
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
 ORDER BY e.nombre;

-- ======================================================================
-- Líneas (Puntos 4, 5, 8, 11 y 13)
-- Líneas con municipio, estaciones, distancia, accesos, flota asignada y cumplimiento de la flota autorizada.
-- ======================================================================
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
 ORDER BY substring(l.codigo_linea from '^[A-Za-z]*'), coalesce(nullif(regexp_replace(l.codigo_linea, '[^0-9]', '', 'g'), '')::int, 0);

-- ======================================================================
-- Buses asignados a cada línea (Puntos 4, 6, 7, 9 y 15)
-- Unidades de cada línea con su tipo, capacidad, pasajeros a bordo, ocupación, estado, parqueo y piloto.
-- ======================================================================
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
 ORDER BY (l.id_linea IS NULL), substring(l.codigo_linea from '^[A-Za-z]*'), coalesce(nullif(regexp_replace(l.codigo_linea, '[^0-9]', '', 'g'), '')::int, 0), b.placa;

-- ======================================================================
-- Recorridos y distancias (Puntos 1, 12 y 13)
-- Estaciones de cada línea en orden de recorrido, con distancia entre paradas y acumulada.
-- ======================================================================
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
 ORDER BY substring(l.codigo_linea from '^[A-Za-z]*'), coalesce(nullif(regexp_replace(l.codigo_linea, '[^0-9]', '', 'g'), '')::int, 0), le.orden_parada;

-- ======================================================================
-- Viajes de las unidades (Puntos 1 y 15)
-- Paradas de cada viaje con hora de llegada y salida, tiempo detenido, ocupación, descensos y abordajes.
-- ======================================================================
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
 LIMIT 500;

-- ======================================================================
-- Abordajes y descensos por estación (Puntos 1, 14, 15 y 18)
-- Abordajes, descensos y recaudación del día por línea y estación, y abordajes de los últimos 7 días.
-- ======================================================================
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
 ORDER BY substring(l.codigo_linea from '^[A-Za-z]*'), coalesce(nullif(regexp_replace(l.codigo_linea, '[^0-9]', '', 'g'), '')::int, 0), le.orden_parada;

-- ======================================================================
-- Verificación de cobros (Puntos 15 y 18)
-- Comprueba que cada persona que subió hoy a un bus pagó su pasaje: personas que subieron contra pagos registrados en el molinete de entrada, y lo cobrado contra la tarifa.
-- ======================================================================
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
 ORDER BY substring(l.codigo_linea from '^[A-Za-z]*'), coalesce(nullif(regexp_replace(l.codigo_linea, '[^0-9]', '', 'g'), '')::int, 0);
