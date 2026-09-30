<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ManejaErroresBD;
use App\Http\Controllers\Controller;
use App\Models\Municipio;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Municipalidades (punto 11).
 */
class MunicipioController extends Controller
{
    use ManejaErroresBD;

    public function index(): View
    {
        return view('admin.municipios.index', [
            'municipios' => Municipio::withCount(['lineas', 'estaciones'])->orderBy('nombre')->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.municipios.form', ['municipio' => new Municipio(['activo' => true])]);
    }

    public function store(Request $request): RedirectResponse
    {
        try {
            Municipio::create($this->validar($request));
        } catch (QueryException $e) {
            return $this->volverConErrorBD($e);
        }

        return redirect()->route('admin.municipios.index')->with('estado', 'Municipio creado.');
    }

    public function edit(Municipio $municipio): View
    {
        return view('admin.municipios.form', compact('municipio'));
    }

    public function update(Request $request, Municipio $municipio): RedirectResponse
    {
        try {
            $municipio->update($this->validar($request, $municipio));
        } catch (QueryException $e) {
            return $this->volverConErrorBD($e);
        }

        return redirect()->route('admin.municipios.index')->with('estado', 'Municipio actualizado.');
    }

    public function destroy(Municipio $municipio): RedirectResponse
    {
        try {
            $municipio->delete();
        } catch (QueryException $e) {
            return $this->volverConErrorBD($e);
        }

        return redirect()->route('admin.municipios.index')->with('estado', 'Municipio eliminado.');
    }

    private function validar(Request $request, ?Municipio $municipio = null): array
    {
        $datos = $request->validate([
            'codigo_iso' => ['required', 'string', 'max:10',
                Rule::unique('municipios', 'codigo_iso')->ignore($municipio?->id_municipio, 'id_municipio')],
            'nombre' => ['required', 'string', 'max:150'],
        ]);
        $datos['activo'] = $request->boolean('activo');

        return $datos;
    }
}
