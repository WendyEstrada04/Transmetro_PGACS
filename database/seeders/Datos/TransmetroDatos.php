<?php

namespace Database\Seeders\Datos;

/**
 * Datos de prueba del Sistema de Control Transmetro.
 *
 * Las líneas, estaciones, horarios y colores están en LineasTransmetro.php (información pública
 * del Transmetro, Municipalidad de Guatemala). Tarjeta Ciudadana: Q20 con 5 viajes gratis;
 * recargas de Q5 a Q200; tarifa Q1.00 (paradas continuas) o Q2.00 (directo).
 * Estaciones, direcciones, ubicación y recorrido salen de los mapas oficiales (carpeta mapas/); los buses, el personal,
 * las tarjetas y los pagos son datos ficticios para demostrar el sistema.
 *
 * Recibe funciones para cifrar y para generar hashes de contraseñas (así se puede probar sin la base de datos).
 */
class TransmetroDatos
{
    private const NOMBRES = ['José', 'María', 'Carlos', 'Ana', 'Luis', 'Sofía', 'Juan', 'Lucía', 'Pedro', 'Andrea',
        'Miguel', 'Gabriela', 'Jorge', 'Fernanda', 'Diego', 'Valeria', 'Óscar', 'Karla', 'Byron', 'Heidy',
        'Marvin', 'Evelyn', 'Otto', 'Mayra', 'Edgar', 'Claudia', 'Rony', 'Wendy', 'Kevin', 'Brenda'];

    private const APELLIDOS = ['López', 'García', 'Pérez', 'Hernández', 'Morales', 'Castillo', 'Ramírez', 'Juárez',
        'Méndez', 'Cifuentes', 'Barrios', 'Orellana', 'Estrada', 'Monzón', 'Sosa', 'Chávez', 'Ajú', 'Xicará',
        'Tzul', 'Coyoy', 'Rodas', 'Arana'];

    private const ZONAS = ['zona 1', 'zona 3', 'zona 5', 'zona 6', 'zona 7', 'zona 11', 'zona 12', 'zona 18', 'zona 21'];

    private const NIVELES = ['Diversificado', 'Técnico', 'Universitario incompleto', 'Universitario'];

    /** Definición de las líneas (estaciones, horario, color, buses) en LineasTransmetro.php. */
    private static function lineas(): array
    {
        return require __DIR__.'/LineasTransmetro.php';
    }

    /**
     * Estaciones (en orden), recorrido y distancias de una línea.
     * Con 'mapa' se leen del mapa oficial; si no, se usan las 'estaciones' escritas a mano.
     * Cada estación queda como [nombre, dirección, latitud, longitud, km desde la anterior].
     */
    private static function completarLinea(array $def): array
    {
        if (empty($def['mapa'])) {
            $puntos = $def['estaciones'];
            $def['circular'] ??= false;
            $def['estaciones'] = [];
            foreach ($puntos as $i => [$nombre, $direccion, $lat, $lon]) {
                $km = $i > 0 ? self::distanciaKm([$puntos[$i - 1][2], $puntos[$i - 1][3]], [$lat, $lon])
                    : ($def['circular'] && count($puntos) > 1 ? self::distanciaKm([end($puntos)[2], end($puntos)[3]], [$lat, $lon]) : 0);
                $def['estaciones'][] = [$nombre, $direccion, $lat, $lon, $km];
            }

            return $def;
        }

        $mapa = MapasOficiales::leer($def);
        $def['circular'] ??= $mapa['circular'];
        $def['trazo'] = $mapa['trazo'];
        $lista = $mapa['estaciones'];
        if (! empty($def['orden'])) {
            $porNombre = array_column($lista, null, 0);
            $lista = array_map(fn ($n) => $porNombre[$n] ?? throw new \RuntimeException("La estación {$n} no está en el mapa."), $def['orden']);
        }

        // Distancia desde la parada anterior medida sobre el recorrido (en la primera, el tramo de regreso).
        // Con orden manual se estima con la distancia en línea recta × 1.2 (las calles no son rectas).
        $largo = max($mapa['largo'], 0.001);
        $n = count($lista);
        $def['estaciones'] = [];
        foreach ($lista as $i => [$nombre, $direccion, $lat, $lon, $posicion]) {
            $anterior = $lista[($i - 1 + $n) % $n];
            $km = match (true) {
                $i === 0 && ! $def['circular'] => 0,
                ! empty($def['orden']) => 1.2 * self::distanciaKm([$anterior[2], $anterior[3]], [$lat, $lon]),
                default => fmod($posicion - $anterior[4] + $largo, $largo),
            };
            $def['estaciones'][] = [$nombre, $direccion, $lat, $lon, round($km, 2)];
        }

        return $def;
    }

    /**
     * Trazos dibujados en el mapa y estaciones movidas a su lugar (archivo trazos.json, lo crea la app
     * al guardar un trazo o el comando "php artisan transmetro:guardar-trazos"). Vacío si no existe.
     */
    private static function trazos(): array
    {
        $archivo = __DIR__.'/trazos.json';

        return is_file($archivo) ? (json_decode((string) file_get_contents($archivo), true) ?: []) : [];
    }

    public static function generar(callable $cifrar, callable $hash, ?int $semilla = 2026, ?array $trazos = null): array
    {
        $trazos ??= self::trazos();
        $ubicacionGuardada = [];
        foreach ($trazos as $t) {
            $ubicacionGuardada += $t['estaciones'] ?? [];
        }
        mt_srand($semilla);
        $ahora = time();
        $fecha = fn (int $ts) => date('Y-m-d H:i:s', $ts);
        $uuid = [self::class, 'uuid'];
        $azar = fn (array $lista) => $lista[mt_rand(0, count($lista) - 1)];

        // ---------------- MUNICIPIOS ----------------
        $municipios = [];
        $mun = [];
        foreach ([['GT-0101', 'Guatemala'], ['GT-0108', 'Mixco'], ['GT-0115', 'Villa Nueva']] as [$codigo, $nombre]) {
            $mun[$nombre] = $uuid();
            $municipios[] = ['id_municipio' => $mun[$nombre], 'codigo_iso' => $codigo, 'nombre' => $nombre, 'activo' => true];
        }

        // ---------------- ESTACIONES, LÍNEAS, RECORRIDOS Y HORARIOS ----------------
        // Primero se lee cada línea (mapa oficial o lista manual) para saber qué estaciones son
        // terminales o de transbordo (en ellas: aforo 300 y 3 accesos).
        $definiciones = [];
        $usos = [];
        foreach (self::lineas() as $codigo => $def) {
            $def = self::completarLinea($def);
            if (! empty($trazos[$codigo]['trazo'])) {
                $def['trazo'] = $trazos[$codigo]['trazo']; // trazo ajustado y guardado desde la app
            }
            $definiciones[$codigo] = $def;
            foreach ($def['estaciones'] as $i => [$nombre]) {
                $usos[$nombre] = ($usos[$nombre] ?? 0) + ($i === 0 ? 2 : 1);
            }
        }
        $principal = fn (string $nombre) => ($usos[$nombre] ?? 0) >= 2;

        $estaciones = $lineas = $lineaEstacion = $horarios = [];
        $est = [];          // nombre => id
        $lineaDeEstacion = []; // id_estacion => codigo de línea
        $ubicacion = [];    // nombre => [lat, lon] (para no juntar estaciones distintas con el mismo nombre)
        $lin = [];
        foreach ($definiciones as $codigo => $def) {
            $idLinea = $uuid();
            $lin[$codigo] = ['id' => $idLinea, 'paradas' => [], 'buses' => $def['buses'], 'capacidad' => $def['capacidad'],
                'terminal' => $def['estaciones'][0][0]];
            $lineas[] = ['id_linea' => $idLinea, 'id_municipio' => $mun['Guatemala'], 'codigo_linea' => $codigo,
                'nombre' => $def['nombre'], 'tipo_servicio' => $def['tipo_servicio'], 'tarifa' => $def['tarifa'],
                'color' => $def['color'], 'ruta_circular' => $def['circular'], 'distancia_total_km' => 0, 'estado' => 'activa',
                'trazo' => empty($def['trazo']) ? null : json_encode($def['trazo'])];
            foreach ($def['horario'] as $tipoDia => [$inicio, $fin]) {
                $horarios[] = ['id_horario' => $uuid(), 'id_linea' => $idLinea, 'tipo_dia' => $tipoDia,
                    'hora_inicio' => $inicio, 'hora_fin' => $fin];
            }

            foreach ($def['estaciones'] as $i => [$nombre, $direccion, $lat, $lon, $km]) {
                [$lat, $lon] = $ubicacionGuardada[$nombre] ?? [$lat, $lon];
                if (isset($ubicacion[$nombre]) && self::distanciaKm($ubicacion[$nombre], [$lat, $lon]) > 0.15) {
                    $nombre .= " ({$codigo})"; // mismo nombre pero otra estación
                }
                if (! isset($est[$nombre])) {
                    $est[$nombre] = $uuid();
                    $ubicacion[$nombre] = [$lat, $lon];
                    $aforo = $principal($nombre) ? 300 : mt_rand(12, 22) * 10;
                    $municipio = str_contains((string) $direccion, 'Villa Nueva') ? 'Villa Nueva'
                        : (str_contains((string) $direccion, 'Mixco') ? 'Mixco' : 'Guatemala');
                    $estaciones[] = ['id_estacion' => $est[$nombre], 'id_municipio' => $mun[$municipio], 'nombre' => $nombre,
                        'direccion' => $direccion, 'aforo_maximo' => $aforo, 'aforo_actual' => intdiv($aforo * mt_rand(15, 90), 100),
                        'latitud' => $lat, 'longitud' => $lon];
                }
                $lineaDeEstacion[$est[$nombre]] ??= $codigo;
                $lineaEstacion[] = ['id_linea' => $idLinea, 'id_estacion' => $est[$nombre], 'orden_parada' => $i + 1, 'distancia_previa_km' => $km];
                $lin[$codigo]['paradas'][] = [$nombre, $i > 0 ? $km : 0];
            }
        }

        // ---------------- BUSES (entre n y 2n por línea) ----------------
        $buses = [];
        $busesAsignados = [];
        $n = 0;
        foreach ($lin as $codigo => $l) {
            for ($i = 0; $i < $l['buses']; $i++) {
                $n++;
                $id = $uuid();
                $buses[] = ['id_bus' => $id, 'placa' => sprintf('TM-%03d', $n), 'capacidad_maxima' => $l['capacidad'],
                    'id_linea_asignada' => $l['id'], 'ocupacion_actual_pct' => mt_rand(5, 95),
                    'estado_logico' => mt_rand(0, 5) ? 'en_ruta' : 'en_espera'];
                $busesAsignados[] = [$id, $codigo];
            }
        }
        foreach (['en_mantenimiento', 'en_mantenimiento', 'fuera_de_servicio'] as $estado) {
            $n++;
            $buses[] = ['id_bus' => $uuid(), 'placa' => sprintf('TM-%03d', $n), 'capacidad_maxima' => 160,
                'id_linea_asignada' => null, 'ocupacion_actual_pct' => 0, 'estado_logico' => $estado];
        }

        // ---------------- PARQUEOS en las terminales — cada bus con un parqueo ----------------
        $parqueos = [];
        $bahias = [];
        foreach ($lin as $l) {
            $bahias[$l['terminal']] = ($bahias[$l['terminal']] ?? 0) + $l['buses'] + 2; // 2 bahías libres por línea
        }
        $bahias[array_key_first($bahias)] += 3; // buses en taller / fuera de servicio
        foreach ($bahias as $estacion => $cantidad) {
            for ($i = 1; $i <= $cantidad; $i++) {
                $parqueos[] = ['id_parqueo' => $uuid(), 'id_estacion' => $est[$estacion],
                    'codigo_bahia' => sprintf('B-%02d', $i), 'id_bus_asignado' => null];
            }
        }
        foreach ($buses as $i => $bus) {
            $parqueos[$i]['id_bus_asignado'] = $bus['id_bus'];
        }

        // ---------------- ACCESOS Y MOLINETES (mínimo 2 por estación) ----------------
        $accesos = $molinetes = [];
        $e = 0;
        foreach ($est as $nombre => $idEstacion) {
            $e++;
            $ubicaciones = $principal($nombre) ? ['Norte', 'Sur', 'Oriente'] : ['Norte', 'Sur'];
            foreach ($ubicaciones as $k => $ubicacion) {
                $idAcceso = $uuid();
                $accesos[] = ['id_acceso' => $idAcceso, 'id_estacion' => $idEstacion, 'ubicacion_fisica' => "Acceso $ubicacion"];
                $molinetes[] = ['id_molinete' => $uuid(), 'id_acceso' => $idAcceso, 'id_estacion' => $idEstacion,
                    'ip_address' => sprintf('10.10.%d.%d', $e, $k + 11), 'estado_operativo' => 'activo'];
            }
        }

        // ---------------- PERSONAL ----------------
        $empleados = $pilotos = $seguridad = $operadores = [];
        $dpi = 0;
        $quitarTildes = fn (string $t) => strtr($t, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'ñ' => 'n']);
        $nuevoEmpleado = function (string $rol) use (&$empleados, &$dpi, $uuid, $cifrar, $azar, $quitarTildes) {
            $dpi++;
            $nombre = $azar(self::NOMBRES).' '.$azar(self::APELLIDOS).' '.$azar(self::APELLIDOS);
            $id = $uuid();
            $empleados[] = [
                'id_empleado' => $id,
                'dpi' => sprintf('2%08d0101', $dpi),
                'nombre_completo' => $nombre,
                'fecha_nacimiento' => sprintf('%d-%02d-%02d', mt_rand(1970, 2002), mt_rand(1, 12), mt_rand(1, 28)),
                'rol' => $rol,
                'contacto_encriptado' => $cifrar(sprintf('Tel. 5%03d-%04d · %s%d@correo.gt', mt_rand(0, 999), mt_rand(0, 9999),
                    strtolower($quitarTildes(explode(' ', $nombre)[0])), $dpi)),
                'direccion_encriptada' => $cifrar(sprintf('%d calle %d-%02d, %s, Guatemala', mt_rand(1, 30), mt_rand(1, 20),
                    mt_rand(1, 99), $azar(self::ZONAS))),
                'activo' => true,
            ];

            return $id;
        };

        foreach ($busesAsignados as $i => [$idBus]) {
            $id = $nuevoEmpleado('piloto');
            $nivel = $azar(self::NIVELES);
            $pilotos[] = ['id_empleado' => $id, 'numero_licencia' => sprintf('LIC-%06d', 100000 + $i),
                'tipo_licencia' => 'A', 'anios_experiencia' => mt_rand(2, 20), 'nivel_educativo' => $nivel,
                'historial_educativo_encriptado' => $cifrar("Primaria completa; Básicos completos; $nivel; Curso de manejo defensivo INTECAP"),
                'id_bus_asignado' => $idBus];
        }
        foreach ($accesos as $i => $acceso) {
            $id = $nuevoEmpleado('seguridad');
            $seguridad[] = ['id_empleado' => $id, 'permisos_vigentes' => 'Licencia DIGESSP vigente',
                'constancia_antecedentes_penales' => 'Vigente '.date('Y'), 'numero_placa_asignada' => sprintf('SEG-%03d', $i + 1),
                'id_acceso_asignado' => $acceso['id_acceso']];
        }
        $operadorSanSebastian = null;
        foreach ($est as $nombre => $idEstacion) {
            $id = $nuevoEmpleado('operador');
            $operadores[] = ['id_empleado' => $id, 'nivel_estudios' => $azar(self::NIVELES),
                'anios_experiencia_monitoreo' => mt_rand(1, 10), 'id_estacion_asignada' => $idEstacion];
            if ($nombre === 'San Sebastián') {
                $operadorSanSebastian = $id;
            }
        }

        // ---------------- USUARIOS (credenciales de prueba) ----------------
        $clave = $hash('Transmetro2026!');
        $users = [
            ['name' => 'Administrador Municipal', 'email' => 'admin@transmetro.test', 'email_verified_at' => $fecha($ahora),
                'password' => $clave, 'created_at' => $fecha($ahora), 'updated_at' => $fecha($ahora), 'rol' => 'administrador', 'id_empleado' => null],
            ['name' => 'Operador San Sebastián', 'email' => 'operador@transmetro.test', 'email_verified_at' => $fecha($ahora),
                'password' => $clave, 'created_at' => $fecha($ahora), 'updated_at' => $fecha($ahora), 'rol' => 'operador', 'id_empleado' => $operadorSanSebastian],
        ];

        // ---------------- TARJETA CIUDADANA: tarjetas y recargas ----------------
        $tarjetas = $recargas = [];
        for ($i = 1; $i <= 40; $i++) {
            $id = $uuid();
            $tipo = $i <= 32 ? 'ciudadana' : ($i <= 37 ? 'adulto_mayor' : 'discapacidad');
            $emision = $ahora - mt_rand(30, 400) * 86400;
            $saldo = 0;
            for ($r = 0; $r < mt_rand(1, 3); $r++) {
                $monto = $azar([5, 10, 20, 25, 50, 100]);
                $saldo += $monto;
                $recargas[] = ['id_recarga' => $uuid(), 'id_tarjeta' => $id, 'monto' => $monto,
                    'metodo_pago' => $azar(['efectivo', 'efectivo', 'debito', 'credito', 'en_linea']), 'fecha_hora' => $fecha($emision + ($r + 1) * 86400)];
            }
            $tarjetas[] = ['id_tarjeta' => $id, 'numero_tarjeta' => sprintf('TC%010d', 4000000000 + $i * 7919),
                'tipo_tarjeta' => $tipo,
                'nombre_titular' => $tipo === 'ciudadana' ? null : $azar(self::NOMBRES).' '.$azar(self::APELLIDOS),
                'dpi_titular' => $tipo === 'ciudadana' ? null : sprintf('1%08d0101', $i),
                'costo_emision' => 20.00,
                // Los 5 viajes gratis de la emisión ya se usaron en las tarjetas con más de 60 días.
                'viajes_gratis_restantes' => $emision > $ahora - 60 * 86400 ? 5 : 0,
                'saldo' => $saldo, 'estado' => $i % 15 === 0 ? 'bloqueada' : 'activa', 'fecha_emision' => $fecha($emision)];
        }

        // ---------------- PAGOS EN MOLINETES (sin efectivo) ----------------
        $tarifas = array_column($lineas, 'tarifa', 'id_linea');
        $transacciones = [];
        for ($i = 0; $i < 200; $i++) {
            $molinete = $azar($molinetes);
            $idLinea = $lin[$lineaDeEstacion[$molinete['id_estacion']]]['id'];
            $tarifa = $tarifas[$idLinea];
            $fila = ['id_transaccion' => $uuid(), 'id_molinete' => $molinete['id_molinete'], 'id_linea' => $idLinea,
                'id_tarjeta' => null, 'monto' => $tarifa, 'viaje_gratis' => false, 'metodo_pago' => 'tarjeta_bancaria',
                'fecha_hora' => $fecha($ahora - mt_rand(0, 7 * 86400))];

            $t = mt_rand(0, count($tarjetas) - 1);
            if (mt_rand(1, 10) <= 8 && $tarjetas[$t]['estado'] === 'activa') {
                if ($tarjetas[$t]['viajes_gratis_restantes'] > 0) {
                    $tarjetas[$t]['viajes_gratis_restantes']--;
                    $fila = ['monto' => 0, 'viaje_gratis' => true, 'metodo_pago' => 'tarjeta_ciudadana', 'id_tarjeta' => $tarjetas[$t]['id_tarjeta']] + $fila;
                } elseif ($tarjetas[$t]['saldo'] >= $tarifa) {
                    $tarjetas[$t]['saldo'] -= $tarifa;
                    $fila = ['metodo_pago' => 'tarjeta_ciudadana', 'id_tarjeta' => $tarjetas[$t]['id_tarjeta']] + $fila;
                }
            }
            $transacciones[] = $fila;
        }
        $molinetes = array_map(fn ($m) => array_diff_key($m, ['id_estacion' => true]), $molinetes);

        // ---------------- VIAJES Y VISITAS DE HOY ----------------
        $viajes = $visitas = [];
        foreach ($busesAsignados as $i => [$idBus, $codigo]) {
            $inicio = strtotime('today 05:00') + $i * 480;
            $enCurso = $i % 4 === 0;
            $idViaje = $uuid();
            $t = $inicio;
            $ultimaSalida = $inicio;
            foreach ($lin[$codigo]['paradas'] as $k => [$estacion, $km]) {
                if ($enCurso && $k >= 3) {
                    break;
                }
                $t += (int) round($km * 150);
                $ocupacion = mt_rand(5, 95);
                $espera = 45 + ($ocupacion < 25 ? 300 : 0);   // punto 15: +5 min si va por debajo del 25 %
                $visitas[] = ['id_visita' => $uuid(), 'id_viaje' => $idViaje, 'id_estacion' => $est[$estacion],
                    'orden_visita' => $k + 1, 'hora_llegada' => $fecha($t), 'hora_salida' => $fecha($t + $espera),
                    'ocupacion_al_llegar_pct' => $ocupacion];
                $t += $espera;
                $ultimaSalida = $t;
            }
            $viajes[] = ['id_viaje' => $idViaje, 'id_bus' => $idBus, 'id_linea' => $lin[$codigo]['id'],
                'fecha_hora_inicio' => $fecha($inicio), 'fecha_hora_fin' => $enCurso ? null : $fecha($ultimaSalida),
                'estado' => $enCurso ? 'en_curso' : 'finalizado'];
        }

        // ---------------- ALERTA HISTÓRICA (cerrada) Y BITÁCORA ----------------
        $alertas = [[
            'id_alerta' => $uuid(), 'id_estacion' => $est['Centro Cívico'], 'tipo_alerta' => 'refuerzo_bus',
            'descripcion' => 'La estacion supero el 150% de su aforo maximo (455/300). Se requiere unidad de refuerzo.',
            'estado' => 'cerrada', 'fecha_creacion' => $fecha($ahora - 2 * 86400), 'fecha_cierre' => $fecha($ahora - 2 * 86400 + 900),
        ]];
        $bitacora = [[
            'id_bitacora' => $uuid(), 'id_usuario' => 1, 'id_empleado_usuario' => null, 'tabla_afectada' => 'buses',
            'id_registro_afectado' => $buses[0]['id_bus'], 'accion' => 'UPDATE',
            'datos_previos' => json_encode(['estado_logico' => 'en_espera']), 'datos_nuevos' => json_encode(['estado_logico' => 'en_ruta']),
            'fecha_registro' => $fecha($ahora - 3600),
        ]];

        return [
            // Orden de inserción (respeta llaves foráneas y triggers).
            'tablas' => [
                'municipios' => $municipios,
                'estaciones' => $estaciones,
                'lineas' => $lineas,
                'linea_estacion' => $lineaEstacion,
                'horarios_linea' => $horarios,
                'buses' => $buses,
                'parqueos' => $parqueos,
                'accesos' => $accesos,
                'molinetes' => $molinetes,
                'empleados' => $empleados,
                'pilotos' => $pilotos,
                'seguridad' => $seguridad,
                'operadores' => $operadores,
                'users' => $users,
                'tarjetas' => $tarjetas,
                'recargas' => $recargas,
                'transacciones_pago' => $transacciones,
                'viajes' => $viajes,
                'visitas_viaje' => $visitas,
                'alertas_sistema' => $alertas,
                'bitacora_auditoria' => $bitacora,
            ],
            // Punto 14: San Sebastián queda saturada (160 %); el trigger crea la alerta abierta.
            'aforos' => [$est['San Sebastián'] => 480],
        ];
    }

    /** Distancia aproximada en km entre dos coordenadas (fórmula de Haversine). */
    private static function distanciaKm(array $a, array $b): float
    {
        $rad = M_PI / 180;
        $dLat = ($b[0] - $a[0]) * $rad;
        $dLon = ($b[1] - $a[1]) * $rad;
        $h = sin($dLat / 2) ** 2 + cos($a[0] * $rad) * cos($b[0] * $rad) * sin($dLon / 2) ** 2;

        return round(2 * 6371 * asin(sqrt($h)), 2);
    }

    public static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0F) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
