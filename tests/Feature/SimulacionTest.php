<?php

use App\Models\AlertaSistema;
use App\Models\BitacoraAuditoria;
use App\Models\Bus;
use App\Models\Estacion;
use App\Models\Linea;
use App\Models\TransaccionPago;
use App\Models\Viaje;
use App\Models\VisitaViaje;
use App\Services\ConteoPasajeros;
use App\Services\SimuladorOperacion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
 * Simulación de la operación: pasajeros, buses, alertas (punto 14), espera extra (punto 15)
 * y envío automático de buses de refuerzo.
 */

beforeEach(function () {
    $this->simulador = app(SimuladorOperacion::class);
    $this->estacion = Estacion::where('nombre', 'San Sebastián')->firstOrFail();
    AlertaSistema::query()->update(['estado' => 'cerrada', 'fecha_cierre' => now()]);
});

/** Deja un único bus en reserva en las líneas que pasan por la estación. */
function busDeReserva(Estacion $estacion): Bus
{
    $deLaEstacion = Bus::whereIn('id_linea_asignada', $estacion->lineas->pluck('id_linea'))
        ->whereDoesntHave('viajes', fn ($q) => $q->where('estado', 'en_curso'));
    (clone $deLaEstacion)->where('estado_logico', 'en_espera')->update(['estado_logico' => 'en_ruta']);
    $bus = $deLaEstacion->orderBy('placa')->firstOrFail();
    $bus->update(['estado_logico' => 'en_espera']);

    return $bus;
}

test('cada persona que sube al bus deja de esperar en la estación', function () {
    $estacion = $this->estacionDelOperador();
    $bus = $this->busDelOperador();
    $this->busEnEstacion($bus, $estacion);
    $estacion->update(['aforo_actual' => 10]);

    $this->actingAs($this->operador())->post('/operador/pases', [
        'id_bus' => $bus->id_bus,
        'metodo_pago' => 'tarjeta_bancaria',
        'motivo' => 'sistema_caido',
    ])->assertSessionHas('estado');

    expect($estacion->refresh()->aforo_actual)->toBe(9);
});

test('la hora pico lleva la estación arriba del 150 % y la base crea la alerta', function () {
    $this->estacion->update(['aforo_actual' => 50]);

    $this->simulador->horaPico($this->estacion);

    expect($this->estacion->refresh()->requiereRefuerzo())->toBeTrue()
        ->and(AlertaSistema::abiertas()->where('id_estacion', $this->estacion->id_estacion)->count())->toBe(1);
});

test('en el ciclo siguiente a la alerta llega un bus de refuerzo y la alerta queda atendida', function () {
    $bus = busDeReserva($this->estacion);
    $this->estacion->update(['aforo_actual' => 50]);
    $this->simulador->horaPico($this->estacion);
    $alerta = AlertaSistema::abiertas()->where('id_estacion', $this->estacion->id_estacion)->firstOrFail();

    $this->simulador->ciclo();   // el centro de control ve la alerta
    expect($alerta->refresh()->estado)->toBe('abierta');

    $this->simulador->ciclo();   // y envía el refuerzo

    $registro = BitacoraAuditoria::where('id_registro_afectado', $alerta->id_alerta)->first();
    $bus = Bus::where('placa', $registro?->datos_nuevos['bus_refuerzo'] ?? $bus->placa)->firstOrFail();

    expect($alerta->refresh()->estado)->toBe('atendida')
        ->and($alerta->fecha_cierre)->not->toBeNull()
        ->and($registro)->not->toBeNull()
        ->and($bus->estado_logico)->toBe('en_ruta')
        ->and(Viaje::where('id_bus', $bus->id_bus)->where('estado', 'en_curso')->exists())->toBeTrue()
        ->and(VisitaViaje::where('id_estacion', $this->estacion->id_estacion)
            ->whereHas('viaje', fn ($q) => $q->where('id_bus', $bus->id_bus))->exists())->toBeTrue()
        ->and(collect($this->simulador->eventos())->pluck('tipo'))->toContain('alerta', 'refuerzo');
});

test('si no hay buses disponibles la alerta sigue abierta', function () {
    Bus::whereIn('id_linea_asignada', $this->estacion->lineas->pluck('id_linea'))
        ->whereIn('estado_logico', ['en_espera', 'en_ruta'])->update(['estado_logico' => 'en_mantenimiento']);
    $this->simulador->horaPico($this->estacion);
    $alerta = AlertaSistema::abiertas()->where('id_estacion', $this->estacion->id_estacion)->firstOrFail();

    expect($this->simulador->despacharRefuerzo($alerta))->toBeNull()
        ->and($alerta->refresh()->estado)->toBe('abierta');
});

test('los buses en ruta avanzan a su siguiente estación y guardan la visita (punto 1)', function () {
    $antes = VisitaViaje::count();

    $this->simulador->moverBuses();

    expect(VisitaViaje::count())->toBeGreaterThan($antes)
        ->and(VisitaViaje::count() - $antes)->toBeLessThanOrEqual(SimuladorOperacion::BUSES_POR_CICLO);
});

test('un bus por debajo del 25 % espera 5 minutos extra en la estación (punto 15)', function () {
    $viaje = Viaje::where('estado', 'en_curso')->with('bus')->firstOrFail();
    $viaje->bus->update(['pasajeros_a_bordo' => 0]);
    $parada = DB::table('linea_estacion')->where('id_linea', $viaje->id_linea)->orderBy('orden_parada')->first();
    Estacion::whereKey($parada->id_estacion)->update(['aforo_actual' => 0]);

    $resultado = $this->simulador->detenerBus($viaje, $parada->id_estacion, 99);
    $visita = VisitaViaje::where('id_viaje', $viaje->id_viaje)->where('orden_visita', 99)->firstOrFail();

    expect($resultado['espera_extra'])->toBeTrue()
        ->and($visita->hora_llegada->diffInMinutes($visita->hora_salida))->toEqual(6);
});

test('un bus de refuerzo con espacio recoge a todos y cobra a cada uno en su molinete de entrada', function () {
    $viaje = Viaje::where('estado', 'en_curso')->with('bus')->firstOrFail();
    $viaje->bus->update(['pasajeros_a_bordo' => 0]);
    $parada = DB::table('linea_estacion')->where('id_linea', $viaje->id_linea)->orderBy('orden_parada')->first();
    Estacion::whereKey($parada->id_estacion)->update(['aforo_actual' => 30]);

    $resultado = $this->simulador->detenerBus($viaje, $parada->id_estacion, 99, refuerzo: true);
    $visita = VisitaViaje::where('id_viaje', $viaje->id_viaje)->where('orden_visita', 99)->firstOrFail();

    expect($resultado['subieron'])->toBe(30)
        ->and(Estacion::find($parada->id_estacion)->aforo_actual)->toBe(0)
        ->and($viaje->bus->refresh()->pasajeros_a_bordo)->toBe(30)
        ->and($visita->subieron)->toBe(30)
        ->and(TransaccionPago::where('id_visita', $visita->id_visita)->where('id_bus', $viaje->id_bus)->count())->toBe(30);
});

test('en cada parada bajan por el molinete de salida y suben pagando en el de entrada; todo cuadra', function () {
    $antes = VisitaViaje::max('hora_llegada');

    $this->simulador->moverBuses();

    $nuevas = VisitaViaje::where('hora_llegada', '>', $antes)->get();
    expect($nuevas)->not->toBeEmpty()
        ->and($nuevas->sum('subieron'))->toBe(TransaccionPago::whereIn('id_visita', $nuevas->pluck('id_visita'))->count())
        ->and($nuevas->sum('bajaron'))->toBe((int) DB::table('bajadas_bus')->whereIn('id_visita', $nuevas->pluck('id_visita'))->sum('cantidad'));

    $descuadrados = DB::selectOne(<<<'SQL'
        SELECT count(*) AS n FROM buses b
         WHERE b.pasajeros_a_bordo <> coalesce((SELECT sum(vv.subieron - vv.bajaron) FROM viajes v
                 JOIN visitas_viaje vv ON vv.id_viaje = v.id_viaje WHERE v.id_bus = b.id_bus AND v.estado = 'en_curso'), 0)
        SQL);
    expect((int) $descuadrados->n)->toBe(0);
});

test('en la última estación del recorrido bajan todos y nadie sube', function () {
    $p = SimuladorOperacion::repartirParada(50, 80, 100, ultimaParada: true);

    expect($p)->toBe(['bajaron' => 50, 'subieron' => 0, 'quedaron' => 0, 'a_bordo' => 0]);
});

test('un bus lleno con gente esperando crea la alerta de bus lleno y llega otro bus de la misma línea', function () {
    $viaje = Viaje::where('estado', 'en_curso')->with('bus.linea')->whereHas('bus', fn ($q) => $q->whereNotNull('id_linea_asignada'))->firstOrFail();
    $bus = $viaje->bus;
    $bus->update(['pasajeros_a_bordo' => $bus->capacidad_maxima]);
    $parada = DB::table('linea_estacion')->where('id_linea', $viaje->id_linea)->orderBy('orden_parada')->first();
    Estacion::whereKey($parada->id_estacion)->update(['aforo_actual' => 50 * $bus->capacidad_maxima]);

    $resultado = $this->simulador->detenerBus($viaje, $parada->id_estacion, 99);
    $alerta = AlertaSistema::abiertas()->where('tipo_alerta', 'bus_lleno')->where('id_estacion', $parada->id_estacion)->firstOrFail();

    expect($resultado['quedaron'])->toBeGreaterThan(0)
        ->and($bus->refresh()->lleno())->toBeTrue()
        ->and($alerta->id_bus)->toBe($bus->id_bus);

    // Solo un bus de reserva en la línea del bus lleno: es el que se envía.
    $reserva = Bus::where('id_linea_asignada', $viaje->id_linea)->where('id_bus', '!=', $bus->id_bus)
        ->whereDoesntHave('viajes', fn ($q) => $q->where('estado', 'en_curso'))->orderBy('placa')->first()
        ?? tap(Bus::where('id_linea_asignada', $viaje->id_linea)->where('id_bus', '!=', $bus->id_bus)->orderBy('placa')->firstOrFail(),
            fn ($b) => Viaje::where('id_bus', $b->id_bus)->update(['estado' => 'finalizado', 'fecha_hora_fin' => now()]));
    $reserva->update(['estado_logico' => 'en_espera', 'pasajeros_a_bordo' => 0]);

    expect($this->simulador->despacharRefuerzo($alerta->load('bus')))->toBe($reserva->placa)
        ->and($alerta->refresh()->estado)->toBe('atendida');
});

test('si nadie atiende la alerta de bus lleno, la central manda el bus después de un momento', function () {
    $estacion = $this->estacionDelOperador();
    $bus = busDeReserva($estacion);
    $alerta = AlertaSistema::create(app(ConteoPasajeros::class)->filaAlerta($this->busDelOperador(), $estacion->id_estacion, $estacion->nombre, 20, now()));

    $this->simulador->ciclo();
    $this->simulador->ciclo();
    expect($alerta->refresh()->estado)->toBe('abierta');   // el operador todavía tiene tiempo

    $alerta->update(['fecha_creacion' => now()->subSeconds(SimuladorOperacion::SEGUNDOS_ESPERA_BUS_LLENO + 5)]);
    $this->simulador->ciclo();
    expect($alerta->refresh()->estado)->toBe('atendida');
});

test('el operador envía un bus de refuerzo al atender la alerta', function () {
    $usuario = $this->operador();
    $estacion = $this->estacionDelOperador();
    $bus = busDeReserva($estacion);
    $this->simulador->horaPico($estacion);
    $alerta = AlertaSistema::abiertas()->where('id_estacion', $estacion->id_estacion)->firstOrFail();

    $this->actingAs($usuario)->post("/operador/alertas/{$alerta->id_alerta}/atender")
        ->assertSessionHas('estado', fn ($texto) => str_contains($texto, $bus->placa));

    expect($alerta->refresh()->estado)->toBe('atendida')
        ->and($bus->refresh()->estado_logico)->toBe('en_ruta');
});

test('solo el administrador entra a la simulación', function () {
    $this->actingAs($this->operador())->get('/admin/simulacion')->assertForbidden();

    $this->actingAs($this->administrador())->get('/admin/simulacion')
        ->assertOk()->assertSee('Simulación en vivo');
});

test('el administrador inicia la simulación y ejecuta ciclos desde la página', function () {
    $admin = $this->administrador();

    $this->actingAs($admin)->post('/admin/simulacion/iniciar')->assertSessionHas('estado');
    expect($this->simulador->activa())->toBeTrue();

    $this->actingAs($admin)->post('/admin/simulacion/ciclo', [], ['X-Requested-With' => 'XMLHttpRequest'])
        ->assertOk()->assertSee('Lo que está pasando');
    expect($this->simulador->numeroCiclo())->toBe(1);

    $this->actingAs($admin)->post('/admin/simulacion/hora-pico', ['id_estacion' => $this->estacion->id_estacion])
        ->assertSessionHas('estado');
    expect($this->estacion->refresh()->requiereRefuerzo())->toBeTrue();

    $this->actingAs($admin)->post('/admin/simulacion/pausar');
    expect($this->simulador->activa())->toBeFalse();
});

test('el comando transmetro:simular ejecuta ciclos desde la terminal', function () {
    $this->artisan('transmetro:simular', ['--ciclos' => 2, '--pico' => 'San Sebastián'])->assertExitCode(0);

    expect($this->simulador->numeroCiclo())->toBe(2);
});

test('los buses avanzan en tiempo real: solo llega a la siguiente estación el que ya cumplió el tiempo del tramo', function () {
    $viaje = Viaje::where('estado', 'en_curso')->whereHas('visitas')->with('linea')->firstOrFail();
    $ultima = $viaje->visitas()->orderByDesc('orden_visita')->firstOrFail();
    Viaje::where('estado', 'en_curso')->where('id_viaje', '!=', $viaje->id_viaje)->update(['estado' => 'finalizado']);

    // Acaba de salir: todavía va en camino, no avanza.
    $ultima->update(['hora_llegada' => now()->subMinute(), 'hora_salida' => now()]);
    $this->simulador->moverBuses();
    expect($viaje->visitas()->count())->toBe($ultima->orden_visita);

    // Ya pasó el tiempo del tramo: llega a la siguiente estación.
    $ultima->update(['hora_llegada' => now()->subMinutes(31), 'hora_salida' => now()->subMinutes(30)]);
    $this->simulador->moverBuses();
    expect($viaje->visitas()->count())->toBe($ultima->orden_visita + 1);
});

test('desde el monitoreo en vivo el administrador inicia y pausa la simulación; el operador no', function () {
    $admin = $this->administrador();
    $operador = $this->operador();

    $this->actingAs($admin)->postJson(route('mapa.simulacion'), ['accion' => 'iniciar'])->assertOk()->assertJson(['activa' => true]);
    expect($this->simulador->activa())->toBeTrue();

    // Con la simulación activa, la página del operador también avanza los ciclos (sin poder pausarla).
    $this->actingAs($operador)->postJson(route('mapa.ciclo'))->assertOk()
        ->assertJson(['activa' => true])->assertJsonStructure(['ahora', 'buses']);
    $this->actingAs($operador)->postJson(route('mapa.simulacion'), ['accion' => 'pausar'])->assertForbidden();
    expect($this->simulador->activa())->toBeTrue();

    $this->actingAs($admin)->postJson(route('mapa.simulacion'), ['accion' => 'pausar'])->assertOk()->assertJson(['activa' => false]);
    expect($this->simulador->activa())->toBeFalse();

    $this->actingAs($admin)->postJson(route('mapa.simulacion'), ['accion' => 'borrar'])->assertUnprocessable();
});

test('con la simulación en pausa, pedir un ciclo desde el monitoreo no mueve nada', function () {
    $this->simulador->pausar();
    $antes = VisitaViaje::count();

    $this->actingAs($this->operador())->postJson(route('mapa.ciclo'))->assertOk()->assertJson(['activa' => false]);
    expect(VisitaViaje::count())->toBe($antes);
});

/*
 * Horarios: los buses circulan solo dentro del horario de su línea.
 * (Las demás pruebas corren sin respetar horarios para no depender del reloj.)
 */
describe('horarios de las líneas', function () {
    beforeEach(function () {
        config(['transmetro.respetar_horarios' => true]);
        $this->l1 = Linea::where('codigo_linea', 'L1')->firstOrFail();
    });

    afterEach(fn () => Carbon::setTestNow());

    test('la línea está en servicio entre su apertura y su cierre', function () {
        Carbon::setTestNow(Carbon::parse('next monday 12:00:00'));   // lunes
        expect($this->l1->refresh()->load('horarios')->operaAhora())->toBeTrue();

        Carbon::setTestNow(Carbon::parse('next monday 22:11:00'));
        expect($this->l1->load('horarios')->operaAhora())->toBeFalse();
    });

    test('fuera de horario el bus que termina su recorrido se retira y no sale otra vuelta', function () {
        Carbon::setTestNow(Carbon::parse('next monday 22:11:00'));
        $viaje = Viaje::where('estado', 'en_curso')->where('id_linea', $this->l1->id_linea)->whereHas('bus.piloto')->with('bus')->firstOrFail();
        Viaje::where('estado', 'en_curso')->where('id_viaje', '!=', $viaje->id_viaje)->update(['estado' => 'finalizado']);
        $total = $this->l1->estaciones()->count();
        $viaje->visitas()->delete();
        $viaje->visitas()->create(['id_estacion' => $this->l1->estaciones()->orderByDesc('orden_parada')->firstOrFail()->id_estacion,
            'orden_visita' => $total, 'hora_llegada' => now()->subHour(), 'hora_salida' => now()->subMinutes(50), 'ocupacion_al_llegar_pct' => 10]);
        $viaje->bus->update(['pasajeros_a_bordo' => 40]);
        $antes = Viaje::where('id_bus', $viaje->id_bus)->count();

        $this->simulador->moverBuses();

        expect($viaje->refresh()->estado)->toBe('finalizado')
            ->and($viaje->bus->refresh()->estado_logico)->toBe('en_espera')
            ->and($viaje->bus->pasajeros_a_bordo)->toBe(0)
            ->and(Viaje::where('id_bus', $viaje->id_bus)->count())->toBe($antes);
    });

    test('fuera de horario no sale ningún bus de refuerzo ni de salida', function () {
        Carbon::setTestNow(Carbon::parse('next monday 22:11:00'));
        Viaje::query()->where('estado', 'en_curso')->update(['estado' => 'finalizado']);
        $this->simulador->horaPico($this->estacion);
        $alerta = AlertaSistema::abiertas()->where('id_estacion', $this->estacion->id_estacion)->firstOrFail();

        expect($this->simulador->despacharRefuerzo($alerta))->toBeNull();
        $this->simulador->despacharSalidas();
        expect(Viaje::where('estado', 'en_curso')->count())->toBe(0);
    });

    test('las estaciones se vacían cuando todas sus líneas cerraron', function () {
        Carbon::setTestNow(Carbon::parse('next monday 22:11:00'));
        $this->estacion->update(['aforo_actual' => 100]);

        $this->simulador->moverPasajeros();

        expect($this->estacion->refresh()->aforo_actual)->toBeLessThan(100);
    });

    test('al abrir la línea salen los buses en reserva hasta tener el mínimo en la calle', function () {
        Carbon::setTestNow(Carbon::parse('next monday 05:10:00'));
        Viaje::where('id_linea', $this->l1->id_linea)->where('estado', 'en_curso')->update(['estado' => 'finalizado']);
        Bus::where('id_linea_asignada', $this->l1->id_linea)->update(['estado_logico' => 'en_espera', 'pasajeros_a_bordo' => 0]);

        $this->simulador->despacharSalidas();

        expect(Viaje::where('id_linea', $this->l1->id_linea)->where('estado', 'en_curso')->count())->toBe(SimuladorOperacion::BUSES_MINIMOS_EN_CALLE);
    });

    test('pasados los minutos de gracia del cierre, los buses que siguen en la calle se retiran', function () {
        Carbon::setTestNow(Carbon::parse('next monday 20:10:00'));   // L1 cerró a las 20:00: todavía hay gracia
        $this->simulador->retirarBusesFueraDeHorario();
        expect(Viaje::where('id_linea', $this->l1->id_linea)->where('estado', 'en_curso')->count())->toBeGreaterThan(0);

        Carbon::setTestNow(Carbon::parse('next monday 22:11:00'));
        $this->simulador->retirarBusesFueraDeHorario();

        expect(Viaje::where('id_linea', $this->l1->id_linea)->where('estado', 'en_curso')->count())->toBe(0)
            ->and(Bus::where('id_linea_asignada', $this->l1->id_linea)->where('estado_logico', 'en_ruta')->count())->toBe(0)
            ->and((int) Bus::where('id_linea_asignada', $this->l1->id_linea)->sum('pasajeros_a_bordo'))->toBe(0);
    });
});

/*
 * Tráfico: en hora pico los tramos tardan más y cada tramo tiene su propia variación (semáforos, cruces).
 */
test('en hora pico de lunes a viernes los tramos tardan un 40 % más', function () {
    $lunesPico = Carbon::parse('next monday 07:15');
    $lunesTarde = Carbon::parse('next monday 11:00');
    $domingoPico = Carbon::parse('next sunday 07:15');

    expect(SimuladorOperacion::esHoraPico($lunesPico))->toBeTrue()
        ->and(SimuladorOperacion::esHoraPico(Carbon::parse('next monday 18:00')))->toBeTrue()
        ->and(SimuladorOperacion::esHoraPico($lunesTarde))->toBeFalse()
        ->and(SimuladorOperacion::esHoraPico($domingoPico))->toBeFalse()
        ->and(SimuladorOperacion::factorTrafico($lunesPico))->toBe(1.4)
        ->and(SimuladorOperacion::factorTrafico($lunesTarde))->toBe(1.0)
        ->and(SimuladorOperacion::segundosTramo(1.0, $lunesPico))->toBe(210)    // 150 s × 1.4
        ->and(SimuladorOperacion::segundosTramo(1.0, $lunesTarde))->toBe(150);
});

test('cada tramo varía entre −10 % y +30 %, y siempre igual para el mismo tramo', function () {
    $hora = Carbon::parse('next monday 11:00');
    $factores = collect(range(1, 200))->map(fn ($i) => SimuladorOperacion::factorTrafico($hora, "viaje-{$i}|3"));

    expect($factores->min())->toBeGreaterThanOrEqual(0.9)
        ->and($factores->max())->toBeLessThanOrEqual(1.3)
        ->and($factores->unique()->count())->toBeGreaterThan(50)
        ->and(SimuladorOperacion::factorTrafico($hora, 'viaje-7|3'))->toBe(SimuladorOperacion::factorTrafico($hora, 'viaje-7|3'));
});

test('sin tráfico configurado, el tramo dura lo de la distancia', function () {
    config(['transmetro.trafico' => false]);

    expect(SimuladorOperacion::segundosTramo(1.0, Carbon::parse('next monday 07:15'), 'x|1'))->toBe(150)
        ->and(SimuladorOperacion::segundosTramo(0.1))->toBe(SimuladorOperacion::SEGUNDOS_MINIMO_TRAMO);
});

test('la simulación sigue encendida aunque el servidor se reinicie (el estado se guarda en la base de datos)', function () {
    $this->simulador->activar();

    // Al reiniciar el servidor se pierde la caché en archivos/memoria, pero no la base de datos.
    Cache::flush();
    expect(app(SimuladorOperacion::class)->activa())->toBeTrue();

    $this->simulador->pausar();
    Cache::flush();
    expect(app(SimuladorOperacion::class)->activa())->toBeFalse();
});

test('en la versión publicada la simulación arranca encendida si nadie la ha pausado', function () {
    Cache::store('database')->forget(SimuladorOperacion::CLAVE_ACTIVA);

    config(['transmetro.simulacion_encendida' => false]);
    expect($this->simulador->activa())->toBeFalse();

    config(['transmetro.simulacion_encendida' => true]);
    expect($this->simulador->activa())->toBeTrue();
});

test('las pantallas abiertas no repiten ciclos: como máximo uno cada pocos segundos aunque haya varias', function () {
    $this->simulador->activar();
    $operador = $this->operador();

    $this->actingAs($operador)->postJson(route('mapa.ciclo'))->assertOk();
    $this->actingAs($this->administrador())->postJson(route('mapa.ciclo'))->assertOk();

    expect($this->simulador->numeroCiclo())->toBe(1)
        ->and($this->simulador->alguienMirando())->toBeTrue();
});

test('si el motor en segundo plano está corriendo, la pantalla abierta no ejecuta ciclos', function () {
    $this->simulador->activar();
    $this->simulador->latido();

    $this->actingAs($this->operador())->postJson(route('mapa.ciclo'))->assertOk();

    expect($this->simulador->numeroCiclo())->toBe(0)
        ->and($this->simulador->alguienMirando())->toBeTrue();
});

test('el motor calcula seguido si alguien mira y cada pocos minutos si nadie mira', function () {
    $cache = Cache::store('database');
    config(['transmetro.motor_segundos_con_vista' => 5, 'transmetro.motor_segundos_sin_vista' => 120]);
    $this->simulador->activar();

    // Nadie mirando y el último ciclo fue hace 1 minuto: todavía no toca.
    $cache->put(SimuladorOperacion::CLAVE_ULTIMO_CICLO, time() - 60, 600);
    $this->simulador->cicloDelMotor();
    expect($this->simulador->numeroCiclo())->toBe(0);

    // Alguien abrió una pantalla: ya toca (pasaron más de 5 segundos).
    $this->simulador->marcarVista();
    $this->simulador->cicloDelMotor();
    expect($this->simulador->numeroCiclo())->toBe(1);

    // Nadie mirando, pero ya pasaron más de 2 minutos: toca otra vez.
    $cache->forget(SimuladorOperacion::CLAVE_VISTA);
    $cache->put(SimuladorOperacion::CLAVE_ULTIMO_CICLO, time() - 130, 600);
    $this->simulador->cicloDelMotor();
    expect($this->simulador->numeroCiclo())->toBe(2);

    // En pausa, el motor no calcula nada.
    $this->simulador->pausar();
    $cache->put(SimuladorOperacion::CLAVE_ULTIMO_CICLO, time() - 600, 600);
    $this->simulador->cicloDelMotor();
    expect($this->simulador->numeroCiclo())->toBe(2);
});
