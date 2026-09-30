<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ManejaErroresBD;
use App\Http\Controllers\Controller;
use App\Models\Bus;
use App\Models\Linea;
use App\Models\Parqueo;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Buses (puntos 4, 5, 6, 7 y 14).
 * - Un bus está en una sola línea o en ninguna.
 * - Todo bus nace con un parqueo y solo puede cambiarlo, nunca quedarse sin él.
 * - El máximo de buses por línea lo valida el trigger trg_buses_max_flota.
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
        $buses = Bus::with(['linea', 'parqueo.estacion', 'piloto.empleado'])
            ->when($request->filled('linea'), fn ($q) => $request->linea === 'ninguna'
                ? $q->whereNull('id_linea_asignada')
                : $q->where('id_linea_asignada', $request->linea))
            ->orderBy('placa')
            ->paginate(25)
            ->withQueryString();

        return view('admin.buses.index', [
            'buses' => $buses,
            'lineas' => Linea::orderBy('codigo_linea')->pluck('codigo_linea', 'id_linea')->all(),
            'estados' => self::ESTADOS,
        ]);
    }

    public function create(): View
    {
        return view('admin.buses.form', [
            'bus' => new Bus(['capacidad_maxima' => 80, 'estado_logico' => 'fuera_de_servicio', 'ocupacion_actual_pct' => 0]),
            'lineas' => $this->lineas(),
            'parqueosLibres' => $this->parqueosLibres(),
            'estados' => self::ESTADOS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $this->validar($request);
        $parqueo = $request->validate([
            'id_parqueo' => ['required', 'uuid', Rule::exists('parqueos', 'id_parqueo')->whereNull('id_bus_asignado')],
        ], ['id_parqueo.exists' => 'Ese parqueo ya está ocupado.'])['id_parqueo'];

        try {
            DB::transaction(function () use ($datos, $parqueo) {
                $bus = Bus::create($datos);
                Parqueo::whereKey($parqueo)->update(['id_bus_asignado' => $bus->id_bus]);
            });
        } catch (QueryException $e) {
            return $this->volverConErrorBD($e);
        }

        return redirect()->route('admin.buses.index')->with('estado', 'Bus registrado con su parqueo.');
    }

    public function edit(Bus $bus): View
    {
        $bus->load(['parqueo.estacion', 'linea']);

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

        try {
            $bus->update($datos);
        } catch (QueryException $e) {
            return $this->volverConErrorBD($e);
        }

        return redirect()->route('admin.buses.index')->with('estado', "Bus {$bus->placa} actualizado.");
    }

    /** Punto 7: el bus cambia de parqueo en una sola operación (procedimiento almacenado). */
    public function cambiarParqueo(Request $request, Bus $bus): RedirectResponse
    {
        $datos = $request->validate(['id_parqueo_nuevo' => ['required', 'uuid', 'exists:parqueos,id_parqueo']]);

        try {
            $bus->cambiarParqueo($datos['id_parqueo_nuevo']);
        } catch (QueryException $e) {
            return $this->volverConErrorBD($e);
        }

        return back()->with('estado', 'Parqueo cambiado.');
    }

    public function destroy(Bus $bus): RedirectResponse
    {
        try {
            $bus->delete();
        } catch (QueryException $e) {
            return $this->volverConErrorBD($e);
        }

        return redirect()->route('admin.buses.index')->with('estado', 'Bus eliminado.');
    }

    private function validar(Request $request, ?Bus $bus = null): array
    {
        return $request->validate([
            'placa' => ['required', 'string', 'max:15', Rule::unique('buses', 'placa')->ignore($bus?->id_bus, 'id_bus')],
            'capacidad_maxima' => ['required', 'integer', 'min:1', 'max:500'],
            'id_linea_asignada' => ['nullable', 'uuid', 'exists:lineas,id_linea'],
            'estado_logico' => ['required', Rule::in(array_keys(self::ESTADOS))],
            'ocupacion_actual_pct' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);
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
