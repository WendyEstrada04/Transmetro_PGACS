<?php

namespace App\Http\Controllers;

use App\Models\Linea;
use App\Services\MonitoreoBuses;
use App\Services\SimuladorOperacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Mapa de rutas del Transmetro con el monitoreo en vivo de los buses (tipo Waze): dónde va cada bus,
 * en qué estación está o hacia cuál se dirige. Solo para ver: cada bus lo maneja su operador.
 * Lo ven el administrador y los operadores.
 */
class MapaController extends Controller
{
    public function __invoke(Request $request): View
    {
        $lineas = Linea::with(['estaciones', 'horarios'])
            ->withCount('buses')
            ->enOrden()
            ->get();

        // El operador trabaja en una estación: el mapa abre con las líneas que pasan por ella y la resalta.
        $estacion = $request->user()->empleado?->operador?->estacion()->with('lineas:id_linea,codigo_linea')->first();
        $propias = $estacion ? $estacion->lineas->pluck('codigo_linea')->sort(SORT_NATURAL)->values()->all() : [];

        return view('mapa', [
            'lineas' => $propias ? $lineas->sortBy(fn ($l) => in_array($l->codigo_linea, $propias, true) ? 0 : 1)->values() : $lineas,
            'lineasPropias' => $propias,
            'estacionPropia' => $estacion ? ['id' => $estacion->id_estacion, 'nombre' => $estacion->nombre,
                'lat' => (float) $estacion->latitud, 'lon' => (float) $estacion->longitud] : null,
            'lineasMapa' => self::datos($lineas),
            'buses' => app(MonitoreoBuses::class)->posiciones($this->busPropio($request)),
            'ahora' => MonitoreoBuses::ahoraMs(),
            'simulacionActiva' => app(SimuladorOperacion::class)->activa(),
        ]);
    }

    /** Posiciones actuales de los buses (el mapa las pide cada pocos segundos). */
    public function buses(Request $request, MonitoreoBuses $monitoreo): JsonResponse
    {
        return response()->json([
            'ahora' => MonitoreoBuses::ahoraMs(),
            'buses' => $monitoreo->posiciones($this->busPropio($request)),
        ]);
    }

    /** Botón de la simulación en el monitoreo: la inicia o la pone en pausa. */
    public function simulacion(Request $request, SimuladorOperacion $simulador): JsonResponse
    {
        // Iniciar o pausar la simulación afecta a toda la red: solo el administrador.
        abort_unless($request->user()->esAdministrador(), 403, 'Solo el administrador inicia o pausa la simulación.');
        $request->validate(['accion' => ['required', 'in:iniciar,pausar']]);
        $request->accion === 'iniciar' ? $simulador->activar() : $simulador->pausar();

        return response()->json(['activa' => $simulador->activa()]);
    }

    /**
     * Mientras la simulación está activa, el monitoreo pide un ciclo cada pocos segundos
     * (llegan pasajeros y avanzan los buses que ya llegaron a su siguiente estación) y recibe las posiciones nuevas.
     */
    public function ciclo(Request $request, SimuladorOperacion $simulador, MonitoreoBuses $monitoreo): JsonResponse
    {
        $simulador->cicloDesdePagina($request->user()->id);

        return response()->json([
            'activa' => $simulador->activa(),
            'ahora' => MonitoreoBuses::ahoraMs(),
            'buses' => $monitoreo->posiciones($this->busPropio($request)),
        ]);
    }

    /** Bus que se resalta en el mapa. Los operadores trabajan por estación, así que ninguno es "su" bus. */
    private function busPropio(Request $request): ?string
    {
        return null;
    }

    /** Datos que usa el mapa (Leaflet): líneas, color, si es circular, trazo por las calles y estaciones en orden. */
    public static function datos(Collection $lineas): array
    {
        return $lineas->map(fn (Linea $linea) => [
            'codigo' => $linea->codigo_linea,
            'nombre' => $linea->nombre,
            'color' => $linea->color,
            'texto' => $linea->colorTexto(),
            'circular' => (bool) $linea->ruta_circular,
            'trazo' => $linea->tieneTrazo() ? $linea->trazo : [],
            'estaciones' => $linea->estaciones->map(fn ($e) => [
                'id' => $e->id_estacion,
                'nombre' => $e->nombre,
                'direccion' => $e->direccion,
                'lat' => (float) $e->latitud,
                'lon' => (float) $e->longitud,
                'orden' => (int) $e->pivot->orden_parada,
            ])->values()->all(),
        ])->values()->all();
    }
}
