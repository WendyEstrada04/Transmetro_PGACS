<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ManejaErroresBD;
use App\Http\Controllers\Controller;
use App\Models\Acceso;
use App\Models\Estacion;
use App\Models\Molinete;
use App\Services\Auditoria;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Estaciones del Transmetro con sus accesos, molinetes y parqueos (puntos 2, 3, 6 y 11).
 * Las estaciones y sus accesos son los oficiales: no se crean ni se eliminan desde el sistema.
 * Lo que cambia la operación diaria es el ESTADO de cada acceso y su molinete
 * (activo, en mantenimiento o inactivo).
 */
class EstacionController extends Controller
{
    use ManejaErroresBD;

    public const ESTADOS_ACCESO = ['activo' => 'Activo', 'mantenimiento' => 'En mantenimiento', 'inactivo' => 'Inactivo'];

    public function index(): View
    {
        return view('admin.estaciones.index', [
            'estaciones' => Estacion::with(['municipio', 'lineas'])
                ->withCount(['accesos', 'lineas', 'parqueos'])
                ->orderBy('nombre')
                ->get(),
        ]);
    }

    public function show(Estacion $estacion): View
    {
        $estacion->load(['municipio', 'lineas', 'accesos.molinete', 'accesos.guardias.empleado', 'parqueos.bus']);

        return view('admin.estaciones.show', ['estacion' => $estacion, 'estados' => self::ESTADOS_ACCESO]);
    }

    /** Cambia el estado del molinete de un acceso. */
    public function cambiarEstadoAcceso(Request $request, Estacion $estacion, Acceso $acceso): RedirectResponse
    {
        abort_if($acceso->id_estacion !== $estacion->id_estacion, 404);
        $estado = $request->validate(['estado_operativo' => ['required', Rule::in(array_keys(self::ESTADOS_ACCESO))]])['estado_operativo'];

        $antes = Molinete::where('id_acceso', $acceso->id_acceso)->value('estado_operativo');
        try {
            DB::transaction(function () use ($acceso, $estacion, $estado, $antes) {
                Molinete::where('id_acceso', $acceso->id_acceso)->update(['estado_operativo' => $estado]);
                Auditoria::cambio('accesos', $acceso->id_acceso,
                    ['estacion' => $estacion->nombre, 'acceso' => $acceso->ubicacion_fisica, 'estado_operativo' => $antes],
                    ['estacion' => $estacion->nombre, 'acceso' => $acceso->ubicacion_fisica, 'estado_operativo' => $estado]);
            });
        } catch (QueryException $e) {
            return $this->volverConErrorBD($e);
        }

        return back()->with('estado', "{$acceso->ubicacion_fisica}: ".mb_strtolower(self::ESTADOS_ACCESO[$estado]).'.');
    }
}
