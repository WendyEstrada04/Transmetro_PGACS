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
        'bitacora_auditoria', 'visitas_viaje', 'viajes', 'transacciones_pago', 'recargas', 'tarjetas',
        'alertas_sistema', 'operadores', 'seguridad', 'pilotos', 'users', 'empleados', 'parqueos',
        'molinetes', 'accesos', 'buses', 'linea_estacion', 'lineas', 'estaciones', 'municipios',
    ];

    public function run(): void
    {
        $datos = TransmetroDatos::generar(
            fn (string $valor) => Crypt::encryptString($valor),
            fn (string $valor) => Hash::make($valor),
        );

        DB::transaction(function () use ($datos) {
            DB::statement('TRUNCATE TABLE '.implode(', ', self::TABLAS).' RESTART IDENTITY CASCADE');

            foreach ($datos['tablas'] as $tabla => $filas) {
                foreach (array_chunk($filas, 100) as $lote) {
                    DB::table($tabla)->insert($lote);
                }
                $this->command?->info(sprintf('  %-20s %d registros', $tabla, count($filas)));
            }

            foreach ($datos['aforos'] as $idEstacion => $aforo) {
                DB::table('estaciones')->where('id_estacion', $idEstacion)->update(['aforo_actual' => $aforo]);
            }
        });
    }
}
