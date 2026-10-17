<?php

use App\Models\AlertaSistema;
use App\Models\BitacoraAuditoria;
use App\Models\Viaje;
use App\Services\LimpiezaSimulacion;
use Illuminate\Support\Facades\DB;

/*
 * Limpieza automática de la operación simulada: con la simulación encendida las 24 horas, cada hora se borra
 * lo viejo para no llenar la base de datos gratuita de Supabase (500 MB).
 */

beforeEach(function () {
    $this->limpieza = app(LimpiezaSimulacion::class);
});

test('borra los pagos, bajadas, viajes terminados y alertas cerradas más viejos que el límite', function () {
    $hace3dias = now()->subDays(3);

    $pagoViejo = DB::table('transacciones_pago')->value('id_transaccion');
    DB::table('transacciones_pago')->where('id_transaccion', $pagoViejo)->update(['fecha_hora' => $hace3dias]);
    $bajadaVieja = DB::table('bajadas_bus')->value('id_bajada');
    DB::table('bajadas_bus')->where('id_bajada', $bajadaVieja)->update(['fecha_hora' => $hace3dias]);

    $viajeViejo = Viaje::where('estado', 'en_curso')->whereHas('visitas')->firstOrFail();
    $viajeViejo->update(['estado' => 'finalizado', 'fecha_hora_inicio' => $hace3dias->copy()->subHour(), 'fecha_hora_fin' => $hace3dias]);
    $viajeEnCurso = Viaje::where('estado', 'en_curso')->firstOrFail();
    $viajeEnCurso->update(['fecha_hora_inicio' => $hace3dias]);

    $alerta = AlertaSistema::firstOrFail();
    $alerta->update(['estado' => 'cerrada', 'fecha_cierre' => $hace3dias]);

    $pagosRecientes = DB::table('transacciones_pago')->where('fecha_hora', '>=', now()->subDays(2))->count();
    $bitacora = BitacoraAuditoria::count();

    $borrados = $this->limpieza->limpiar(48);

    expect($borrados['transacciones_pago'])->toBeGreaterThanOrEqual(1)
        ->and(DB::table('transacciones_pago')->where('id_transaccion', $pagoViejo)->exists())->toBeFalse()
        ->and(DB::table('bajadas_bus')->where('id_bajada', $bajadaVieja)->exists())->toBeFalse()
        ->and(Viaje::find($viajeViejo->id_viaje))->toBeNull()
        ->and(DB::table('visitas_viaje')->where('id_viaje', $viajeViejo->id_viaje)->exists())->toBeFalse()
        ->and(Viaje::find($viajeEnCurso->id_viaje))->not->toBeNull()   // un viaje en curso nunca se borra
        ->and(AlertaSistema::find($alerta->id_alerta))->toBeNull()
        ->and(DB::table('transacciones_pago')->where('fecha_hora', '>=', now()->subDays(2))->count())->toBe($pagosRecientes)
        ->and(BitacoraAuditoria::count())->toBe($bitacora);   // la bitácora no se toca
});

test('las alertas abiertas no se borran aunque sean viejas', function () {
    $alerta = AlertaSistema::firstOrFail();
    $alerta->update(['estado' => 'abierta', 'fecha_creacion' => now()->subDays(5), 'fecha_cierre' => null]);

    $this->limpieza->limpiar(1);

    expect(AlertaSistema::find($alerta->id_alerta))->not->toBeNull();
});

test('si la base pasa del límite de tamaño se conservan solo las últimas 12 horas', function () {
    $pago = DB::table('transacciones_pago')->value('id_transaccion');
    DB::table('transacciones_pago')->where('id_transaccion', $pago)->update(['fecha_hora' => now()->subHours(20)]);
    config(['transmetro.retencion_horas' => 48, 'transmetro.limite_base_mb' => 0]);

    $resultado = $this->limpieza->limpiarSegunEspacio();

    expect($resultado['horas'])->toBe(LimpiezaSimulacion::HORAS_DE_EMERGENCIA)
        ->and(DB::table('transacciones_pago')->where('id_transaccion', $pago)->exists())->toBeFalse();
});

test('en la laptop la limpieza está apagada y el comando lo avisa', function () {
    config(['transmetro.retencion_horas' => 0, 'transmetro.limite_base_mb' => 100000]);
    $antes = DB::table('transacciones_pago')->count();

    expect($this->limpieza->limpiarSegunEspacio()['borrados'])->toBe([]);
    $this->artisan('transmetro:limpiar')->expectsOutputToContain('desactivada')->assertExitCode(0);
    expect(DB::table('transacciones_pago')->count())->toBe($antes);
});

test('el comando transmetro:limpiar borra lo más viejo que las horas indicadas', function () {
    $pago = DB::table('transacciones_pago')->value('id_transaccion');
    DB::table('transacciones_pago')->where('id_transaccion', $pago)->update(['fecha_hora' => now()->subDays(4)]);

    $this->artisan('transmetro:limpiar', ['--horas' => 72])->expectsOutputToContain('transacciones_pago')->assertExitCode(0);

    expect(DB::table('transacciones_pago')->where('id_transaccion', $pago)->exists())->toBeFalse();
});
