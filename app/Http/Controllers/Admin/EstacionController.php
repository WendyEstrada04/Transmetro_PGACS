<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ManejaErroresBD;
use App\Http\Controllers\Controller;
use App\Models\Acceso;
use App\Models\Estacion;
use App\Models\Molinete;
use App\Models\Municipio;
use App\Models\Parqueo;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Estaciones con sus accesos, molinetes y parqueos (puntos 2, 3, 6 y 11).
 * Toda estación nace con al menos 2 accesos (cada uno con su molinete).
 */
class EstacionController extends Controller
{
    use ManejaErroresBD;

    public function index(): View
    {
        return view('admin.estaciones.index', [
            'estaciones' => Estacion::with('municipio')
                ->withCount(['accesos', 'lineas', 'parqueos'])
                ->orderBy('nombre')
                ->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.estaciones.form', [
            'estacion' => new Estacion,
            'municipios' => $this->municipios(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $this->validar($request);
        $accesos = $request->validate([
            'acceso_1' => ['required', 'string', 'max:100'],
            'ip_1' => ['required', 'ip'],
            'acceso_2' => ['required', 'string', 'max:100', 'different:acceso_1'],
            'ip_2' => ['required', 'ip', 'different:ip_1'],
        ]);

        try {
            $estacion = DB::transaction(function () use ($datos, $accesos) {
                $estacion = Estacion::create($datos + ['aforo_actual' => 0]);
                foreach ([1, 2] as $n) {
                    $acceso = Acceso::create(['id_estacion' => $estacion->id_estacion, 'ubicacion_fisica' => $accesos["acceso_$n"]]);
                    Molinete::create(['id_acceso' => $acceso->id_acceso, 'ip_address' => $accesos["ip_$n"], 'estado_operativo' => 'activo']);
                }

                return $estacion;
            });
        } catch (QueryException $e) {
            return $this->volverConErrorBD($e);
        }

        return redirect()->route('admin.estaciones.show', $estacion)
            ->with('estado', 'Estación creada con 2 accesos. Recuerda asignar un guardia a cada acceso en Personal.');
    }

    public function show(Estacion $estacion): View
    {
        $estacion->load(['municipio', 'lineas', 'accesos.molinete', 'accesos.guardias.empleado', 'parqueos.bus', 'operadores.empleado']);

        return view('admin.estaciones.show', compact('estacion'));
    }

    public function edit(Estacion $estacion): View
    {
        return view('admin.estaciones.form', [
            'estacion' => $estacion,
            'municipios' => $this->municipios(),
        ]);
    }

    public function update(Request $request, Estacion $estacion): RedirectResponse
    {
        try {
            $estacion->update($this->validar($request));
        } catch (QueryException $e) {
            return $this->volverConErrorBD($e);
        }

        return redirect()->route('admin.estaciones.show', $estacion)->with('estado', 'Estación actualizada.');
    }

    // ---------------- Accesos y molinetes ----------------

    public function guardarAcceso(Request $request, Estacion $estacion): RedirectResponse
    {
        $datos = $request->validate([
            'ubicacion_fisica' => ['required', 'string', 'max:100'],
            'ip_address' => ['required', 'ip'],
        ]);

        try {
            DB::transaction(function () use ($estacion, $datos) {
                $acceso = Acceso::create(['id_estacion' => $estacion->id_estacion, 'ubicacion_fisica' => $datos['ubicacion_fisica']]);
                Molinete::create(['id_acceso' => $acceso->id_acceso, 'ip_address' => $datos['ip_address'], 'estado_operativo' => 'activo']);
            });
        } catch (QueryException $e) {
            return $this->volverConErrorBD($e);
        }

        return back()->with('estado', 'Acceso agregado. Asígnale un guardia de seguridad en Personal.');
    }

    public function actualizarAcceso(Request $request, Estacion $estacion, Acceso $acceso): RedirectResponse
    {
        abort_if($acceso->id_estacion !== $estacion->id_estacion, 404);
        $datos = $request->validate([
            'ubicacion_fisica' => ['required', 'string', 'max:100'],
            'ip_address' => ['required', 'ip'],
            'estado_operativo' => ['required', Rule::in(['activo', 'inactivo', 'mantenimiento'])],
        ]);

        try {
            DB::transaction(function () use ($acceso, $datos) {
                $acceso->update(['ubicacion_fisica' => $datos['ubicacion_fisica']]);
                Molinete::updateOrCreate(['id_acceso' => $acceso->id_acceso],
                    ['ip_address' => $datos['ip_address'], 'estado_operativo' => $datos['estado_operativo']]);
            });
        } catch (QueryException $e) {
            return $this->volverConErrorBD($e);
        }

        return back()->with('estado', 'Acceso actualizado.');
    }

    public function eliminarAcceso(Estacion $estacion, Acceso $acceso): RedirectResponse
    {
        abort_if($acceso->id_estacion !== $estacion->id_estacion, 404);

        try {
            DB::transaction(function () use ($acceso) {
                $acceso->molinete()->delete();
                $acceso->delete();
            });
        } catch (QueryException $e) {
            return $this->volverConErrorBD($e);
        }

        return back()->with('estado', 'Acceso eliminado.');
    }

    // ---------------- Parqueos ----------------

    public function guardarParqueo(Request $request, Estacion $estacion): RedirectResponse
    {
        $datos = $request->validate([
            'codigo_bahia' => ['required', 'string', 'max:20',
                Rule::unique('parqueos', 'codigo_bahia')->where('id_estacion', $estacion->id_estacion)],
        ]);

        Parqueo::create(['id_estacion' => $estacion->id_estacion, 'codigo_bahia' => $datos['codigo_bahia']]);

        return back()->with('estado', 'Parqueo agregado.');
    }

    public function eliminarParqueo(Estacion $estacion, Parqueo $parqueo): RedirectResponse
    {
        abort_if($parqueo->id_estacion !== $estacion->id_estacion, 404);

        if ($parqueo->id_bus_asignado) {
            return back()->withErrors(['bd' => 'El parqueo tiene un bus asignado. Primero cambia el bus a otro parqueo.']);
        }

        $parqueo->delete();

        return back()->with('estado', 'Parqueo eliminado.');
    }

    private function validar(Request $request): array
    {
        return $request->validate([
            'nombre' => ['required', 'string', 'max:150'],
            'direccion' => ['nullable', 'string', 'max:200'],
            'id_municipio' => ['required', 'uuid', 'exists:municipios,id_municipio'],
            'aforo_maximo' => ['required', 'integer', 'min:1', 'max:100000'],
            'latitud' => ['required', 'numeric', 'between:-90,90'],
            'longitud' => ['required', 'numeric', 'between:-180,180'],
        ]);
    }

    private function municipios(): array
    {
        return Municipio::orderBy('nombre')->pluck('nombre', 'id_municipio')->all();
    }
}
