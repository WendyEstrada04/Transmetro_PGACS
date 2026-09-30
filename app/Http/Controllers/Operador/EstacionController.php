<?php

namespace App\Http\Controllers\Operador;

use App\Http\Controllers\Controller;
use App\Models\AlertaSistema;
use App\Models\BitacoraAuditoria;
use App\Models\Bus;
use App\Models\Estacion;
use App\Models\Linea;
use App\Models\TransaccionPago;
use App\Services\CobroPasaje;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
        $estacion->load(['municipio', 'lineas.horarios', 'accesos.molinete', 'accesos.guardias.empleado', 'parqueos.bus']);

        return view('operador.estacion', [
            'estacion' => $estacion,
            'alertas' => $estacion->alertas()->where('estado', 'abierta')->orderByDesc('fecha_creacion')->get(),
            'buses' => Bus::with('linea')
                ->whereIn('id_linea_asignada', $estacion->lineas->pluck('id_linea'))
                ->orderBy('placa')
                ->get(),
            'pases' => TransaccionPago::with(['molinete.acceso', 'linea', 'tarjeta'])
                ->whereIn('id_molinete', $estacion->accesos->pluck('molinete.id_molinete')->filter())
                ->orderByDesc('fecha_hora')
                ->limit(10)
                ->get(),
            'metodosPago' => TransaccionPago::METODOS,
        ]);
    }

    /** Registra el paso de un pasajero por un molinete de la estación y cobra el pasaje (punto 18). */
    public function registrarPase(Request $request, CobroPasaje $cobro): RedirectResponse
    {
        $estacion = $this->estacionDelOperador($request);
        $estacion->load(['accesos.molinete', 'lineas']);

        $datos = $request->validate([
            'id_acceso' => ['required', Rule::in($estacion->accesos->pluck('id_acceso')->all())],
            'id_linea' => ['required', Rule::in($estacion->lineas->pluck('id_linea')->all())],
            'metodo_pago' => ['required', Rule::in(array_keys(TransaccionPago::METODOS))],
            'numero_tarjeta' => ['required_if:metodo_pago,tarjeta_ciudadana', 'nullable', 'alpha_num', 'max:20'],
        ], ['numero_tarjeta.required_if' => 'Escribe el número de la Tarjeta Ciudadana.']);

        $molinete = $estacion->accesos->firstWhere('id_acceso', $datos['id_acceso'])->molinete;
        abort_unless($molinete, 422, 'Ese acceso no tiene molinete.');

        $pago = $cobro->cobrar($molinete, Linea::findOrFail($datos['id_linea']), $datos['metodo_pago'], $datos['numero_tarjeta'] ?? null);

        return back()->with('estado', $pago->viaje_gratis
            ? 'Pase registrado: viaje gratis de la Tarjeta Ciudadana.'
            : 'Pase registrado: Q'.number_format((float) $pago->monto, 2).' cobrados.');
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
