<?php

use App\Models\Acceso;
use App\Models\AlertaSistema;
use App\Models\Bus;
use App\Models\Estacion;
use App\Models\Linea;
use App\Models\Municipio;
use App\Models\Parqueo;
use App\Models\Recarga;
use App\Models\Seguridad;
use Illuminate\Support\Facades\DB;

/*
 * Reglas de negocio que cumple la propia base de datos (triggers, procedimiento y CHECKs).
 */

/** Estación nueva, sin pagos ni guardias, con la cantidad de accesos indicada. */
function estacionConAccesos(int $cantidad): Estacion
{
    $estacion = Estacion::create([
        'id_municipio' => Municipio::value('id_municipio'),
        'nombre' => 'Estación de prueba '.$cantidad,
        'aforo_maximo' => 100,
        'aforo_actual' => 0,
        'latitud' => 14.62,
        'longitud' => -90.52,
    ]);
    foreach (range(1, $cantidad) as $n) {
        Acceso::create(['id_estacion' => $estacion->id_estacion, 'ubicacion_fisica' => "Acceso {$n}"]);
    }

    return $estacion;
}

test('punto 3: una estación no puede quedarse con menos de 2 accesos', function () {
    $estacion = estacionConAccesos(2);

    expect(errorDeBD(fn () => $estacion->accesos()->first()->delete()))->toContain('al menos 2 accesos');
    expect($estacion->accesos()->count())->toBe(2);
});

test('punto 3: una estación con 3 accesos sí puede eliminar uno', function () {
    $estacion = estacionConAccesos(3);

    $estacion->accesos()->first()->delete();

    expect($estacion->accesos()->count())->toBe(2);
});

test('punto 10: un acceso no puede quedarse sin personal de seguridad', function () {
    // Busca un acceso que tenga un solo guardia.
    $idAcceso = Seguridad::select('id_acceso_asignado')->groupBy('id_acceso_asignado')
        ->havingRaw('COUNT(*) = 1')->value('id_acceso_asignado');
    $guardia = Seguridad::where('id_acceso_asignado', $idAcceso)->firstOrFail();
    $otroAcceso = Acceso::where('id_acceso', '!=', $idAcceso)->firstOrFail();

    expect(errorDeBD(fn () => $guardia->update(['id_acceso_asignado' => $otroAcceso->id_acceso])))
        ->toContain('sin personal de seguridad');
    expect(errorDeBD(fn () => $guardia->delete()))
        ->toContain('sin personal de seguridad');
});

test('punto 5: una línea no puede tener más del doble de buses que de estaciones', function () {
    $linea = Linea::where('codigo_linea', 'L1')->firstOrFail();
    $maximo = $linea->maximoBuses();
    $actuales = Bus::where('id_linea_asignada', $linea->id_linea)->count();

    // Llena la línea hasta el máximo permitido.
    foreach (range(1, $maximo - $actuales) as $n) {
        Bus::create(['placa' => sprintf('PR-%03d', $n), 'capacidad_maxima' => 80, 'id_linea_asignada' => $linea->id_linea,
            'ocupacion_actual_pct' => 0, 'estado_logico' => 'en_espera']);
    }

    // El siguiente bus ya no cabe.
    expect(errorDeBD(fn () => Bus::create(['placa' => 'PR-999', 'capacidad_maxima' => 80,
        'id_linea_asignada' => $linea->id_linea, 'ocupacion_actual_pct' => 0, 'estado_logico' => 'en_espera'])))
        ->toContain('maximo permitido');

    expect(Bus::where('id_linea_asignada', $linea->id_linea)->count())->toBe($maximo);
});

test('punto 14: al pasar del 150 % del aforo se crea una alerta de refuerzo', function () {
    $estacion = Estacion::where('nombre', 'Centro Cívico')->firstOrFail();
    $estacion->update(['aforo_actual' => 0]);
    $antes = AlertaSistema::where('id_estacion', $estacion->id_estacion)->count();

    $estacion->update(['aforo_actual' => (int) ceil($estacion->aforo_maximo * 1.4)]);
    expect(AlertaSistema::where('id_estacion', $estacion->id_estacion)->count())->toBe($antes);

    $estacion->update(['aforo_actual' => (int) ceil($estacion->aforo_maximo * 1.5)]);
    $alerta = AlertaSistema::where('id_estacion', $estacion->id_estacion)->latest('fecha_creacion')->first();

    expect(AlertaSistema::where('id_estacion', $estacion->id_estacion)->count())->toBe($antes + 1)
        ->and($alerta->tipo_alerta)->toBe('refuerzo_bus')
        ->and($alerta->estado)->toBe('abierta');
});

test('punto 14: si la estación sigue saturada no se repite la alerta', function () {
    $estacion = Estacion::where('nombre', 'San Sebastián')->firstOrFail();
    expect($estacion->requiereRefuerzo())->toBeTrue();
    $antes = AlertaSistema::where('id_estacion', $estacion->id_estacion)->count();

    $estacion->update(['aforo_actual' => $estacion->aforo_actual + 10]);

    expect(AlertaSistema::where('id_estacion', $estacion->id_estacion)->count())->toBe($antes);
});

test('punto 13: la distancia total de la línea es la suma de sus tramos', function () {
    $linea = Linea::where('codigo_linea', 'L2')->firstOrFail();
    $suma = (float) DB::table('linea_estacion')->where('id_linea', $linea->id_linea)->sum('distancia_previa_km');

    expect((float) $linea->distancia_total_km)->toEqualWithDelta($suma, 0.001);

    // Si cambia un tramo, el total se recalcula solo.
    $tramo = DB::table('linea_estacion')->where('id_linea', $linea->id_linea)->orderBy('orden_parada')->skip(1)->first();
    DB::table('linea_estacion')->where('id_linea', $linea->id_linea)->where('id_estacion', $tramo->id_estacion)
        ->update(['distancia_previa_km' => $tramo->distancia_previa_km + 1.5]);

    expect((float) $linea->refresh()->distancia_total_km)->toEqualWithDelta($suma + 1.5, 0.001);
});

test('punto 7: el bus cambia de parqueo sin quedarse sin parqueo', function () {
    $parqueoActual = Parqueo::whereNotNull('id_bus_asignado')->firstOrFail();
    $libre = Parqueo::whereNull('id_bus_asignado')->firstOrFail();
    $bus = Bus::findOrFail($parqueoActual->id_bus_asignado);

    $bus->cambiarParqueo($libre->id_parqueo);

    expect($libre->refresh()->id_bus_asignado)->toBe($bus->id_bus)
        ->and($parqueoActual->refresh()->id_bus_asignado)->toBeNull()
        ->and(Parqueo::where('id_bus_asignado', $bus->id_bus)->count())->toBe(1);
});

test('punto 7: no se puede mover un bus a un parqueo ocupado', function () {
    [$uno, $dos] = Parqueo::whereNotNull('id_bus_asignado')->limit(2)->get();
    $bus = Bus::findOrFail($uno->id_bus_asignado);

    expect(errorDeBD(fn () => $bus->cambiarParqueo($dos->id_parqueo)))->toContain('ya esta ocupado');
    expect($uno->refresh()->id_bus_asignado)->toBe($bus->id_bus);
});

test('punto 18: las recargas solo pueden ser de Q5 a Q200', function (float $monto) {
    $tarjeta = tarjetaDePrueba();

    expect(errorDeBD(fn () => Recarga::create(['id_tarjeta' => $tarjeta->id_tarjeta, 'monto' => $monto, 'metodo_pago' => 'efectivo'])))
        ->toContain('chk_recargas_monto');
})->with([4.99, 200.01]);

test('punto 18: una tarjeta de adulto mayor debe tener titular y DPI', function () {
    expect(errorDeBD(fn () => tarjetaDePrueba(['tipo_tarjeta' => 'adulto_mayor'])))
        ->toContain('chk_tarjetas_titular');
});
