<?php

namespace Tests;

use App\Models\Bus;
use App\Models\Estacion;
use App\Models\User;
use App\Models\Viaje;
use App\Models\VisitaViaje;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\Datos\TransmetroDatos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Base de todas las pruebas.
 *
 * Las pruebas usan PostgreSQL (los triggers y el procedimiento almacenado no existen en SQLite)
 * dentro de un ESQUEMA SEPARADO llamado "pruebas" (variable DB_SCHEMA en phpunit.xml).
 * Así nunca se tocan los datos reales del esquema "public" de Supabase.
 * Se migra y se cargan los datos de prueba una sola vez por corrida; cada prueba corre
 * dentro de una transacción que se revierte al terminar (RefreshDatabase).
 */
abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /** Carga los datos del TransmetroSeeder una vez, después de migrar. */
    protected bool $seed = true;

    protected string $seeder = DatabaseSeeder::class;

    /** Candado de seguridad + crea el esquema de pruebas si aún no existe. */
    protected function beforeRefreshingDatabase()
    {
        $conexion = config('database.default');
        $esquema = config("database.connections.{$conexion}.search_path");

        if ($conexion !== 'pgsql' || $esquema !== 'pruebas') {
            throw new RuntimeException(
                "Las pruebas deben correr en PostgreSQL con DB_SCHEMA=pruebas (actual: {$conexion} / {$esquema}). "
                .'Esto evita borrar los datos reales del esquema public. Si abriste el sistema con iniciar.bat, corre antes '
                .'"php artisan optimize:clear" (o usa probar.bat).'
            );
        }

        DB::statement('CREATE SCHEMA IF NOT EXISTS pruebas');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    protected function administrador(): User
    {
        return User::where('email', 'admin@gmail.com')->firstOrFail();
    }

    /** Operador del primer bus de la Línea 1 (que pasa por San Sebastián) según los datos de prueba. */
    protected function operador(): User
    {
        return User::where('email', 'operador@gmail.com')->firstOrFail();
    }

    /**
     * Deja un bus detenido en una estación de su línea con un viaje en curso (así sus molinetes pueden
     * cobrar y contar) y con los pasajeros a bordo indicados. Devuelve la visita de esa parada.
     */
    protected function busEnEstacion(Bus $bus, ?Estacion $estacion = null, int $aBordo = 0): VisitaViaje
    {
        $estacion ??= $bus->linea->estaciones()->firstOrFail();
        Viaje::where('id_bus', $bus->id_bus)->where('estado', 'en_curso')->update(['estado' => 'finalizado', 'fecha_hora_fin' => now()]);
        $viaje = Viaje::create(['id_bus' => $bus->id_bus, 'id_linea' => $bus->id_linea_asignada,
            'fecha_hora_inicio' => now()->subMinutes(5), 'estado' => 'en_curso']);
        $bus->update(['estado_logico' => 'en_ruta', 'pasajeros_a_bordo' => $aBordo]);

        return $viaje->visitas()->create([
            'id_estacion' => $estacion->id_estacion, 'orden_visita' => 1,
            'hora_llegada' => now()->subMinute(), 'hora_salida' => now()->addMinute(),
            'ocupacion_al_llegar_pct' => round($aBordo * 100 / $bus->capacidad_maxima, 2),
        ]);
    }

    /** Un bus que pasa por la estación del operador de prueba: el primero de la Línea 1 (placa de Guatemala, p. ej. C100JVX). */
    protected function busDelOperador(): Bus
    {
        return Bus::where('placa', TransmetroDatos::placa(1))->firstOrFail();
    }

    /** San Sebastián (L1 y L2): la estación donde trabaja el operador de prueba. */
    protected function estacionDelOperador(): Estacion
    {
        return Estacion::where('nombre', 'San Sebastián')->firstOrFail();
    }
}
