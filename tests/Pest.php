<?php

use App\Models\Tarjeta;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

// TestCase ya usa RefreshDatabase con el esquema "pruebas" (ver tests/TestCase.php).
pest()->extend(TestCase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

// (sin expectativas personalizadas por ahora)

/*
|--------------------------------------------------------------------------
| Funciones de apoyo
|--------------------------------------------------------------------------
*/

/** Crea una Tarjeta Ciudadana de prueba con los valores indicados. */
function tarjetaDePrueba(array $datos = []): Tarjeta
{
    return Tarjeta::create(array_merge([
        'numero_tarjeta' => 'TCPRUEBA'.random_int(100000, 999999),
        'tipo_tarjeta' => 'ciudadana',
        'costo_emision' => Tarjeta::COSTO_EMISION,
        'viajes_gratis_restantes' => 0,
        'saldo' => 0,
        'estado' => 'activa',
    ], $datos))->refresh();
}

/** Ejecuta una operación que la base de datos debe rechazar y devuelve el mensaje del error. */
function errorDeBD(callable $operacion): string
{
    try {
        // La transacción interna (savepoint) permite seguir usando la conexión después del error.
        DB::transaction($operacion);
    } catch (QueryException $e) {
        return $e->getMessage();
    }

    throw new RuntimeException('Se esperaba que la base de datos rechazara la operación.');
}
