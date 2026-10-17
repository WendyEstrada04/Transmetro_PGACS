<?php

namespace Database\Seeders;

use Database\Seeders\Datos\TransmetroDatos;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Carga los datos de prueba del Transmetro.
 * ATENCIÓN: primero BORRA los datos de las tablas del sistema y de usuarios.
 * Uso: php artisan db:seed
 */
class TransmetroSeeder extends Seeder
{
    private const TABLAS = [
        'bitacora_auditoria', 'bajadas_bus', 'visitas_viaje', 'viajes', 'transacciones_pago', 'recargas', 'tarjetas',
        'alertas_sistema', 'horarios_linea', 'mantenimientos_bus', 'operadores', 'seguridad', 'pilotos', 'users', 'empleados', 'parqueos',
        'molinetes', 'accesos', 'buses', 'linea_estacion', 'lineas', 'estaciones', 'municipios',
    ];

    /** [tabla, trigger]: los buses se cargan antes que sus pilotos, por eso se pausa también la regla de piloto. */
    private const TRIGGERS_PAUSADOS = [
        ['buses', 'trg_buses_molinetes'],
        ['buses', 'trg_buses_ruta_con_piloto'],
        ['transacciones_pago', 'trg_transacciones_suben'],
        ['bajadas_bus', 'trg_bajadas_restan'],
        ['transacciones_pago', 'trg_transacciones_tarjeta_activa'],
        ['recargas', 'trg_recargas_tarjeta_activa'],
    ];

    public function run(): void
    {
        // Los datos de un día completo son muchas filas: PHP trae 128 MB por defecto en la terminal y no alcanzan.
        if (($limite = ini_get('memory_limit')) !== '-1' && (int) $limite < 768) {
            ini_set('memory_limit', '768M');
        }

        $datos = TransmetroDatos::generar(
            fn (string $valor) => Crypt::encryptString($valor),
            fn (string $valor) => Hash::make($valor),
            escala: (float) (getenv('TRANSMETRO_ESCALA') ?: 1),
            respetarHorarios: (bool) config('transmetro.respetar_horarios', true),
        );

        DB::transaction(function () use ($datos) {
            DB::statement('TRUNCATE TABLE '.implode(', ', self::TABLAS).' RESTART IDENTITY CASCADE');

            // Los datos ya traen los molinetes de cada bus y los pasajeros contados en cada parada:
            // mientras se cargan, los triggers que los crean y los suman no se ejecutan (se reactivan al final).
            foreach (self::TRIGGERS_PAUSADOS as [$tabla, $trigger]) {
                DB::statement("ALTER TABLE {$tabla} DISABLE TRIGGER {$trigger}");
            }

            foreach (array_keys($datos['tablas']) as $tabla) {
                $filas = $datos['tablas'][$tabla];
                unset($datos['tablas'][$tabla]);   // libera memoria a medida que se inserta
                foreach (array_chunk($filas, in_array($tabla, ['transacciones_pago', 'bajadas_bus', 'visitas_viaje'], true) ? 1000 : 100) as $lote) {
                    DB::table($tabla)->insert($lote);
                }
                $this->command?->info(sprintf('  %-20s %d registros', $tabla, count($filas)));
                unset($filas);
            }

            foreach (self::TRIGGERS_PAUSADOS as [$tabla, $trigger]) {
                DB::statement("ALTER TABLE {$tabla} ENABLE TRIGGER {$trigger}");
            }

            foreach ($datos['aforos'] as $idEstacion => $aforo) {
                DB::table('estaciones')->where('id_estacion', $idEstacion)->update(['aforo_actual' => $aforo]);
            }
        });
    }
}
