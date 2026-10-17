<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Estacion;
use App\Services\Auditoria;
use App\Services\SimuladorOperacion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Pantalla de simulación de la operación (puntos 1, 14 y 15).
 * Mientras la página está abierta y la simulación activa, el navegador pide un ciclo
 * cada pocos segundos; los buses avanzan en tiempo real según la distancia de cada tramo.
 */
class SimulacionController extends Controller
{
    public function __construct(private SimuladorOperacion $simulador) {}

    public function index(): View
    {
        return view('admin.simulacion.index', $this->datos() + [
            'estacionesPico' => Estacion::whereHas('lineas')->orderBy('nombre')->pluck('nombre', 'id_estacion')->all(),
        ]);
    }

    /** Ejecuta un ciclo y devuelve el panel actualizado (lo usa el navegador cada pocos segundos). */
    public function ciclo(Request $request): View|RedirectResponse
    {
        // Botón "Ejecutar un ciclo": siempre. Avance automático de la página: solo si no corre el motor en segundo plano.
        $automatico = $request->expectsJson() || $request->ajax();
        $automatico ? $this->simulador->cicloDesdePagina($request->user()->id) : $this->simulador->ciclo($request->user()->id);

        return $request->expectsJson() || $request->ajax()
            ? view('admin.simulacion._estado', $this->datos())
            : back()->with('estado', 'Ciclo de simulación ejecutado.');
    }

    public function estado(): View
    {
        return view('admin.simulacion._estado', $this->datos());
    }

    public function iniciar(): RedirectResponse
    {
        $this->simulador->activar();

        return back()->with('estado', 'Simulación iniciada.');
    }

    public function pausar(): RedirectResponse
    {
        $this->simulador->pausar();

        return back()->with('estado', 'Simulación en pausa.');
    }

    public function horaPico(Request $request): RedirectResponse
    {
        $datos = $request->validate(['id_estacion' => ['required', 'uuid', 'exists:estaciones,id_estacion']]);
        $estacion = DB::transaction(function () use ($datos) {
            $estacion = Estacion::findOrFail($datos['id_estacion']);
            $antes = $estacion->aforo_actual;
            $estacion = $this->simulador->horaPico($estacion);
            Auditoria::cambio('estaciones', $estacion->id_estacion, ['estacion' => $estacion->nombre, 'aforo_actual' => $antes, 'origen' => null],
                ['estacion' => $estacion->nombre, 'aforo_actual' => $estacion->aforo_actual, 'origen' => 'Hora pico provocada desde la simulación']);

            return $estacion;
        });

        return back()->with('estado', "Hora pico en {$estacion->nombre}: {$estacion->aforo_actual}/{$estacion->aforo_maximo} personas.");
    }

    public function reiniciar(): RedirectResponse
    {
        $this->simulador->reiniciar();
        Auditoria::registrar('estaciones', null, 'UPDATE', null, ['aforo_actual' => 'Reiniciado entre el 20 % y el 50 % del aforo en todas las estaciones', 'origen' => 'Simulación']);

        return back()->with('estado', 'Aforos reiniciados.');
    }

    private function datos(): array
    {
        return [
            'activa' => $this->simulador->activa(),
            'ciclo' => $this->simulador->numeroCiclo(),
            'eventos' => $this->simulador->eventos(),
            'masLlenas' => Estacion::whereHas('lineas')
                ->orderByRaw('aforo_actual::numeric / NULLIF(aforo_maximo, 0) DESC')
                ->limit(8)
                ->get(),
        ] + $this->contadores();
    }

    /** Los cinco contadores del panel en una sola consulta. */
    private function contadores(): array
    {
        $hoy = now()->startOfDay()->toDateTimeString();   // hora de Guatemala (la base remota puede estar en UTC)
        $c = DB::selectOne(<<<'SQL'
            SELECT (SELECT count(*) FROM alertas_sistema WHERE estado = 'abierta' AND tipo_alerta IN ('refuerzo_bus', 'bus_lleno')) AS abiertas,
                   (SELECT count(*) FROM alertas_sistema WHERE estado = 'atendida' AND fecha_cierre >= ?) AS atendidas,
                   (SELECT count(*) FROM buses WHERE estado_logico = 'en_ruta') AS en_ruta,
                   (SELECT count(*) FROM buses WHERE estado_logico = 'en_espera' AND id_linea_asignada IS NOT NULL) AS reserva,
                   (SELECT count(*) FROM viajes WHERE estado = 'en_curso') AS viajes
            SQL, [$hoy]);

        return [
            'alertasAbiertas' => (int) $c->abiertas,
            'alertasAtendidasHoy' => (int) $c->atendidas,
            'busesEnRuta' => (int) $c->en_ruta,
            'busesReserva' => (int) $c->reserva,
            'viajesEnCurso' => (int) $c->viajes,
        ];
    }
}
