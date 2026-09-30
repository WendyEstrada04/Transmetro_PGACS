<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ManejaErroresBD;
use App\Http\Controllers\Controller;
use App\Models\Recarga;
use App\Models\Tarjeta;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Tarjetas de acceso Transmetro, recargas y pagos (punto 18).
 */
class TarjetaController extends Controller
{
    use ManejaErroresBD;

    /** Formas de pago de la recarga (en máquinas de las estaciones o en línea). */
    private const METODOS = ['efectivo' => 'Efectivo', 'debito' => 'Tarjeta de débito', 'credito' => 'Tarjeta de crédito', 'en_linea' => 'Recarga en línea'];

    public function index(Request $request): View
    {
        return view('admin.tarjetas.index', [
            'tarjetas' => Tarjeta::withCount('transacciones')
                ->when($request->filled('buscar'), fn ($q) => $q->where(fn ($w) => $w
                    ->where('numero_tarjeta', 'ilike', '%'.$request->buscar.'%')
                    ->orWhere('nombre_titular', 'ilike', '%'.$request->buscar.'%')))
                ->when($request->filled('tipo'), fn ($q) => $q->where('tipo_tarjeta', $request->tipo))
                ->orderBy('numero_tarjeta')
                ->paginate(25)
                ->withQueryString(),
            'tipos' => Tarjeta::TIPOS,
        ]);
    }

    public function create(): View
    {
        return view('admin.tarjetas.create', [
            'numeroSugerido' => 'TC'.random_int(1000000000, 9999999999),
            'metodos' => self::METODOS,
            'tipos' => Tarjeta::TIPOS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'numero_tarjeta' => ['required', 'alpha_num', 'max:20', 'unique:tarjetas,numero_tarjeta'],
            'tipo_tarjeta' => ['required', Rule::in(array_keys(Tarjeta::TIPOS))],
            'nombre_titular' => ['required_unless:tipo_tarjeta,ciudadana', 'nullable', 'string', 'max:200'],
            'dpi_titular' => ['required_unless:tipo_tarjeta,ciudadana', 'nullable', 'digits:13'],
            'saldo_inicial' => ['nullable', 'numeric', 'min:'.Tarjeta::RECARGA_MINIMA, 'max:'.Tarjeta::RECARGA_MAXIMA],
            'metodo_pago' => ['required_with:saldo_inicial', 'nullable', Rule::in(array_keys(self::METODOS))],
        ], [
            'nombre_titular.required_unless' => 'Las tarjetas de adulto mayor y de persona con discapacidad son personalizadas: escribe el nombre del titular.',
            'dpi_titular.required_unless' => 'Escribe el DPI del titular (13 dígitos).',
        ]);
        $saldo = (float) ($datos['saldo_inicial'] ?? 0);
        $personalizada = $datos['tipo_tarjeta'] !== 'ciudadana';

        try {
            $tarjeta = DB::transaction(function () use ($datos, $saldo, $personalizada) {
                $tarjeta = Tarjeta::create([
                    'numero_tarjeta' => strtoupper($datos['numero_tarjeta']),
                    'tipo_tarjeta' => $datos['tipo_tarjeta'],
                    'nombre_titular' => $personalizada ? $datos['nombre_titular'] : null,
                    'dpi_titular' => $personalizada ? $datos['dpi_titular'] : null,
                    'costo_emision' => Tarjeta::COSTO_EMISION,
                    'viajes_gratis_restantes' => Tarjeta::VIAJES_GRATIS,
                    'saldo' => $saldo,
                    'estado' => 'activa',
                ]);
                if ($saldo > 0) {
                    Recarga::create(['id_tarjeta' => $tarjeta->id_tarjeta, 'monto' => $saldo, 'metodo_pago' => $datos['metodo_pago']]);
                }

                return $tarjeta;
            });
        } catch (QueryException $e) {
            return $this->volverConErrorBD($e);
        }

        return redirect()->route('admin.tarjetas.show', $tarjeta)
            ->with('estado', 'Tarjeta emitida (costo Q'.number_format(Tarjeta::COSTO_EMISION, 2).', incluye '.Tarjeta::VIAJES_GRATIS.' viajes gratis).');
    }

    public function show(Tarjeta $tarjeta): View
    {
        return view('admin.tarjetas.show', [
            'tarjeta' => $tarjeta,
            'recargas' => $tarjeta->recargas()->orderByDesc('fecha_hora')->limit(20)->get(),
            'pagos' => $tarjeta->transacciones()->with(['molinete.acceso.estacion', 'linea'])->orderByDesc('fecha_hora')->limit(20)->get(),
            'metodos' => self::METODOS,
            'tipos' => Tarjeta::TIPOS,
        ]);
    }

    public function recargar(Request $request, Tarjeta $tarjeta): RedirectResponse
    {
        if ($tarjeta->estado !== 'activa') {
            return back()->withErrors(['bd' => 'Solo se pueden recargar tarjetas activas.']);
        }

        $datos = $request->validate([
            'monto' => ['required', 'numeric', 'min:'.Tarjeta::RECARGA_MINIMA, 'max:'.Tarjeta::RECARGA_MAXIMA],
            'metodo_pago' => ['required', Rule::in(array_keys(self::METODOS))],
        ]);

        try {
            DB::transaction(function () use ($tarjeta, $datos) {
                Recarga::create(['id_tarjeta' => $tarjeta->id_tarjeta, 'monto' => $datos['monto'], 'metodo_pago' => $datos['metodo_pago']]);
                $tarjeta->increment('saldo', (float) $datos['monto']);
            });
        } catch (QueryException $e) {
            return $this->volverConErrorBD($e);
        }

        return back()->with('estado', 'Recarga de Q'.number_format((float) $datos['monto'], 2).' aplicada.');
    }

    public function cambiarEstado(Request $request, Tarjeta $tarjeta): RedirectResponse
    {
        $datos = $request->validate(['estado' => ['required', Rule::in(['activa', 'bloqueada', 'vencida'])]]);
        $tarjeta->update($datos);

        return back()->with('estado', 'Estado de la tarjeta actualizado.');
    }
}
