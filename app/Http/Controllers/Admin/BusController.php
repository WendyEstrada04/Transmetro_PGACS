<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ManejaErroresBD;
use App\Http\Controllers\Controller;
use App\Models\Bus;
use App\Models\Linea;
use App\Models\Molinete;
use App\Models\Parqueo;
use App\Services\Auditoria;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Buses (puntos 4, 5, 6, 7 y 14).
 * - Un bus está en una sola línea o en ninguna.
 * - Todo bus nace con un parqueo y solo puede cambiarlo, nunca quedarse sin él.
 * - El máximo de buses por línea lo valida el trigger trg_buses_max_flota.
 * - Cada bus tiene molinete de entrada (cobra al subir) y de salida (cuenta al bajar); la base los crea
 *   sola (trg_buses_molinetes) y los pasajeros a bordo salen de ellos, no se escriben a mano.
 */
class BusController extends Controller
{
    use ManejaErroresBD;

    private const ESTADOS = [
        'en_ruta' => 'En ruta',
        'en_espera' => 'En espera',
        'fuera_de_servicio' => 'Fuera de servicio',
        'en_mantenimiento' => 'En mantenimiento',
    ];

    public function index(Request $request): View
    {
        $placa = strtoupper(preg_replace('/[\s-]/', '', (string) $request->input('placa')));
        // Línea, parqueo y piloto de cada bus con JOIN en la misma consulta (más rápido con la base remota).
        // Estación donde va: la última que visitó en su viaje en curso; la próxima es la siguiente de su ruta
        // (en una ruta circular, después de la última vuelve a la primera).
        $actual = DB::table('viajes as v')
            ->join('visitas_viaje as vv', 'vv.id_viaje', '=', 'v.id_viaje')
            ->join('estaciones as ea', 'ea.id_estacion', '=', 'vv.id_estacion')
            ->join('linea_estacion as la', fn ($j) => $j->on('la.id_linea', '=', 'v.id_linea')->on('la.id_estacion', '=', 'vv.id_estacion'))
            ->whereColumn('v.id_bus', 'buses.id_bus')->where('v.estado', 'en_curso')
            ->orderByDesc('vv.orden_visita')->limit(1)
            ->select('ea.nombre', 'la.orden_parada', 'vv.subieron', 'vv.bajaron');
        $proxima = DB::table('linea_estacion as lp')
            ->join('estaciones as ep', 'ep.id_estacion', '=', 'lp.id_estacion')
            ->whereColumn('lp.id_linea', 'buses.id_linea_asignada')
            ->whereNotNull('ub.orden_parada')
            ->where(fn ($w) => $w->whereColumn('lp.orden_parada', '>', 'ub.orden_parada')->orWhere('l.ruta_circular', true))
            ->orderByRaw('lp.orden_parada <= ub.orden_parada, lp.orden_parada')->limit(1)
            ->select('ep.nombre');

        $buses = Bus::query()
            ->select('buses.*', 'l.codigo_linea', 'l.color as color_linea', 'pq.codigo_bahia', 'pe.nombre as parqueo_estacion', 'em.nombre_completo as piloto_nombre',
                'ub.nombre as estacion_actual', 'ub.orden_parada as orden_actual', 'up.nombre as estacion_proxima',
                'ub.subieron as subieron_actual', 'ub.bajaron as bajaron_actual')
            ->leftJoin('lineas as l', 'l.id_linea', '=', 'buses.id_linea_asignada')
            ->leftJoin('parqueos as pq', 'pq.id_bus_asignado', '=', 'buses.id_bus')
            ->leftJoin('estaciones as pe', 'pe.id_estacion', '=', 'pq.id_estacion')
            ->leftJoin('pilotos as pi', 'pi.id_bus_asignado', '=', 'buses.id_bus')
            ->leftJoin('empleados as em', 'em.id_empleado', '=', 'pi.id_empleado')
            ->leftJoinLateral($actual, 'ub')
            ->leftJoinLateral($proxima, 'up')
            ->when($request->filled('linea'), fn ($q) => $request->linea === 'ninguna'
                ? $q->whereNull('buses.id_linea_asignada')
                : $q->where('buses.id_linea_asignada', $request->linea))
            // Buscador por placa (se acepta "c-142 jzb") y apartado de unidades sin piloto.
            ->when($placa !== '', fn ($q) => $q->where('buses.placa', 'like', '%'.$placa.'%'))
            ->when($request->piloto === 'sin', fn ($q) => $q->whereNull('pi.id_empleado'))
            ->orderBy('buses.placa')
            ->paginate(24)
            ->withQueryString();

        return view('admin.buses.index', [
            'buses' => $buses,
            'lineas' => Linea::enOrden()->get(['id_linea', 'codigo_linea', 'color']),
            'estados' => self::ESTADOS,
            'porEstado' => Bus::selectRaw('estado_logico, count(*) AS n')->groupBy('estado_logico')->pluck('n', 'estado_logico')->all(),
            'sinPiloto' => Bus::whereDoesntHave('piloto')->count(),
        ]);
    }

    public function create(): View
    {
        return view('admin.buses.form', [
            'bus' => new Bus(['tipo_unidad' => 'articulado', 'capacidad_maxima' => Bus::TIPOS_UNIDAD['articulado'][1], 'estado_logico' => 'fuera_de_servicio', 'pasajeros_a_bordo' => 0]),
            'lineas' => $this->lineas(),
            'parqueosLibres' => $this->parqueosLibres(),
            'estados' => self::ESTADOS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $this->validar($request);
        $this->exigirPilotoParaRuta($datos, null);
        $parqueo = $request->validate([
            'id_parqueo' => ['required', 'uuid', Rule::exists('parqueos', 'id_parqueo')->whereNull('id_bus_asignado')],
        ], ['id_parqueo.exists' => 'Ese parqueo ya está ocupado.'])['id_parqueo'];

        try {
            DB::transaction(function () use ($datos, $parqueo) {
                $bus = Bus::create($datos);
                Parqueo::whereKey($parqueo)->update(['id_bus_asignado' => $bus->id_bus]);
                Auditoria::registrar('buses', $bus->id_bus, 'INSERT', null, $this->auditoria($bus->refresh()));
            });
        } catch (QueryException $e) {
            return $this->volverConErrorBD($e);
        }

        return redirect()->route('admin.buses.index')->with('estado', 'Bus registrado con su parqueo.');
    }

    public function edit(Bus $bus): View
    {
        $bus->load(['parqueo.estacion', 'linea', 'molinetes', 'piloto.empleado', 'mantenimientos.reportadoPor']);

        return view('admin.buses.form', [
            'bus' => $bus,
            'lineas' => $this->lineas(),
            'parqueosLibres' => $this->parqueosLibres(),
            'estados' => self::ESTADOS,
        ]);
    }

    public function update(Request $request, Bus $bus): RedirectResponse
    {
        $datos = $this->validar($request, $bus);
        $this->exigirPilotoParaRuta($datos, $bus);

        $antes = $this->auditoria($bus);
        try {
            DB::transaction(function () use ($bus, $datos, $antes) {
                $bus->update($datos);
                Auditoria::cambio('buses', $bus->id_bus, $antes, $this->auditoria($bus->refresh()));
            });
        } catch (QueryException $e) {
            return $this->volverConErrorBD($e);
        }

        return redirect()->route('admin.buses.index')->with('estado', "Bus {$bus->placa} actualizado.");
    }

    /** Punto 7: el bus cambia de parqueo en una sola operación (procedimiento almacenado). */
    public function cambiarParqueo(Request $request, Bus $bus): RedirectResponse
    {
        $datos = $request->validate(['id_parqueo_nuevo' => ['required', 'uuid', 'exists:parqueos,id_parqueo']]);

        $antes = $this->auditoria($bus);
        try {
            DB::transaction(function () use ($bus, $datos, $antes) {
                $bus->cambiarParqueo($datos['id_parqueo_nuevo']);
                Auditoria::cambio('buses', $bus->id_bus, $antes, $this->auditoria($bus->refresh()));
            });
        } catch (QueryException $e) {
            return $this->volverConErrorBD($e);
        }

        return back()->with('estado', 'Parqueo cambiado.');
    }

    /**
     * Cambia el estado del molinete de entrada (cobra) o de salida (cuenta) del bus.
     * Un molinete fuera de servicio no registra: el operador registra los pases o bajadas a mano.
     */
    public function cambiarEstadoMolinete(Request $request, Bus $bus, Molinete $molinete): RedirectResponse
    {
        abort_unless($molinete->id_bus === $bus->id_bus, 404);
        $estado = $request->validate(['estado_operativo' => ['required', Rule::in(array_keys(Molinete::ESTADOS))]])['estado_operativo'];
        $puerta = $molinete->tipo === 'entrada_bus' ? 'entrada' : 'salida';
        $antes = $molinete->estado_operativo;
        DB::transaction(function () use ($molinete, $estado, $antes, $bus, $puerta) {
            $molinete->update(['estado_operativo' => $estado]);
            Auditoria::cambio('molinetes', $molinete->id_molinete,
                ['bus' => $bus->placa, 'molinete' => $puerta, 'estado_operativo' => $antes],
                ['bus' => $bus->placa, 'molinete' => $puerta, 'estado_operativo' => $estado]);
        });

        return back()->with('estado', "Molinete de {$puerta} del bus {$bus->placa}: ".mb_strtolower(Molinete::ESTADOS[$estado]).'.');
    }

    public function destroy(Bus $bus): RedirectResponse
    {
        $antes = $this->auditoria($bus);
        try {
            DB::transaction(function () use ($bus, $antes) {
                $bus->delete();
                Auditoria::registrar('buses', $bus->id_bus, 'DELETE', $antes, null);
            });
        } catch (QueryException $e) {
            return $this->volverConErrorBD($e);
        }

        return redirect()->route('admin.buses.index')->with('estado', 'Bus eliminado.');
    }

    /** Datos del bus que se guardan en la bitácora (con la línea y el parqueo en texto, no sus códigos internos). */
    private function auditoria(Bus $bus): array
    {
        $parqueo = $bus->parqueo()->with('estacion')->first();

        return [
            'placa' => $bus->placa,
            'tipo_unidad' => $bus->tipo_unidad,
            'capacidad_maxima' => (int) $bus->capacidad_maxima,
            'linea' => $bus->linea()->value('codigo_linea'),
            'estado_logico' => $bus->estado_logico,
            'parqueo' => $parqueo ? "{$parqueo->estacion->nombre} · {$parqueo->codigo_bahia}" : null,
        ];
    }

    /** Placa de Guatemala: letra del tipo de vehículo + 3 números + 3 letras (los buses del Transmetro usan C, p. ej. C387JGL). */
    public const FORMATO_PLACA = '/^(P|A|C|U|O|M|TC|TE)[0-9]{3}[A-Z]{3}$/';

    private function validar(Request $request, ?Bus $bus = null): array
    {
        // Se acepta con espacios, guion o en minúsculas: "c-387 jgl" → C387JGL.
        $request->merge(['placa' => strtoupper(preg_replace('/[\s-]/', '', (string) $request->input('placa')))]);

        return $request->validate([
            'placa' => ['required', 'string', 'max:15', 'regex:'.self::FORMATO_PLACA, Rule::unique('buses', 'placa')->ignore($bus?->id_bus, 'id_bus')],
            // No puede quedar menor que los pasajeros que ya van a bordo.
            'tipo_unidad' => ['required', Rule::in(array_keys(Bus::TIPOS_UNIDAD))],
            'capacidad_maxima' => ['required', 'integer', 'min:'.max(1, (int) $bus?->pasajeros_a_bordo), 'max:500'],
            'id_linea_asignada' => ['nullable', 'uuid', 'exists:lineas,id_linea'],
            'estado_logico' => ['required', Rule::in(array_keys(self::ESTADOS))],
        ], [
            'placa.regex' => 'La placa debe tener el formato de Guatemala: letra del tipo de vehículo, 3 números y 3 letras (por ejemplo C387JGL).',
        ]);
    }

    /** Una unidad sin piloto asignado no puede ponerse en ruta (la base de datos también lo impide). */
    private function exigirPilotoParaRuta(array $datos, ?Bus $bus): void
    {
        if ($datos['estado_logico'] === 'en_ruta' && ! $bus?->piloto()->exists()) {
            throw ValidationException::withMessages([
                'estado_logico' => 'La unidad no tiene piloto asignado: no puede ponerse en ruta. Asígnale uno en Personal (piloto → Bus que conduce).',
            ]);
        }
    }

    private function lineas(): array
    {
        return Linea::withCount(['estaciones', 'buses'])->enOrden()->get()
            ->mapWithKeys(fn ($l) => [$l->id_linea => "{$l->codigo_linea} ({$l->buses_count} buses, máx. ".(2 * $l->estaciones_count).')'])
            ->all();
    }

    private function parqueosLibres(): array
    {
        return Parqueo::with('estacion')->whereNull('id_bus_asignado')->get()
            ->sortBy(fn ($p) => $p->estacion->nombre.$p->codigo_bahia)
            ->mapWithKeys(fn ($p) => [$p->id_parqueo => "{$p->estacion->nombre} · {$p->codigo_bahia}"])
            ->all();
    }
}
