<?php

use App\Models\AlertaSistema;
use App\Models\Bus;
use App\Models\Linea;
use App\Models\Molinete;
use App\Models\Tarjeta;
use App\Models\TransaccionPago;
use App\Services\CobroPasaje;
use App\Services\ConteoPasajeros;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/*
 * Punto 18: el pasaje se cobra al SUBIR al bus, en su molinete de entrada (un pago por persona),
 * con Tarjeta Ciudadana o tarjeta bancaria sin contacto. El molinete de salida cuenta a los que bajan.
 * Con los dos: pasajeros a bordo = subidas − bajadas, y si el bus va lleno se pide otro bus.
 */

beforeEach(function () {
    $this->cobro = app(CobroPasaje::class);
    $this->conteo = app(ConteoPasajeros::class);
    $this->linea = Linea::where('codigo_linea', 'L1')->firstOrFail();
    $this->bus = Bus::where('id_linea_asignada', $this->linea->id_linea)->orderBy('placa')->firstOrFail();
    $this->estacion = $this->estacionDelOperador();
    $this->visita = $this->busEnEstacion($this->bus, $this->estacion);
    $this->molinete = $this->bus->molineteEntrada;
    $this->salida = $this->bus->molineteSalida;
    AlertaSistema::query()->update(['estado' => 'cerrada', 'fecha_cierre' => now()]);
});

test('cada bus tiene su molinete de entrada y su molinete de salida', function () {
    expect($this->molinete)->not->toBeNull()->and($this->salida)->not->toBeNull()
        ->and(Bus::whereDoesntHave('molineteEntrada')->orWhereDoesntHave('molineteSalida')->count())->toBe(0);
});

test('un bus nuevo nace con sus dos molinetes (trigger)', function () {
    $bus = Bus::create(['placa' => 'C998ZZW', 'capacidad_maxima' => 80, 'estado_logico' => 'fuera_de_servicio']);

    expect($bus->molinetes()->pluck('tipo')->sort()->values()->all())->toBe(['entrada_bus', 'salida_bus']);
});

test('cada persona que sube paga una vez: suma al bus y a la parada y deja de esperar en la estación', function () {
    $this->estacion->update(['aforo_actual' => 20]);

    $pago = $this->cobro->cobrar($this->molinete, 'tarjeta_bancaria');

    expect($pago->id_bus)->toBe($this->bus->id_bus)
        ->and($pago->id_estacion)->toBe($this->estacion->id_estacion)
        ->and($pago->id_visita)->toBe($this->visita->id_visita)
        ->and($pago->id_linea)->toBe($this->linea->id_linea)
        ->and($this->bus->refresh()->pasajeros_a_bordo)->toBe(1)
        ->and($this->visita->refresh()->subieron)->toBe(1)
        ->and($this->estacion->refresh()->aforo_actual)->toBe(19);
});

test('la ocupación del bus se calcula sola a partir de los pasajeros a bordo', function () {
    $this->bus->update(['pasajeros_a_bordo' => intdiv($this->bus->capacidad_maxima, 2)]);

    expect((float) $this->bus->refresh()->ocupacion_actual_pct)->toBe(round(intdiv($this->bus->capacidad_maxima, 2) * 100 / $this->bus->capacidad_maxima, 2));
});

test('el molinete de salida cuenta a los que bajan: a bordo = subidas − bajadas', function () {
    foreach (range(1, 5) as $i) {
        $this->cobro->cobrar($this->molinete, 'tarjeta_bancaria');
    }
    $this->conteo->registrarBajadas($this->salida, 2);

    expect($this->bus->refresh()->pasajeros_a_bordo)->toBe(3)
        ->and($this->visita->refresh()->subieron)->toBe(5)
        ->and($this->visita->bajaron)->toBe(2)
        ->and(TransaccionPago::where('id_visita', $this->visita->id_visita)->count())->toBe(5);
});

test('no pueden bajar más personas de las que van a bordo', function () {
    $this->bus->update(['pasajeros_a_bordo' => 2]);

    expect(fn () => $this->conteo->registrarBajadas($this->salida, 3))
        ->toThrow(ValidationException::class, 'van 2 pasajeros');
    expect(errorDeBD(fn () => DB::table('bajadas_bus')->insert(['id_molinete' => $this->salida->id_molinete, 'cantidad' => 3])))
        ->toContain('mas bajadas que pasajeros a bordo');
});

test('con el bus lleno el molinete no deja subir y se pide otro bus (alerta de bus lleno)', function () {
    $this->bus->update(['pasajeros_a_bordo' => $this->bus->capacidad_maxima]);
    $antes = TransaccionPago::count();

    expect(fn () => $this->cobro->cobrar($this->molinete, 'tarjeta_bancaria'))
        ->toThrow(ValidationException::class, 'va lleno');

    $alerta = AlertaSistema::abiertas()->where('tipo_alerta', 'bus_lleno')->first();
    expect(TransaccionPago::count())->toBe($antes)
        ->and($alerta)->not->toBeNull()
        ->and($alerta->id_estacion)->toBe($this->estacion->id_estacion)
        ->and($alerta->id_bus)->toBe($this->bus->id_bus)
        ->and($alerta->descripcion)->toContain($this->bus->placa);

    // La base de datos tampoco lo deja pasar si alguien inserta el pago directo.
    expect(errorDeBD(fn () => DB::table('transacciones_pago')->insert([
        'id_molinete' => $this->molinete->id_molinete, 'monto' => 1, 'metodo_pago' => 'tarjeta_bancaria',
    ])))->toContain('esta lleno');
});

test('solo se cobra en el molinete de entrada del bus (no en la estación ni en la salida)', function () {
    $deEstacion = Molinete::where('tipo', 'estacion')->firstOrFail();

    expect(fn () => $this->cobro->cobrar($deEstacion, 'tarjeta_bancaria'))->toThrow(ValidationException::class, 'molinete de entrada')
        ->and(fn () => $this->cobro->cobrar($this->salida, 'tarjeta_bancaria'))->toThrow(ValidationException::class, 'molinete de entrada');
    expect(errorDeBD(fn () => DB::table('transacciones_pago')->insert([
        'id_molinete' => $deEstacion->id_molinete, 'monto' => 1, 'metodo_pago' => 'tarjeta_bancaria',
    ])))->toContain('molinete de entrada del bus');
});

test('un bus sin viaje en curso no puede cobrar', function () {
    DB::table('viajes')->where('id_bus', $this->bus->id_bus)->update(['estado' => 'finalizado', 'fecha_hora_fin' => now()]);

    expect(fn () => $this->cobro->cobrar($this->molinete, 'tarjeta_bancaria'))
        ->toThrow(ValidationException::class, 'no está en una estación');
});

test('los 5 viajes gratis se usan antes que el saldo', function () {
    $tarjeta = tarjetaDePrueba(['viajes_gratis_restantes' => 5, 'saldo' => 10]);

    foreach (range(1, 5) as $viaje) {
        $pago = $this->cobro->cobrar($this->molinete, 'tarjeta_ciudadana', $tarjeta->numero_tarjeta);
        expect($pago->viaje_gratis)->toBeTrue()->and((float) $pago->monto)->toBe(0.0);
    }

    $tarjeta->refresh();
    expect($tarjeta->viajes_gratis_restantes)->toBe(0)
        ->and((float) $tarjeta->saldo)->toBe(10.0);

    // El sexto viaje ya se descuenta del saldo con la tarifa de la línea del bus.
    $pago = $this->cobro->cobrar($this->molinete, 'tarjeta_ciudadana', $tarjeta->numero_tarjeta);

    expect($pago->viaje_gratis)->toBeFalse()
        ->and((float) $pago->monto)->toBe((float) $this->linea->tarifa)
        ->and((float) $tarjeta->refresh()->saldo)->toBe(10 - (float) $this->linea->tarifa);
});

test('el número de tarjeta se acepta como va impreso (00 0040 0001) o como lo da el código QR', function () {
    $tarjeta = tarjetaDePrueba(['saldo' => 5]);

    $pago = $this->cobro->cobrar($this->molinete, 'tarjeta_ciudadana', ' '.Tarjeta::formatearNumero($tarjeta->numero_tarjeta).' ');

    expect($pago->id_tarjeta)->toBe($tarjeta->id_tarjeta);
});

test('sin saldo suficiente no se cobra, no se registra el pase y nadie sube', function () {
    $tarjeta = tarjetaDePrueba(['saldo' => 0.50]);
    $pagosAntes = $tarjeta->transacciones()->count();

    expect(fn () => $this->cobro->cobrar($this->molinete, 'tarjeta_ciudadana', $tarjeta->numero_tarjeta))
        ->toThrow(ValidationException::class, 'Saldo insuficiente');

    expect((float) $tarjeta->refresh()->saldo)->toBe(0.5)
        ->and($tarjeta->transacciones()->count())->toBe($pagosAntes)
        ->and($this->bus->refresh()->pasajeros_a_bordo)->toBe(0);
});

test('una tarjeta bloqueada o dada de baja es rechazada en el molinete', function (string $estado, string $texto) {
    $tarjeta = tarjetaDePrueba(['saldo' => 50, 'estado' => $estado]);

    expect(fn () => $this->cobro->cobrar($this->molinete, 'tarjeta_ciudadana', $tarjeta->numero_tarjeta))
        ->toThrow(ValidationException::class, 'La tarjeta está '.$texto)
        ->and(errorDeBD(fn () => TransaccionPago::create(['id_molinete' => $this->molinete->id_molinete, 'id_linea' => $this->linea->id_linea,
            'id_tarjeta' => $tarjeta->id_tarjeta, 'metodo_pago' => 'tarjeta_ciudadana', 'monto' => 1, 'fecha_hora' => now()])))
        ->toContain('no está activa');
})->with([['bloqueada', 'bloqueada'], ['baja', 'dada de baja']]);

test('una tarjeta que no existe es rechazada', function () {
    expect(fn () => $this->cobro->cobrar($this->molinete, 'tarjeta_ciudadana', 'NOEXISTE123'))
        ->toThrow(ValidationException::class, 'No existe una tarjeta');
});

test('con tarjeta bancaria se cobra la tarifa sin usar Tarjeta Ciudadana', function () {
    $pago = $this->cobro->cobrar($this->molinete, 'tarjeta_bancaria');

    expect($pago->id_tarjeta)->toBeNull()
        ->and($pago->metodo_pago)->toBe('tarjeta_bancaria')
        ->and((float) $pago->monto)->toBe((float) $this->linea->tarifa);
});

test('no se cobra en un molinete fuera de servicio (salvo el registro manual del operador)', function () {
    $this->molinete->update(['estado_operativo' => 'mantenimiento']);

    expect(fn () => $this->cobro->cobrar($this->molinete, 'tarjeta_bancaria'))
        ->toThrow(ValidationException::class, 'no está activo');
    expect($this->cobro->cobrar($this->molinete, 'tarjeta_bancaria', manual: true)->id_bus)->toBe($this->bus->id_bus);
});

test('no se cobra en una línea inactiva', function () {
    $this->linea->update(['estado' => 'inactiva']);

    expect(fn () => $this->cobro->cobrar($this->molinete, 'tarjeta_bancaria'))
        ->toThrow(ValidationException::class, 'no está activa');
});

test('los datos de prueba cuadran: lo cobrado y contado en cada parada es lo que va en cada bus', function () {
    $malos = DB::selectOne(<<<'SQL'
        SELECT (SELECT count(*) FROM visitas_viaje vv
                 WHERE vv.subieron <> (SELECT count(*) FROM transacciones_pago t WHERE t.id_visita = vv.id_visita)
                    OR vv.bajaron <> (SELECT coalesce(sum(cantidad), 0) FROM bajadas_bus b WHERE b.id_visita = vv.id_visita)) AS visitas,
               (SELECT count(*) FROM buses b
                 WHERE b.id_bus <> ? AND b.pasajeros_a_bordo <> coalesce((SELECT sum(vv.subieron - vv.bajaron) FROM viajes v
                         JOIN visitas_viaje vv ON vv.id_viaje = v.id_viaje WHERE v.id_bus = b.id_bus AND v.estado = 'en_curso'), 0)) AS buses
        SQL, [$this->bus->id_bus]);

    expect((int) $malos->visitas)->toBe(0)->and((int) $malos->buses)->toBe(0);
});

test('adulto mayor: 4 viajes de cortesía cada día; el quinto se cobra del saldo', function () {
    $tarjeta = tarjetaDePrueba(['tipo_tarjeta' => 'adulto_mayor', 'afiliada' => true, 'nombre_titular' => 'Rosa Pérez',
        'dpi_titular' => '1234567890101', 'fecha_nacimiento_titular' => now()->subYears(70), 'costo_emision' => 0, 'saldo' => 10]);

    foreach (range(1, Tarjeta::CORTESIA_DIARIA_ADULTO_MAYOR) as $viaje) {
        expect($this->cobro->cobrar($this->molinete, 'tarjeta_ciudadana', $tarjeta->numero_tarjeta)->viaje_gratis)->toBeTrue();
    }
    $quinto = $this->cobro->cobrar($this->molinete, 'tarjeta_ciudadana', $tarjeta->numero_tarjeta);

    expect($quinto->viaje_gratis)->toBeFalse()
        ->and((float) $tarjeta->refresh()->saldo)->toBe(10 - (float) $this->linea->tarifa)
        ->and($tarjeta->cortesiasRestantesHoy())->toBe(0);

    // Las cortesías no se acumulan: las de ayer no cuentan para hoy.
    TransaccionPago::where('id_tarjeta', $tarjeta->id_tarjeta)->update(['fecha_hora' => now()->subDay()]);
    expect($tarjeta->cortesiasRestantesHoy())->toBe(Tarjeta::CORTESIA_DIARIA_ADULTO_MAYOR);
});
