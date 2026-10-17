<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ManejaErroresBD;
use App\Http\Controllers\Controller;
use App\Models\BitacoraAuditoria;
use App\Models\Tarjeta;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Tarjeta Ciudadana: consulta y control de estado (punto 18).
 *
 * La emisión, la afiliación, la reposición y las recargas se hacen en los puntos de atención (POCAE)
 * y en los puntos de recarga. Desde el sistema del Transmetro el administrador:
 *  - consulta las tarjetas, su saldo, sus recargas y sus viajes;
 *  - bloquea una tarjeta (robo o extravío, uso indebido, …) y la desbloquea;
 *  - la da de baja: queda anulada y ya no cambia de estado.
 * Cada cambio queda en la bitácora de auditoría con su motivo.
 */
class TarjetaController extends Controller
{
    use ManejaErroresBD;

    /** Formas de pago con que se hizo la recarga (para mostrar el historial). */
    private const METODOS = ['efectivo' => 'Efectivo', 'debito' => 'Tarjeta de débito', 'credito' => 'Tarjeta de crédito', 'en_linea' => 'Recarga en línea'];

    public function index(Request $request): View
    {
        $buscar = trim((string) $request->buscar);
        $digitos = Tarjeta::normalizarNumero($buscar);
        $estado = array_key_exists((string) $request->estado, Tarjeta::ESTADOS) ? $request->estado : null;
        $tipo = array_key_exists((string) $request->tipo, Tarjeta::TIPOS) ? $request->tipo : null;

        $filtro = fn ($q) => $q
            ->when($buscar !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('nombre_titular', 'ilike', '%'.$buscar.'%')
                ->when($digitos !== '', fn ($x) => $x->orWhere('numero_tarjeta', 'like', '%'.$digitos.'%'))))
            ->when($tipo, fn ($q) => $q->where('tipo_tarjeta', $tipo));

        return view('admin.tarjetas.index', [
            'tarjetas' => Tarjeta::withCount('transacciones')
                ->tap($filtro)
                ->when($estado, fn ($q) => $q->where('estado', $estado))
                ->orderBy('numero_tarjeta')
                ->paginate(25)
                ->withQueryString(),
            'conteo' => Tarjeta::query()->tap($filtro)->selectRaw('estado, count(*) AS n')->groupBy('estado')->pluck('n', 'estado'),
            'tipos' => Tarjeta::TIPOS,
            'estadoActual' => $estado,
        ]);
    }

    public function show(Tarjeta $tarjeta): View
    {
        $tarjeta->load(['anterior:id_tarjeta,numero_tarjeta', 'reposicion:id_tarjeta,numero_tarjeta,id_tarjeta_anterior']);

        return view('admin.tarjetas.show', [
            'tarjeta' => $tarjeta,
            'recargas' => $tarjeta->recargas()->orderByDesc('fecha_hora')->limit(20)->get(),
            'pagos' => $tarjeta->transacciones()->with(['molinete.acceso.estacion', 'estacion:id_estacion,nombre', 'bus:id_bus,placa', 'linea'])->orderByDesc('fecha_hora')->limit(20)->get(),
            'metodos' => self::METODOS,
            'tipos' => Tarjeta::TIPOS,
            'cortesiasHoy' => $tarjeta->cortesiasRestantesHoy(),
        ]);
    }

    /** Bloquea una tarjeta activa: no puede pagar ni recargarse hasta que se desbloquee. */
    public function bloquear(Request $request, Tarjeta $tarjeta): RedirectResponse
    {
        if ($tarjeta->estado !== 'activa') {
            return back()->withErrors(['bd' => 'Solo se bloquean tarjetas activas.']);
        }
        $motivo = $this->motivo($request, Tarjeta::MOTIVOS_BLOQUEO);

        return $this->cambiar($request, $tarjeta, 'bloqueada', $motivo, "Tarjeta {$tarjeta->numeroFormateado()} bloqueada: {$motivo}.");
    }

    /** Desbloquea una tarjeta bloqueada: vuelve a funcionar con su saldo. */
    public function desbloquear(Request $request, Tarjeta $tarjeta): RedirectResponse
    {
        if ($tarjeta->estado !== 'bloqueada') {
            return back()->withErrors(['bd' => 'Solo se desbloquean tarjetas bloqueadas.']);
        }

        return $this->cambiar($request, $tarjeta, 'activa', 'Desbloqueada', "Tarjeta {$tarjeta->numeroFormateado()} desbloqueada: vuelve a funcionar.");
    }

    /** Da de baja la tarjeta: queda anulada y no se puede volver a activar. */
    public function darDeBaja(Request $request, Tarjeta $tarjeta): RedirectResponse
    {
        if ($tarjeta->estadoDefinitivo()) {
            return back()->withErrors(['bd' => 'Esta tarjeta ya está '.mb_strtolower($tarjeta->estadoTexto()).'.']);
        }
        $motivo = $this->motivo($request, Tarjeta::MOTIVOS_BAJA, '_baja');

        return $this->cambiar($request, $tarjeta, 'baja', $motivo, "Tarjeta {$tarjeta->numeroFormateado()} dada de baja: {$motivo}.");
    }

    /** Motivo elegido de la lista, con la observación si se escribió una. */
    private function motivo(Request $request, array $motivos, string $sufijo = ''): string
    {
        [$campo, $nota] = ['motivo'.$sufijo, 'observacion'.$sufijo];
        $datos = $request->validate([
            $campo => ['required', Rule::in(array_keys($motivos))],
            $nota => ['nullable', 'string', 'max:120'],
        ], [$campo.'.required' => 'Elige el motivo.', $campo.'.in' => 'Elige un motivo de la lista.']);

        return trim($motivos[$datos[$campo]].(filled($datos[$nota] ?? null) ? ' — '.$datos[$nota] : ''));
    }

    /** Cambia el estado y lo registra en la bitácora de auditoría. */
    private function cambiar(Request $request, Tarjeta $tarjeta, string $estado, string $motivo, string $mensaje): RedirectResponse
    {
        $antes = ['estado' => $tarjeta->estado, 'motivo_estado' => $tarjeta->motivo_estado];

        try {
            DB::transaction(function () use ($request, $tarjeta, $estado, $motivo, $antes) {
                $tarjeta->update(['estado' => $estado, 'motivo_estado' => $motivo, 'fecha_estado' => now()]);
                BitacoraAuditoria::create([
                    'id_usuario' => $request->user()->id,
                    'tabla_afectada' => 'tarjetas',
                    'id_registro_afectado' => $tarjeta->id_tarjeta,
                    'accion' => 'UPDATE',
                    'datos_previos' => $antes,
                    'datos_nuevos' => ['estado' => $estado, 'motivo_estado' => $motivo],
                ]);
            });
        } catch (QueryException $e) {
            return $this->volverConErrorBD($e);
        }

        return back()->with('estado', $mensaje);
    }
}
