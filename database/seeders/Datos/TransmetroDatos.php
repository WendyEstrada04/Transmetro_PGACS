<?php

namespace Database\Seeders\Datos;

use App\Models\Bus;
use App\Services\SimuladorOperacion;

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

    /**
     * $escala: 1 = día normal de operación. Un valor menor simula un inicio de uso (poca gente):
     * menos días de historial, menos personas en las estaciones y en los buses. 0.05 = primer día.
     */
    public static function generar(callable $cifrar, callable $hash, ?int $semilla = 2026, ?array $trazos = null, float $escala = 1.0, bool $respetarHorarios = true, ?int $momento = null): array
    {
        $trazos ??= self::trazos();
        $ubicacionGuardada = [];
        foreach ($trazos as $t) {
            $ubicacionGuardada += $t['estaciones'] ?? [];
        }
        mt_srand($semilla);
        $ahora = $momento ?? time();
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
            $lin[$codigo] = ['id' => $idLinea, 'paradas' => [], 'buses' => $def['buses'], 'unidad' => $def['unidad'],
                'terminal' => $def['estaciones'][0][0]];
            $lineas[] = ['id_linea' => $idLinea, 'id_municipio' => $mun['Guatemala'], 'codigo_linea' => $codigo,
                'nombre' => $def['nombre'], 'tipo_servicio' => $def['tipo_servicio'], 'tarifa' => $def['tarifa'],
                'color' => $def['color'], 'ruta_circular' => $def['circular'], 'distancia_total_km' => 0, 'estado' => 'activa',
                'trazo' => empty($def['trazo']) ? null : json_encode($def['trazo'])];
            foreach ($def['horario'] as $tipoDia => [$inicio, $fin]) {
                $horarios[] = ['id_horario' => $uuid(), 'id_linea' => $idLinea, 'tipo_dia' => $tipoDia,
                    'hora_inicio' => $inicio, 'hora_fin' => $fin];
            }
            // Horario de hoy: los buses solo circulan entre la apertura y el cierre de su línea.
            $tipoHoy = match ((int) date('w', $ahora)) {
                0 => 'domingo_feriado', 6 => 'sabado', default => 'lunes_viernes'
            };
            [$inicioHoy, $finHoy] = $def['horario'][$tipoHoy] ?? ['00:00', '23:59'];
            $lin[$codigo]['abre'] = strtotime("today {$inicioHoy}", $ahora);
            $lin[$codigo]['cierra'] = strtotime("today {$finHoy}", $ahora);
            $lin[$codigo]['opera'] = ! $respetarHorarios || ($ahora >= $lin[$codigo]['abre'] && $ahora <= $lin[$codigo]['cierra']);

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
                        'direccion' => $direccion, 'aforo_maximo' => $aforo, 'aforo_actual' => (int) (intdiv($aforo * mt_rand(15, 90), 100) * $escala),
                        'latitud' => $lat, 'longitud' => $lon];
                }
                $lineaDeEstacion[$est[$nombre]] ??= $codigo;
                $lineaEstacion[] = ['id_linea' => $idLinea, 'id_estacion' => $est[$nombre], 'orden_parada' => $i + 1, 'distancia_previa_km' => $km];
                $lin[$codigo]['paradas'][] = [$nombre, $i > 0 ? $km : 0];
            }
        }

        // Las estaciones cuyas líneas están fuera de su horario están vacías (los viajes de hoy se calculan con la gente que había de día).
        $aforoDeDia = array_column($estaciones, 'aforo_actual', 'id_estacion');
        $conServicio = [];
        foreach ($lineaEstacion as $le) {
            foreach ($lin as $l) {
                if ($l['id'] === $le['id_linea'] && $l['opera']) {
                    $conServicio[$le['id_estacion']] = true;
                }
            }
        }
        foreach ($estaciones as &$estacionFila) {
            if (! isset($conServicio[$estacionFila['id_estacion']])) {
                $estacionFila['aforo_actual'] = 0;
            }
        }
        unset($estacionFila);

        // ---------------- BUSES (entre n y 2n por línea) ----------------
        $buses = [];
        $busesAsignados = [];
        $n = 0;
        foreach ($lin as $codigo => $l) {
            for ($i = 0; $i < $l['buses']; $i++) {
                $n++;
                $id = $uuid();
                // L12: uno de cada tres es biarticulado y los demás articulados; el resto de líneas, su tipo de unidad.
                $tipoUnidad = $l['unidad'] === 'biarticulado' && $i % 3 !== 0 ? 'articulado' : $l['unidad'];
                $buses[] = ['id_bus' => $id, 'placa' => self::placa($n), 'tipo_unidad' => $tipoUnidad,
                    'capacidad_maxima' => Bus::TIPOS_UNIDAD[$tipoUnidad][1],
                    'id_linea_asignada' => $l['id'], 'pasajeros_a_bordo' => 0,   // lo calculan los viajes de hoy (más abajo)
                    'estado_logico' => (mt_rand(0, 5) !== 0 && $l['opera'] && ($escala >= 1 || $i < max(1, (int) ceil($l['buses'] * $escala * 2)))) ? 'en_ruta' : 'en_espera'];
                $busesAsignados[] = [$id, $codigo];
            }
        }
        foreach (['en_mantenimiento', 'en_mantenimiento', 'fuera_de_servicio'] as $estado) {
            $n++;
            $buses[] = ['id_bus' => $uuid(), 'placa' => self::placa($n), 'tipo_unidad' => 'articulado',
                'capacidad_maxima' => Bus::TIPOS_UNIDAD['articulado'][1],
                'id_linea_asignada' => null, 'pasajeros_a_bordo' => 0, 'estado_logico' => $estado];
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
                $molinetes[] = ['id_molinete' => $uuid(), 'id_acceso' => $idAcceso, 'id_bus' => null, 'tipo' => 'estacion',
                    'ip_address' => sprintf('10.10.%d.%d', $e, $k + 11), 'estado_operativo' => 'activo'];
            }
        }
        // Cada bus: molinete de entrada (cobra al subir) y de salida (cuenta al bajar).
        $molineteBus = [];
        foreach ($buses as $b => $bus) {
            foreach (['entrada_bus' => 11, 'salida_bus' => 12] as $tipo => $ip) {
                $molineteBus[$bus['id_bus']][$tipo] = $uuid();
                $molinetes[] = ['id_molinete' => $molineteBus[$bus['id_bus']][$tipo], 'id_acceso' => null, 'id_bus' => $bus['id_bus'],
                    'tipo' => $tipo, 'ip_address' => sprintf('10.20.%d.%d', $b + 1, $ip), 'estado_operativo' => 'activo'];
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
        // ---------------- TARJETA CIUDADANA: tarjetas y recargas ----------------
        $tarjetas = $recargas = [];
        for ($i = 1; $i <= 40; $i++) {
            $id = $uuid();
            $tipo = $i <= 32 ? 'vecino' : ($i <= 37 ? 'adulto_mayor' : 'discapacidad');
            // Vecino: la mitad está afiliada (perfil POCAE) y la otra mitad es al portador.
            // Adulto mayor y discapacidad siempre llevan titular.
            $afiliada = $tipo !== 'vecino' || $i % 2 === 1;
            [$edadMin, $edadMax] = ['vecino' => [18, 59], 'adulto_mayor' => [60, 85], 'discapacidad' => [18, 70]][$tipo];
            $nacimiento = sprintf('%d-%02d-%02d', (int) date('Y', $ahora) - mt_rand($edadMin + 1, $edadMax), mt_rand(1, 12), mt_rand(1, 28));
            $emision = $ahora - mt_rand(30, 400) * 86400;
            $saldo = 0;
            for ($r = 0; $r < mt_rand(1, 3); $r++) {
                $monto = $azar([5, 10, 20, 25, 50, 100]);
                $saldo += $monto;
                $recargas[] = ['id_recarga' => $uuid(), 'id_tarjeta' => $id, 'monto' => $monto,
                    'metodo_pago' => $azar(['efectivo', 'efectivo', 'debito', 'credito', 'en_linea']), 'fecha_hora' => $fecha($emision + ($r + 1) * 86400)];
            }
            // Estado: dos bloqueadas (robo o extravío) y una dada de baja; las demás activas.
            [$estado, $motivo] = match (true) {
                $i % 15 === 0 => ['bloqueada', 'Robo o extravío reportado'],
                $i === 26 => ['baja', 'Tarjeta dañada sin reposición'],
                default => ['activa', null],
            };
            // Número impreso atrás de la tarjeta: 00 0040 0001, 00 0040 0002, …
            $tarjetas[] = ['id_tarjeta' => $id, 'numero_tarjeta' => sprintf('%010d', 400000 + $i),
                'tipo_tarjeta' => $tipo, 'afiliada' => $afiliada,
                'nombre_titular' => $afiliada ? $azar(self::NOMBRES).' '.$azar(self::APELLIDOS) : null,
                'dpi_titular' => $afiliada ? sprintf('1%08d0101', $i) : null,
                'fecha_nacimiento_titular' => $afiliada ? $nacimiento : null,
                'id_tarjeta_anterior' => null,
                // Vecino Q20; adulto mayor (la primera) y discapacidad, gratis.
                'costo_emision' => $tipo === 'vecino' ? 20.00 : 0,
                // Los 5 viajes de bienvenida de la tarjeta Vecino ya se usaron en las que tienen más de 60 días.
                'viajes_gratis_restantes' => $tipo === 'vecino' && $emision > $ahora - 60 * 86400 ? 5 : 0,
                'saldo' => $saldo, 'estado' => $estado, 'fecha_emision' => $fecha($emision),
                'motivo_estado' => $motivo, 'fecha_estado' => $estado === 'activa' ? null : $fecha($ahora - mt_rand(1, 20) * 86400)];
        }

        // ---------------- PAGOS: uno por cada persona que sube al bus (sin efectivo) ----------------
        // Tarjeta Ciudadana (viaje gratis o saldo) o tarjeta bancaria sin contacto.
        $tarifas = array_column($lineas, 'tarifa', 'id_linea');
        $transacciones = $bajadas = [];
        $saldoInicial = array_column($tarjetas, 'saldo');
        $cortesias = [];   // adulto mayor: viajes de cortesía usados por día (4 diarios)
        $pago = function (string $idBus, string $idLinea, string $idEstacion, ?string $idVisita, int $ts) use (&$tarjetas, &$transacciones, &$cortesias, $molineteBus, $tarifas, $uuid, $fecha, $saldoInicial) {
            $tarifa = $tarifas[$idLinea];
            $fila = ['id_transaccion' => $uuid(), 'id_molinete' => $molineteBus[$idBus]['entrada_bus'], 'id_linea' => $idLinea,
                'id_tarjeta' => null, 'monto' => $tarifa, 'viaje_gratis' => false, 'metodo_pago' => 'tarjeta_bancaria',
                'fecha_hora' => $fecha($ts), 'id_bus' => $idBus, 'id_estacion' => $idEstacion, 'id_visita' => $idVisita];
            // Cada tarjeta conserva al menos la mitad de lo que recargó (así quedan tarjetas con saldo para probar).
            $t = mt_rand(0, count($tarjetas) - 1);
            if (mt_rand(1, 10) <= 7 && $tarjetas[$t]['estado'] === 'activa') {
                $dia = date('Y-m-d', $ts);
                if ($tarjetas[$t]['tipo_tarjeta'] === 'adulto_mayor' && ($cortesias[$t][$dia] ?? 0) < 4) {
                    $cortesias[$t][$dia] = ($cortesias[$t][$dia] ?? 0) + 1;
                    $fila = ['monto' => 0, 'viaje_gratis' => true, 'metodo_pago' => 'tarjeta_ciudadana', 'id_tarjeta' => $tarjetas[$t]['id_tarjeta']] + $fila;
                } elseif ($tarjetas[$t]['viajes_gratis_restantes'] > 0) {
                    $tarjetas[$t]['viajes_gratis_restantes']--;
                    $fila = ['monto' => 0, 'viaje_gratis' => true, 'metodo_pago' => 'tarjeta_ciudadana', 'id_tarjeta' => $tarjetas[$t]['id_tarjeta']] + $fila;
                } elseif ($tarjetas[$t]['saldo'] - $tarifa >= $saldoInicial[$t] / 2) {
                    $tarjetas[$t]['saldo'] -= $tarifa;
                    $fila = ['metodo_pago' => 'tarjeta_ciudadana', 'id_tarjeta' => $tarjetas[$t]['id_tarjeta']] + $fila;
                }
            }
            $transacciones[] = $fila;
        };

        // Días anteriores (para las gráficas de la semana): pagos en los buses de cada línea.
        $paradasDeLinea = [];
        foreach ($busesAsignados as [$idBus, $codigo]) {
            $paradasDeLinea[$codigo] ??= array_map(fn ($p) => $est[$p[0]], $lin[$codigo]['paradas']);
        }
        for ($dia = (int) round(6 * $escala); $dia >= 1; $dia--) {
            $base = strtotime("today 05:00 -{$dia} days");
            for ($k = (int) round(mt_rand(250, 400) * $escala); $k > 0; $k--) {
                [$idBus, $codigo] = $azar($busesAsignados);
                $pago($idBus, $lin[$codigo]['id'], $azar($paradasDeLinea[$codigo]), null, $base + mt_rand(0, 14 * 3600));
            }
        }

        // ---------------- VIAJES Y VISITAS DE HOY ----------------
        // En cada parada el molinete de salida cuenta a los que bajan y el de entrada cobra a los que suben
        // (el mismo reparto que usa la simulación). Al final del recorrido bajan todos.
        $aforoDe = $aforoDeDia;
        $viajes = $visitas = [];
        foreach ($busesAsignados as $i => [$idBus, $codigo]) {
            // Los buses en ruta van a mitad de un viaje (cada uno en una estación distinta); los demás ya terminaron el suyo.
            $enCurso = $buses[$i]['estado_logico'] === 'en_ruta';
            if ($escala < 1 && ! $enCurso) {
                continue;   // inicio de uso: los buses que no están en ruta todavía no han hecho ningún viaje hoy
            }
            if ($respetarHorarios && $ahora < $lin[$codigo]['abre']) {
                continue;   // la línea todavía no abre hoy: no hay viajes
            }
            $total = count($lin[$codigo]['paradas']);
            $hasta = 1 + ($i * 7) % max(1, $total - 1);
            $capacidad = $buses[$i]['capacidad_maxima'];
            $idViaje = $uuid();
            $t = 0;
            $aBordo = 0;
            $filas = [];
            foreach ($lin[$codigo]['paradas'] as $k => [$estacion, $km]) {
                if ($enCurso && $k >= $hasta) {
                    break;
                }
                // Un viaje en curso no puede haber salido antes de que abriera la línea.
                if ($enCurso && $respetarHorarios && $t + (int) round($km * 150) + 345 + 160 > $ahora - $lin[$codigo]['abre']) {
                    break;
                }
                $t += (int) round($km * 150);
                $p = SimuladorOperacion::repartirParada($aBordo, $capacidad, $aforoDe[$est[$estacion]], $k === $total - 1);
                $llegaCon = $aBordo;
                $aBordo = $p['a_bordo'];
                $espera = 45 + ($aBordo * 100 / $capacidad < 25 ? 300 : 0);   // punto 15: +5 min si va por debajo del 25 %
                $filas[] = [$est[$estacion], $k + 1, $t, $t + $espera, round($llegaCon * 100 / $capacidad, 2), $p['subieron'], $p['bajaron']];
                $t += $espera;
            }
            // Horas reales: el viaje en curso salió de su última estación hace unos segundos (así se ve avanzar
            // en el monitoreo en vivo); los viajes terminados acabaron antes de ahora.
            $tope = $respetarHorarios ? min($ahora, $lin[$codigo]['cierra']) : $ahora;   // los viajes terminados acabaron antes del cierre
            $inicio = $enCurso ? $ahora - ($i % 4) * 40 - $t
                : min($lin[$codigo]['abre'] + ($i % 60) * 480, $tope - $t - 900);
            if (! $enCurso && $respetarHorarios && $inicio < $lin[$codigo]['abre']) {
                continue;   // no alcanzó a hacer un recorrido completo en el horario de hoy
            }
            foreach ($filas as [$idEstacion, $orden, $llegada, $salida, $ocupacion, $suben, $bajan]) {
                $idVisita = $uuid();
                $visitas[] = ['id_visita' => $idVisita, 'id_viaje' => $idViaje, 'id_estacion' => $idEstacion,
                    'orden_visita' => $orden, 'hora_llegada' => $fecha($inicio + $llegada), 'hora_salida' => $fecha($inicio + $salida),
                    'ocupacion_al_llegar_pct' => $ocupacion, 'subieron' => $suben, 'bajaron' => $bajan];
                if ($bajan > 0) {
                    $bajadas[] = ['id_bajada' => $uuid(), 'id_molinete' => $molineteBus[$idBus]['salida_bus'], 'id_bus' => $idBus,
                        'id_estacion' => $idEstacion, 'id_visita' => $idVisita, 'cantidad' => $bajan, 'fecha_hora' => $fecha($inicio + $llegada)];
                }
                for ($k = 0; $k < $suben; $k++) {
                    $pago($idBus, $lin[$codigo]['id'], $idEstacion, $idVisita, $inicio + $llegada + 5 + intdiv($k, 3));
                }
            }
            $buses[$i]['pasajeros_a_bordo'] = $enCurso ? $aBordo : 0;
            $viajes[] = ['id_viaje' => $idViaje, 'id_bus' => $idBus, 'id_linea' => $lin[$codigo]['id'],
                'fecha_hora_inicio' => $fecha($inicio), 'fecha_hora_fin' => $enCurso ? null : $fecha($inicio + $t),
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

        // ---------------- OPERADORES: uno por estación, en la PC de la estación (punto 16) ----------------
        // Se generan al final para no cambiar los demás datos de prueba (mismo orden de números al azar).
        $operadorUsuario = null;
        foreach ($est as $nombre => $idEstacion) {
            $id = $nuevoEmpleado('operador');
            $operadores[] = ['id_empleado' => $id, 'nivel_estudios' => $azar(self::NIVELES),
                'anios_experiencia_monitoreo' => mt_rand(1, 10), 'id_estacion_asignada' => $idEstacion];
            if ($nombre === 'San Sebastián') {
                $operadorUsuario = $id;   // el usuario de prueba trabaja en San Sebastián (L1 y L2)
            }
        }

        // ---------------- MANTENIMIENTO DE LOS BUSES ----------------
        $mantenimientos = [];
        $fallas = [
            ['frenos', 'Ruido metálico al frenar en bajada.'], ['puertas', 'La puerta trasera tarda en cerrar.'],
            ['aire', 'El aire acondicionado no enfría en la parte de atrás.'], ['validador', 'El validador de tarjetas se reinicia solo.'],
            ['llantas', 'Desgaste irregular en la llanta delantera derecha.'], ['electrico', 'Luces internas parpadean.'],
        ];
        foreach ($buses as $i => $bus) {
            $km = mt_rand(40000, 220000);
            // Servicio preventivo cada ~10 000 km: los dos últimos, ya resueltos.
            foreach ([mt_rand(70, 120), mt_rand(15, 60)] as $k => $dias) {
                $cuando = $ahora - $dias * 86400;
                $mantenimientos[] = ['id_mantenimiento' => $uuid(), 'id_bus' => $bus['id_bus'], 'id_empleado_reporta' => null,
                    'tipo' => 'preventivo', 'categoria' => 'motor', 'prioridad' => 'media', 'estado' => 'resuelto',
                    'descripcion' => 'Servicio preventivo: cambio de aceite y filtros, revisión de frenos y llantas.',
                    'kilometraje' => $km - (2 - $k) * 10000, 'fecha_reporte' => $fecha($cuando), 'fecha_cierre' => $fecha($cuando + 6 * 3600)];
            }
            if ($bus['estado_logico'] === 'en_mantenimiento') {
                [$categoria, $texto] = $azar($fallas);
                $mantenimientos[] = ['id_mantenimiento' => $uuid(), 'id_bus' => $bus['id_bus'], 'id_empleado_reporta' => null,
                    'tipo' => 'correctivo', 'categoria' => $categoria, 'prioridad' => 'alta', 'estado' => 'en_proceso',
                    'descripcion' => $texto.' En taller.', 'kilometraje' => $km, 'fecha_reporte' => $fecha($ahora - mt_rand(1, 4) * 86400), 'fecha_cierre' => null];
            } elseif ($i % 7 === 0 && isset($operadores[$i])) {
                [$categoria, $texto] = $azar($fallas);
                $mantenimientos[] = ['id_mantenimiento' => $uuid(), 'id_bus' => $bus['id_bus'], 'id_empleado_reporta' => $operadores[$i]['id_empleado'],
                    'tipo' => 'falla', 'categoria' => $categoria, 'prioridad' => 'baja', 'estado' => 'reportado',
                    'descripcion' => $texto, 'kilometraje' => $km, 'fecha_reporte' => $fecha($ahora - mt_rand(2, 30) * 3600), 'fecha_cierre' => null];
            }
        }

        // ---------------- USUARIOS (credenciales de prueba) ----------------
        $users = [
            ['name' => 'Administrador Municipal', 'email' => 'admin@gmail.com', 'email_verified_at' => $fecha($ahora),
                'password' => $hash('admin123'), 'created_at' => $fecha($ahora), 'updated_at' => $fecha($ahora), 'rol' => 'administrador', 'id_empleado' => null],
            ['name' => 'Operador estación San Sebastián', 'email' => 'operador@gmail.com', 'email_verified_at' => $fecha($ahora),
                'password' => $hash('operador123'), 'created_at' => $fecha($ahora), 'updated_at' => $fecha($ahora), 'rol' => 'operador', 'id_empleado' => $operadorUsuario],
        ];

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
                'mantenimientos_bus' => $mantenimientos,
                'users' => $users,
                'tarjetas' => $tarjetas,
                'recargas' => $recargas,
                'viajes' => $viajes,
                'visitas_viaje' => $visitas,
                'transacciones_pago' => $transacciones,
                'bajadas_bus' => $bajadas,
                'alertas_sistema' => $alertas,
                'bitacora_auditoria' => $bitacora,
            ],
            // Punto 14: San Sebastián queda saturada (160 %); el trigger crea la alerta abierta (solo si sus líneas están en servicio).
            'aforos' => isset($conServicio[$est['San Sebastián']]) ? [$est['San Sebastián'] => 480] : [],
        ];
    }

    /**
     * Placa de Guatemala para el bus número $n, como las del Transmetro (p. ej. C387JGL):
     * C (vehículo comercial) + 3 números + 3 consonantes. Distinta para cada bus.
     */
    public static function placa(int $n): string
    {
        $letras = 'BCDFGHJKLMNPQRSTVWXYZ';
        $k = $n - 1 + 3000;

        return 'C'.str_pad((string) (100 + (($n - 1) * 137) % 900), 3, '0', STR_PAD_LEFT)
            .$letras[intdiv($k, 441) % 21].$letras[intdiv($k, 21) % 21].$letras[$k % 21];
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
