<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BitacoraAuditoria;
use App\Models\Bus;
use App\Models\MantenimientoBus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Mantenimiento de los buses (taller), desde la ficha de cada bus en la administración.
 * Los operadores de estación reportan las fallas; aquí se registran los mantenimientos, se pasan las fallas
 * al taller y se dan por resueltas. Una falla de prioridad alta saca el bus de servicio hasta que se resuelva.
 */
class MantenimientoController extends Controller
{
    /** Reporta una falla o registra un mantenimiento ya realizado. */
    public function store(Request $request, Bus $bus): RedirectResponse
    {
        $datos = $request->validate([
            'tipo' => ['required', Rule::in(array_keys(MantenimientoBus::TIPOS))],
            'categoria' => ['required', Rule::in(array_keys(MantenimientoBus::CATEGORIAS))],
            'prioridad' => ['required', Rule::in(array_keys(MantenimientoBus::PRIORIDADES))],
            'descripcion' => ['required', 'string', 'min:5', 'max:500'],
            'kilometraje' => ['nullable', 'integer', 'min:0', 'max:5000000'],
        ]);

        $realizado = $datos['tipo'] !== 'falla';   // un mantenimiento registrado ya se hizo
        DB::transaction(function () use ($bus, $datos, $realizado, $request) {
            $registro = MantenimientoBus::create($datos + [
                'id_bus' => $bus->id_bus,
                'estado' => $realizado ? 'resuelto' : 'reportado',
                'fecha_reporte' => now(),
                'fecha_cierre' => $realizado ? now() : null,
            ]);
            if (! $realizado && $datos['prioridad'] === 'alta' && $bus->estado_logico !== 'en_mantenimiento') {
                $antes = $bus->estado_logico;
                $bus->update(['estado_logico' => 'en_mantenimiento']);
                $this->bitacora($request, 'buses', $bus->id_bus, ['estado_logico' => $antes], ['estado_logico' => 'en_mantenimiento']);
            }
            $this->bitacora($request, 'mantenimientos_bus', $registro->id_mantenimiento, null, $datos, 'INSERT');
        });

        return $this->volver($bus)->with('estado', match (true) {
            $realizado => 'Mantenimiento registrado en el historial del bus.',
            $datos['prioridad'] === 'alta' => "Falla registrada. El bus {$bus->placa} quedó fuera de servicio (en mantenimiento).",
            default => 'Falla registrada.',
        });
    }

    /** Pasa un reporte al taller o lo da por resuelto. Al resolver las fallas graves, el bus vuelve a estar disponible. */
    public function update(Request $request, MantenimientoBus $mantenimiento): RedirectResponse
    {
        if (! $mantenimiento->abierto()) {
            return $this->volver($mantenimiento->bus)->with('estado', 'Ese reporte ya está resuelto.');
        }
        $estado = $request->validate(['estado' => ['required', Rule::in(['en_proceso', 'resuelto'])]])['estado'];
        $bus = $mantenimiento->bus;

        DB::transaction(function () use ($mantenimiento, $estado, $bus, $request) {
            $antes = $mantenimiento->estado;
            $mantenimiento->update(['estado' => $estado, 'fecha_cierre' => $estado === 'resuelto' ? now() : null]);
            $this->bitacora($request, 'mantenimientos_bus', $mantenimiento->id_mantenimiento, ['estado' => $antes], ['estado' => $estado]);

            $pendientes = MantenimientoBus::where('id_bus', $bus->id_bus)->where('estado', '!=', 'resuelto')->where('prioridad', 'alta')->exists();
            if ($estado === 'resuelto' && ! $pendientes && $bus->estado_logico === 'en_mantenimiento') {
                $bus->update(['estado_logico' => 'en_espera']);
                $this->bitacora($request, 'buses', $bus->id_bus, ['estado_logico' => 'en_mantenimiento'], ['estado_logico' => 'en_espera']);
            }
        });

        return $this->volver($bus)->with('estado', $estado === 'resuelto'
            ? ($bus->refresh()->estado_logico === 'en_espera' ? 'Reporte resuelto. El bus vuelve a estar disponible.' : 'Reporte resuelto.')
            : 'Reporte enviado al taller.');
    }

    private function volver(Bus $bus): RedirectResponse
    {
        return redirect()->to(route('admin.buses.edit', $bus).'#mantenimiento');
    }

    private function bitacora(Request $request, string $tabla, string $id, ?array $antes, ?array $despues, string $accion = 'UPDATE'): void
    {
        BitacoraAuditoria::create([
            'id_usuario' => $request->user()->id,
            'tabla_afectada' => $tabla,
            'id_registro_afectado' => $id,
            'accion' => $accion,
            'datos_previos' => $antes,
            'datos_nuevos' => $despues,
        ]);
    }
}
