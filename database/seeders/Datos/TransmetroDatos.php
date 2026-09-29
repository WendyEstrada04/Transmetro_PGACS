<?php

namespace Database\Seeders\Datos;

/**
 * Datos de prueba del Sistema de Control Transmetro.
 *
 * Son datos de ejemplo para demostrar la aplicación (no son datos oficiales).
 * Respetan todas las reglas del enunciado: buses entre n y 2n por línea, cada bus
 * con parqueo, al menos 2 accesos por estación, un guardia por acceso, un operador
 * por estación, etc.
 *
 * No depende de Laravel: recibe funciones para cifrar y para generar hashes de
 * contraseñas, y devuelve las filas de cada tabla en el orden en que se insertan.
 */
class TransmetroDatos
{
    private const NOMBRES = ['José', 'María', 'Carlos', 'Ana', 'Luis', 'Sofía', 'Juan', 'Lucía', 'Pedro', 'Andrea',
        'Miguel', 'Gabriela', 'Jorge', 'Fernanda', 'Diego', 'Valeria', 'Óscar', 'Karla', 'Byron', 'Heidy',
        'Marvin', 'Evelyn', 'Otto', 'Mayra', 'Edgar', 'Claudia', 'Rony', 'Wendy', 'Kevin', 'Brenda'];

    private const APELLIDOS = ['López', 'García', 'Pérez', 'Hernández', 'Morales', 'Castillo', 'Ramírez', 'Juárez',
        'Méndez', 'Cifuentes', 'Barrios', 'Orellana', 'Estrada', 'Monzón', 'Sosa', 'Chávez', 'Ajú', 'Xicará',
        'Tzul', 'Coyoy', 'Rodas', 'Arana'];

    private const ZONAS = ['zona 1', 'zona 5', 'zona 6', 'zona 7', 'zona 11', 'zona 12', 'zona 18', 'zona 21'];

    private const NIVELES = ['Diversificado', 'Técnico', 'Universitario incompleto', 'Universitario'];

    public static function generar(callable $cifrar, callable $hash, ?int $semilla = 2026): array
    {
        mt_srand($semilla);
        $ahora = time();
        $fecha = fn (int $ts) => date('Y-m-d H:i:s', $ts);
        $uuid = [self::class, 'uuid'];

        // ---------------- MUNICIPIOS ----------------
        $municipios = [];
        $mun = [];
        foreach ([['GT-0101', 'Guatemala'], ['GT-0108', 'Mixco'], ['GT-0115', 'Villa Nueva']] as [$codigo, $nombre]) {
            $id = $uuid();
            $mun[$nombre] = $id;
            $municipios[] = ['id_municipio' => $id, 'codigo_iso' => $codigo, 'nombre' => $nombre, 'activo' => true];
        }

        // ---------------- ESTACIONES ----------------
        // nombre => [municipio, aforo_maximo, latitud, longitud]
        $defEstaciones = [
            'Centra Sur' => ['Guatemala', 300, 14.5669, -90.5508],
            'Monte María' => ['Guatemala', 150, 14.5831, -90.5462],
            'El Trébol' => ['Guatemala', 250, 14.6180, -90.5292],
            'Centro Cívico' => ['Guatemala', 200, 14.6255, -90.5153],
            'Plaza Barrios' => ['Guatemala', 220, 14.6380, -90.5141],
            'Hipódromo del Norte' => ['Guatemala', 160, 14.6560, -90.5080],
            'Mixco Centro' => ['Mixco', 180, 14.6308, -90.6068],
            'El Milagro' => ['Mixco', 140, 14.6392, -90.5793],
            'Roosevelt' => ['Mixco', 160, 14.6245, -90.5590],
            'Villa Nueva Centro' => ['Villa Nueva', 200, 14.5269, -90.5875],
            'Bárcenas' => ['Villa Nueva', 140, 14.5480, -90.5720],
        ];
        $estaciones = [];
        $est = [];
        foreach ($defEstaciones as $nombre => [$municipio, $aforo, $lat, $lon]) {
            $id = $uuid();
            $est[$nombre] = $id;
            $estaciones[] = [
                'id_estacion' => $id, 'id_municipio' => $mun[$municipio], 'nombre' => $nombre,
                'aforo_maximo' => $aforo, 'aforo_actual' => intdiv($aforo * mt_rand(20, 90), 100),
                'latitud' => $lat, 'longitud' => $lon,
            ];
        }

        // ---------------- LÍNEAS Y SUS ESTACIONES EN ORDEN ----------------
        // codigo => [municipio, [[estacion, km desde la anterior], ...], buses a asignar]
        $defLineas = [
            'L1' => ['Guatemala', [['Centra Sur', 0], ['Monte María', 3.2], ['El Trébol', 4.1], ['Centro Cívico', 2.0], ['Plaza Barrios', 1.5]], 6],
            'L12' => ['Guatemala', [['Hipódromo del Norte', 0], ['Plaza Barrios', 2.4], ['Centro Cívico', 1.5], ['El Trébol', 2.0]], 5],
            'M1' => ['Mixco', [['Mixco Centro', 0], ['El Milagro', 3.1], ['Roosevelt', 2.6], ['El Trébol', 3.4]], 4],
            'VN1' => ['Villa Nueva', [['Villa Nueva Centro', 0], ['Bárcenas', 2.9], ['Centra Sur', 3.8]], 3],
        ];
        $lineas = [];
        $lineaEstacion = [];
        $lin = [];
        foreach ($defLineas as $codigo => [$municipio, $paradas, $numBuses]) {
            $id = $uuid();
            $lin[$codigo] = ['id' => $id, 'paradas' => $paradas, 'buses' => $numBuses];
            $lineas[] = ['id_linea' => $id, 'id_municipio' => $mun[$municipio], 'codigo_linea' => $codigo,
                'distancia_total_km' => 0, 'estado' => 'activa'];
            foreach ($paradas as $i => [$estacion, $km]) {
                $lineaEstacion[] = ['id_linea' => $id, 'id_estacion' => $est[$estacion],
                    'orden_parada' => $i + 1, 'distancia_previa_km' => $km];
            }
        }

        // ---------------- BUSES ----------------
        $buses = [];
        $busesAsignados = []; // [id_bus, codigo_linea]
        $n = 0;
        foreach ($lin as $codigo => $l) {
            for ($i = 0; $i < $l['buses']; $i++) {
                $n++;
                $id = $uuid();
                $articulado = $n % 3 === 0;
                $buses[] = ['id_bus' => $id, 'placa' => sprintf('TM-%03d', $n),
                    'capacidad_maxima' => $articulado ? 160 : 80, 'id_linea_asignada' => $l['id'],
                    'ocupacion_actual_pct' => mt_rand(5, 95), 'estado_logico' => mt_rand(0, 4) ? 'en_ruta' : 'en_espera'];
                $busesAsignados[] = [$id, $codigo];
            }
        }
        foreach (['en_mantenimiento', 'fuera_de_servicio'] as $estado) {
            $n++;
            $buses[] = ['id_bus' => $uuid(), 'placa' => sprintf('TM-%03d', $n), 'capacidad_maxima' => 80,
                'id_linea_asignada' => null, 'ocupacion_actual_pct' => 0, 'estado_logico' => $estado];
        }

        // ---------------- PARQUEOS (algunas estaciones) — cada bus con un parqueo ----------------
        $parqueos = [];
        $bahias = [];
        foreach (['Centra Sur' => 8, 'Plaza Barrios' => 6, 'Mixco Centro' => 5, 'Villa Nueva Centro' => 4] as $estacion => $cant) {
            for ($i = 1; $i <= $cant; $i++) {
                $bahias[] = ['id_parqueo' => $uuid(), 'id_estacion' => $est[$estacion],
                    'codigo_bahia' => sprintf('B-%02d', $i), 'id_bus_asignado' => null];
            }
        }
        foreach ($buses as $i => $bus) {
            $bahias[$i]['id_bus_asignado'] = $bus['id_bus'];
        }
        $parqueos = $bahias;

        // ---------------- ACCESOS Y MOLINETES ----------------
        $accesos = [];
        $molinetes = [];
        $e = 0;
        foreach ($est as $nombre => $idEstacion) {
            $e++;
            $ubicaciones = in_array($nombre, ['Centra Sur', 'Plaza Barrios'], true) ? ['Norte', 'Sur', 'Oriente'] : ['Norte', 'Sur'];
            foreach ($ubicaciones as $k => $ubicacion) {
                $idAcceso = $uuid();
                $accesos[] = ['id_acceso' => $idAcceso, 'id_estacion' => $idEstacion, 'ubicacion_fisica' => "Acceso $ubicacion"];
                $molinetes[] = ['id_molinete' => $uuid(), 'id_acceso' => $idAcceso,
                    'ip_address' => sprintf('10.10.%d.%d', $e, $k + 11), 'estado_operativo' => 'activo'];
            }
        }

        // ---------------- PERSONAL ----------------
        $empleados = $pilotos = $seguridad = $operadores = [];
        $dpi = 0;
        $nuevoEmpleado = function (string $rol) use (&$empleados, &$dpi, $uuid, $cifrar) {
            $dpi++;
            $nombre = self::NOMBRES[mt_rand(0, count(self::NOMBRES) - 1)].' '
                .self::APELLIDOS[mt_rand(0, count(self::APELLIDOS) - 1)].' '
                .self::APELLIDOS[mt_rand(0, count(self::APELLIDOS) - 1)];
            $id = $uuid();
            $empleados[] = [
                'id_empleado' => $id,
                'dpi' => sprintf('2%08d0101', $dpi),
                'nombre_completo' => $nombre,
                'fecha_nacimiento' => sprintf('%d-%02d-%02d', mt_rand(1970, 2002), mt_rand(1, 12), mt_rand(1, 28)),
                'rol' => $rol,
                'contacto_encriptado' => $cifrar(sprintf('Tel. 5%03d-%04d · %s', mt_rand(0, 999), mt_rand(0, 9999),
                    strtolower(strtr(explode(' ', $nombre)[0], ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'ñ' => 'n'])).$dpi.'@correo.gt')),
                'direccion_encriptada' => $cifrar(sprintf('%d calle %d-%02d, %s, Guatemala', mt_rand(1, 30), mt_rand(1, 20),
                    mt_rand(1, 99), self::ZONAS[mt_rand(0, count(self::ZONAS) - 1)])),
                'activo' => true,
            ];

            return $id;
        };

        foreach ($busesAsignados as $i => [$idBus]) {
            $id = $nuevoEmpleado('piloto');
            $nivel = self::NIVELES[mt_rand(0, count(self::NIVELES) - 1)];
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
        $operadorPlazaBarrios = null;
        foreach ($est as $nombre => $idEstacion) {
            $id = $nuevoEmpleado('operador');
            $operadores[] = ['id_empleado' => $id, 'nivel_estudios' => self::NIVELES[mt_rand(0, count(self::NIVELES) - 1)],
                'anios_experiencia_monitoreo' => mt_rand(1, 10), 'id_estacion_asignada' => $idEstacion];
            if ($nombre === 'Plaza Barrios') {
                $operadorPlazaBarrios = $id;
            }
        }

        // ---------------- USUARIOS (credenciales de prueba) ----------------
        $clave = $hash('Transmetro2026!');
        $users = [
            ['name' => 'Administrador Municipal', 'email' => 'admin@transmetro.test', 'email_verified_at' => $fecha($ahora),
                'password' => $clave, 'created_at' => $fecha($ahora), 'updated_at' => $fecha($ahora), 'rol' => 'administrador', 'id_empleado' => null],
            ['name' => 'Operador Plaza Barrios', 'email' => 'operador@transmetro.test', 'email_verified_at' => $fecha($ahora),
                'password' => $clave, 'created_at' => $fecha($ahora), 'updated_at' => $fecha($ahora), 'rol' => 'operador', 'id_empleado' => $operadorPlazaBarrios],
        ];

        // ---------------- TARJETAS, RECARGAS Y PAGOS ----------------
        $tarjetas = $recargas = $transacciones = [];
        for ($i = 1; $i <= 25; $i++) {
            $id = $uuid();
            $emision = $ahora - mt_rand(30, 400) * 86400;
            $totalRecargado = 0;
            for ($r = 0; $r < mt_rand(1, 3); $r++) {
                $monto = [10, 20, 25, 50][mt_rand(0, 3)];
                $totalRecargado += $monto;
                $recargas[] = ['id_recarga' => $uuid(), 'id_tarjeta' => $id, 'monto' => $monto,
                    'metodo_pago' => ['efectivo', 'credito', 'debito'][mt_rand(0, 2)], 'fecha_hora' => $fecha($emision + $r * 86400)];
            }
            $tarjetas[] = ['id_tarjeta' => $id, 'numero_tarjeta' => sprintf('TM%010d', 4000000000 + $i * 7919),
                'saldo' => $totalRecargado, 'estado' => $i % 12 === 0 ? 'bloqueada' : 'activa', 'fecha_emision' => $fecha($emision)];
        }
        for ($i = 0; $i < 120; $i++) {
            $metodo = mt_rand(1, 10) <= 7 ? 'tarjeta_transmetro' : ['efectivo', 'debito', 'credito'][mt_rand(0, 2)];
            $idTarjeta = null;
            if ($metodo === 'tarjeta_transmetro') {
                $t = mt_rand(0, count($tarjetas) - 1);
                if ($tarjetas[$t]['saldo'] < 1) {
                    $metodo = 'efectivo';
                } else {
                    $tarjetas[$t]['saldo'] -= 1;
                    $idTarjeta = $tarjetas[$t]['id_tarjeta'];
                }
            }
            $transacciones[] = ['id_transaccion' => $uuid(), 'id_molinete' => $molinetes[mt_rand(0, count($molinetes) - 1)]['id_molinete'],
                'id_tarjeta' => $idTarjeta, 'monto' => 1.00, 'metodo_pago' => $metodo,
                'fecha_hora' => $fecha($ahora - mt_rand(0, 7 * 86400))];
        }

        // ---------------- VIAJES Y VISITAS ----------------
        $viajes = $visitas = [];
        foreach ($busesAsignados as $i => [$idBus, $codigo]) {
            $inicio = strtotime('today 06:00') + $i * 600;
            $enCurso = $i % 4 === 0;
            $idViaje = $uuid();
            $paradas = $lin[$codigo]['paradas'];
            $t = $inicio;
            $ultimaSalida = $inicio;
            foreach ($paradas as $k => [$estacion, $km]) {
                if ($enCurso && $k >= 2) {
                    break;
                }
                $t += (int) round($km * 180);
                $ocupacion = mt_rand(5, 95);
                $espera = 60 + ($ocupacion < 25 ? 300 : 0);   // punto 15: +5 min si va por debajo del 25 %
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

        // ---------------- ALERTA HISTÓRICA (cerrada) ----------------
        $alertas = [[
            'id_alerta' => $uuid(), 'id_estacion' => $est['Centra Sur'], 'tipo_alerta' => 'refuerzo_bus',
            'descripcion' => 'La estacion supero el 150% de su aforo maximo (455/300). Se requiere unidad de refuerzo.',
            'estado' => 'cerrada', 'fecha_creacion' => $fecha($ahora - 2 * 86400), 'fecha_cierre' => $fecha($ahora - 2 * 86400 + 900),
        ]];

        // ---------------- BITÁCORA ----------------
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
            // Punto 14: Plaza Barrios queda saturada (160 %); el trigger crea la alerta abierta.
            'aforos' => [$est['Plaza Barrios'] => (int) (1.6 * $defEstaciones['Plaza Barrios'][1])],
        ];
    }

    public static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0F) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
