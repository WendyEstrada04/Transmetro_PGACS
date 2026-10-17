<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ManejaErroresBD;
use App\Http\Controllers\Controller;
use App\Models\Linea;
use App\Services\Auditoria;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Líneas del Transmetro (puntos 1, 2, 5, 8, 12 y 13).
 * Las líneas, su recorrido, su trazo y su horario son los oficiales del Transmetro de Guatemala:
 * no se crean, editan ni eliminan desde el sistema. Lo único que cambia la operación diaria es
 * su ESTADO (activa, en mantenimiento o inactiva, por ejemplo por un cierre temporal).
 * Para ACTIVARLA debe tener al menos 2 estaciones y entre n y 2n buses (n = estaciones).
 */
class LineaController extends Controller
{
    use ManejaErroresBD;

    public const ESTADOS = ['activa' => 'Activa', 'mantenimiento' => 'En mantenimiento', 'inactiva' => 'Inactiva'];

    public function index(): View
    {
        return view('admin.lineas.index', [
            'lineas' => Linea::with(['municipio', 'estaciones', 'horarios'])->withCount(['estaciones', 'buses'])->enOrden()->get(),
        ]);
    }

    public function show(Linea $linea): View
    {
        $linea->load(['municipio', 'horarios', 'estaciones.municipio', 'buses.parqueo.estacion', 'buses.piloto.empleado']);

        return view('admin.lineas.show', [
            'linea' => $linea,
            'accesos' => $linea->accesos()->load('estacion'),
            'estados' => self::ESTADOS,
        ]);
    }

    /** Cambia el estado de servicio de la línea. */
    public function cambiarEstado(Request $request, Linea $linea): RedirectResponse
    {
        $estado = $request->validate(['estado' => ['required', Rule::in(array_keys(self::ESTADOS))]])['estado'];

        if ($estado === 'activa') {
            $n = $linea->estaciones()->count();
            $buses = $linea->buses()->count();
            if ($n < 2) {
                return back()->withErrors(['estado' => 'Para activar la línea necesita al menos 2 estaciones.']);
            }
            if ($buses < $n || $buses > 2 * $n) {
                return back()->withErrors(['estado' => "Para activar la línea necesita entre {$n} y ".(2 * $n)." buses (tiene {$buses})."]);
            }
        }

        $antes = $linea->estado;
        try {
            DB::transaction(function () use ($linea, $estado, $antes) {
                $linea->update(['estado' => $estado]);
                Auditoria::cambio('lineas', $linea->id_linea, ['linea' => $linea->codigo_linea, 'estado' => $antes], ['linea' => $linea->codigo_linea, 'estado' => $estado]);
            });
        } catch (QueryException $e) {
            return $this->volverConErrorBD($e);
        }

        return back()->with('estado', "Línea {$linea->codigo_linea}: ".mb_strtolower(self::ESTADOS[$estado]).'.');
    }
}
