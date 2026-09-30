<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ManejaErroresBD;
use App\Http\Controllers\Controller;
use App\Models\Estacion;
use App\Models\HorarioLinea;
use App\Models\Linea;
use App\Models\Municipio;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Líneas y sus estaciones en orden (puntos 1, 2, 5, 8, 11, 12 y 13).
 * Una línea solo se puede ACTIVAR si tiene al menos 2 estaciones y entre
 * n y 2n buses asignados (n = número de estaciones). El máximo 2n también
 * lo protege el trigger trg_buses_max_flota en la base de datos.
 */
class LineaController extends Controller
{
    use ManejaErroresBD;

    public function index(): View
    {
        return view('admin.lineas.index', [
            'lineas' => Linea::with('municipio')->withCount(['estaciones', 'buses'])->orderBy('codigo_linea')->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.lineas.form', ['linea' => new Linea, 'municipios' => $this->municipios()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $this->validar($request);

        try {
            // Una línea nueva no tiene estaciones ni buses: nace inactiva y con el horario oficial.
            $linea = DB::transaction(function () use ($datos) {
                $linea = Linea::create($datos + ['estado' => 'inactiva', 'distancia_total_km' => 0]);
                foreach (HorarioLinea::HORARIO_OFICIAL as $tipo => [$inicio, $fin]) {
                    $linea->horarios()->create(['tipo_dia' => $tipo, 'hora_inicio' => $inicio, 'hora_fin' => $fin]);
                }

                return $linea;
            });
        } catch (QueryException $e) {
            return $this->volverConErrorBD($e);
        }

        return redirect()->route('admin.lineas.show', $linea)
            ->with('estado', 'Línea creada. Agrégale sus estaciones en orden y asígnale buses para poder activarla.');
    }

    public function show(Linea $linea): View
    {
        $linea->load(['municipio', 'horarios', 'estaciones.municipio', 'buses.parqueo.estacion', 'buses.piloto.empleado']);
        $idsEnLinea = $linea->estaciones->pluck('id_estacion');

        return view('admin.lineas.show', [
            'linea' => $linea,
            'accesos' => $linea->accesos()->load('estacion'),
            'estacionesDisponibles' => Estacion::whereNotIn('id_estacion', $idsEnLinea)->orderBy('nombre')
                ->pluck('nombre', 'id_estacion')->all(),
        ]);
    }

    public function edit(Linea $linea): View
    {
        return view('admin.lineas.form', ['linea' => $linea, 'municipios' => $this->municipios()]);
    }

    public function update(Request $request, Linea $linea): RedirectResponse
    {
        $datos = $this->validar($request, $linea);
        $datos += $request->validate(['estado' => ['required', Rule::in(['activa', 'inactiva', 'mantenimiento'])]]);

        if ($datos['estado'] === 'activa') {
            $n = $linea->estaciones()->count();
            $buses = $linea->buses()->count();
            if ($n < 2) {
                return back()->withInput()->withErrors(['estado' => 'Para activar la línea necesita al menos 2 estaciones.']);
            }
            if ($buses < $n || $buses > 2 * $n) {
                return back()->withInput()->withErrors(['estado' => "Para activar la línea necesita entre {$n} y ".(2 * $n)." buses (tiene {$buses})."]);
            }
        }

        try {
            $linea->update($datos);
        } catch (QueryException $e) {
            return $this->volverConErrorBD($e);
        }

        return redirect()->route('admin.lineas.show', $linea)->with('estado', 'Línea actualizada.');
    }

    public function destroy(Linea $linea): RedirectResponse
    {
        try {
            $linea->delete();
        } catch (QueryException $e) {
            return $this->volverConErrorBD($e);
        }

        return redirect()->route('admin.lineas.index')->with('estado', 'Línea eliminada. Sus buses quedaron sin línea asignada.');
    }

    /** Agrega una estación al final del recorrido de la línea. */
    public function agregarEstacion(Request $request, Linea $linea): RedirectResponse
    {
        $datos = $request->validate([
            'id_estacion' => ['required', 'uuid', 'exists:estaciones,id_estacion',
                Rule::unique('linea_estacion', 'id_estacion')->where('id_linea', $linea->id_linea)],
            'distancia_previa_km' => ['required', 'numeric', 'min:0', 'max:999'],
        ], ['id_estacion.unique' => 'Esa estación ya está en la línea.']);

        $ultimo = (int) DB::table('linea_estacion')->where('id_linea', $linea->id_linea)->max('orden_parada');

        try {
            DB::table('linea_estacion')->insert([
                'id_linea' => $linea->id_linea,
                'id_estacion' => $datos['id_estacion'],
                'orden_parada' => $ultimo + 1,
                'distancia_previa_km' => $ultimo === 0 ? 0 : $datos['distancia_previa_km'],
            ]);
        } catch (QueryException $e) {
            return $this->volverConErrorBD($e);
        }

        return back()->with('estado', 'Estación agregada al recorrido.');
    }

    /** Quita una estación del recorrido y vuelve a numerar el orden de las paradas. */
    public function quitarEstacion(Linea $linea, Estacion $estacion): RedirectResponse
    {
        $tabla = fn () => DB::table('linea_estacion')->where('id_linea', $linea->id_linea);
        $parada = $tabla()->where('id_estacion', $estacion->id_estacion)->first();
        abort_unless($parada, 404);

        try {
            DB::transaction(function () use ($tabla, $parada) {
                $k = $parada->orden_parada;
                $tabla()->where('id_estacion', $parada->id_estacion)->delete();

                // El tramo de la estación quitada se suma al de la siguiente (o queda en 0 si era la primera).
                $siguiente = $tabla()->where('orden_parada', $k + 1);
                $k === 1
                    ? $siguiente->update(['distancia_previa_km' => 0])
                    : $siguiente->update(['distancia_previa_km' => DB::raw('distancia_previa_km + '.(float) $parada->distancia_previa_km)]);

                // Renumerar en dos pasos para no chocar con UNIQUE(id_linea, orden_parada).
                $tabla()->where('orden_parada', '>', $k)->update(['orden_parada' => DB::raw('orden_parada + 1000')]);
                $tabla()->where('orden_parada', '>', 1000)->update(['orden_parada' => DB::raw('orden_parada - 1001')]);
            });
        } catch (QueryException $e) {
            return $this->volverConErrorBD($e);
        }

        return back()->with('estado', 'Estación quitada del recorrido.');
    }

    /** Guarda el horario de servicio de la línea (lunes a viernes, sábado, domingo y feriados). */
    public function guardarHorarios(Request $request, Linea $linea): RedirectResponse
    {
        $reglas = [];
        foreach (array_keys(HorarioLinea::TIPOS_DIA) as $tipo) {
            $reglas["horarios.$tipo.inicio"] = ['required', 'date_format:H:i'];
            $reglas["horarios.$tipo.fin"] = ['required', 'date_format:H:i', "after:horarios.$tipo.inicio"];
        }
        $datos = $request->validate($reglas, ['after' => 'La hora de fin debe ser después de la hora de inicio.']);

        DB::transaction(function () use ($linea, $datos) {
            foreach ($datos['horarios'] as $tipo => $horas) {
                $linea->horarios()->updateOrCreate(['tipo_dia' => $tipo], ['hora_inicio' => $horas['inicio'], 'hora_fin' => $horas['fin']]);
            }
        });

        return back()->with('estado', 'Horario de servicio actualizado.');
    }

    private function validar(Request $request, ?Linea $linea = null): array
    {
        return $request->validate([
            'codigo_linea' => ['required', 'string', 'max:20',
                Rule::unique('lineas', 'codigo_linea')->ignore($linea?->id_linea, 'id_linea')],
            'id_municipio' => ['required', 'uuid', 'exists:municipios,id_municipio'],
            'nombre' => ['nullable', 'string', 'max:100'],
            'tipo_servicio' => ['required', Rule::in(array_keys(Linea::TIPOS_SERVICIO))],
            'tarifa' => ['required', 'numeric', 'min:0', 'max:20'],
            'color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]) + ['ruta_circular' => $request->boolean('ruta_circular')];
    }

    private function municipios(): array
    {
        return Municipio::orderBy('nombre')->pluck('nombre', 'id_municipio')->all();
    }
}
