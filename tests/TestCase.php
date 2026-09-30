<?php

namespace Tests;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
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
                .'Esto evita borrar los datos reales del esquema public.'
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
        return User::where('email', 'admin@transmetro.test')->firstOrFail();
    }

    /** Operador asignado a la estación San Sebastián (según los datos de prueba). */
    protected function operador(): User
    {
        return User::where('email', 'operador@transmetro.test')->firstOrFail();
    }
}
