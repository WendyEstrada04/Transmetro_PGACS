<?php

namespace App\Http\Controllers\Operador;

use App\Http\Controllers\Controller;
use App\Models\AlertaSistema;
use App\Models\BitacoraAuditoria;
use App\Models\Bus;
use App\Models\Estacion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Panel del operador de estación (punto 16): la PC de trabajo de cada estación.
 * Muestra el aforo, las alertas (punto 14), los buses de sus líneas con la regla
 * de espera del 25 % (punto 15) y los accesos con su personal de seguridad.
 */
class EstacionController extends Controller
{
    public function show(Request $request): View
    {
        $estacion = $this->estacionDelOperador($request);
        $estacion->load(['municipio', 'lineas', 'accesos.molinete', 'accesos.guardias.empleado', 'parqueos.bus']);

        return view('operador.estacion', [
            'estacion' => $estacion,
            'alertas' => $estacion->alertas()->where('estado', 'abierta')->orderByDesc('fecha_creacion')->get(),
            'buses' => Bus::with('linea')
                ->whereIn('id_linea_asignada', $estacion->lineas->pluck('id_linea'))
                ->orderBy('placa')
                ->get(),
        ]);
    }

    /** Registra cuántas personas hay en la estación; si pasa del 150 % la base de datos crea la alerta. */
    public function actualizarAforo(Request $request): RedirectResponse
    {
        $estacion = $this->estacionDelOperador($request);
        $datos = $request->validate([
            'aforo_actual' => ['required', 'integer', 'min:0', 'max:100000'],
        ]);

        $anterior = $estacion->aforo_actual;
        $estacion->update(['aforo_actual' => $datos['aforo_actual']]);
        $this->registrarBitacora($request, 'estaciones', $estacion->id_estacion,
            ['aforo_actual' => $anterior], ['aforo_actual' => $estacion->aforo_actual]);

        return back()->with('estado', 'Aforo actualizado.');
    }

    /** Marca como atendida una alerta de la estación del operador. */
    public function atenderAlerta(Request $request, AlertaSistema $alerta): RedirectResponse
    {
        $estacion = $this->estacionDelOperador($request);
        abort_if($alerta->id_estacion !== $estacion->id_estacion, 403, 'Esa alerta no pertenece a tu estación.');

        $alerta->update(['estado' => 'atendida', 'fecha_cierre' => now()]);
        $this->registrarBitacora($request, 'alertas_sistema', $alerta->id_alerta,
            ['estado' => 'abierta'], ['estado' => 'atendida']);

        return back()->with('estado', 'Alerta marcada como atendida.');
    }

    private function estacionDelOperador(Request $request): Estacion
    {
        $estacion = $request->user()->empleado?->operador?->estacion;
        abort_unless($estacion, 403, 'Tu usuario no tiene una estación asignada.');

        return $estacion;
    }

    private function registrarBitacora(Request $request, string $tabla, string $id, array $antes, array $despues): void
    {
        BitacoraAuditoria::create([
            'id_usuario' => $request->user()->id,
            'id_empleado_usuario' => $request->user()->id_empleado,
            'tabla_afectada' => $tabla,
            'id_registro_afectado' => $id,
            'accion' => 'UPDATE',
            'datos_previos' => $antes,
            'datos_nuevos' => $despues,
        ]);
    }
}
