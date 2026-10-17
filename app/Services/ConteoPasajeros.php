<?php

namespace App\Services;

use App\Models\AlertaSistema;
use App\Models\BajadaBus;
use App\Models\Bus;
use App\Models\Estacion;
use App\Models\Molinete;
use App\Models\VisitaViaje;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Conteo de pasajeros en las puertas del bus.
 *  - Molinete de salida: cuenta a las personas que bajan en cada estación.
 *  - Bus lleno: si el bus va lleno y quedan personas en la estación sin poder subir, se crea la
 *    alerta 'bus_lleno' para mandar otro bus a esa ruta.
 * Las subidas (con su cobro) están en CobroPasaje.
 */
class ConteoPasajeros
{
    /** Parada donde está el bus: la última visita de su viaje en curso (o null si no va en ruta). */
    public function paradaActual(Bus $bus): ?VisitaViaje
    {
        return VisitaViaje::query()
            ->join('viajes', 'viajes.id_viaje', '=', 'visitas_viaje.id_viaje')
            ->where('viajes.id_bus', $bus->id_bus)
            ->where('viajes.estado', 'en_curso')
            ->orderByDesc('visitas_viaje.orden_visita')
            ->select('visitas_viaje.*')
            ->with('estacion:id_estacion,nombre,aforo_actual,aforo_maximo')
            ->first();
    }

    /**
     * Registra en el molinete de salida a las personas que bajaron del bus en su parada actual.
     * $manual: lo registra el operador porque el contador falló (se acepta aunque el molinete no esté activo).
     */
    public function registrarBajadas(Molinete $salida, int $cantidad = 1, bool $manual = false): BajadaBus
    {
        if ($salida->tipo !== 'salida_bus') {
            throw ValidationException::withMessages(['bajadas' => 'Las bajadas se cuentan en el molinete de salida del bus.']);
        }
        if (! $manual && $salida->estado_operativo !== 'activo') {
            throw ValidationException::withMessages(['bajadas' => 'El molinete de salida del bus no está activo.']);
        }
        $bus = $salida->bus;
        $parada = $this->paradaActual($bus);
        if (! $parada) {
            throw ValidationException::withMessages(['bajadas' => "El bus {$bus->placa} no está en una estación (no tiene un viaje en curso)."]);
        }
        if ($cantidad > $bus->pasajeros_a_bordo) {
            throw ValidationException::withMessages(['cantidad' => "En el bus van {$bus->pasajeros_a_bordo} pasajeros: no pueden bajar {$cantidad}."]);
        }

        try {
            return BajadaBus::create([
                'id_molinete' => $salida->id_molinete,
                'id_bus' => $bus->id_bus,
                'id_estacion' => $parada->id_estacion,
                'id_visita' => $parada->id_visita,
                'cantidad' => $cantidad,
                'fecha_hora' => Carbon::now(),
            ]);
        } catch (QueryException $e) {
            // El trigger trg_bajadas_restan revisa lo mismo si dos lecturas llegan a la vez.
            if (($e->errorInfo[0] ?? '') !== 'P0001') {
                throw $e;
            }
            throw ValidationException::withMessages(['cantidad' => 'El contador de salida marca más bajadas que pasajeros a bordo.']);
        }
    }

    /**
     * Alerta de bus lleno: el bus va lleno y en la estación quedaron personas que no pudieron subir.
     * Una sola alerta abierta por estación (si ya hay una, no se repite). Devuelve la alerta creada o null.
     */
    public function alertaBusLleno(Bus $bus, Estacion $estacion, int $quedaron): ?AlertaSistema
    {
        if ($quedaron <= 0 || AlertaSistema::abiertas()->where('tipo_alerta', 'bus_lleno')->where('id_estacion', $estacion->id_estacion)->exists()) {
            return null;
        }

        return AlertaSistema::create($this->filaAlerta($bus, $estacion->id_estacion, $estacion->nombre, $quedaron, Carbon::now()));
    }

    /** Fila de la alerta de bus lleno (la simulación las inserta varias juntas). */
    public function filaAlerta(Bus $bus, string $idEstacion, string $nombreEstacion, int $quedaron, Carbon $cuando): array
    {
        $linea = $bus->linea?->codigo_linea;

        return [
            'id_estacion' => $idEstacion,
            'id_bus' => $bus->id_bus,
            'tipo_alerta' => 'bus_lleno',
            'estado' => 'abierta',
            'fecha_creacion' => $cuando->toDateTimeString(),
            'descripcion' => "El bus {$bus->placa}".($linea ? " ({$linea})" : '')." va lleno ({$bus->capacidad_maxima}/{$bus->capacidad_maxima}) en {$nombreEstacion}: "
                ."{$quedaron} ".($quedaron === 1 ? 'persona quedó' : 'personas quedaron').' sin poder subir. Enviar otro bus a la ruta.',
        ];
    }

    /** Subidas y bajadas de hoy de un bus (lo que marcaron sus molinetes). */
    public function resumenHoy(Bus $bus): array
    {
        $desde = Carbon::today()->toDateTimeString();
        $fila = DB::selectOne(<<<'SQL'
            SELECT (SELECT count(*) FROM transacciones_pago WHERE id_bus = :b1 AND fecha_hora >= :d1) AS subieron,
                   (SELECT coalesce(sum(monto), 0) FROM transacciones_pago WHERE id_bus = :b2 AND fecha_hora >= :d2) AS cobrado,
                   (SELECT coalesce(sum(cantidad), 0) FROM bajadas_bus WHERE id_bus = :b3 AND fecha_hora >= :d3) AS bajaron
            SQL, ['b1' => $bus->id_bus, 'd1' => $desde, 'b2' => $bus->id_bus, 'd2' => $desde, 'b3' => $bus->id_bus, 'd3' => $desde]);

        return ['subieron' => (int) $fila->subieron, 'bajaron' => (int) $fila->bajaron, 'cobrado' => (float) $fila->cobrado];
    }
}
