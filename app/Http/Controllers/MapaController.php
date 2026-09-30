<?php

namespace App\Http\Controllers;

use App\Models\Linea;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Mapa de rutas del Transmetro: líneas con su color oficial y estaciones en orden.
 * Lo ven el administrador y los operadores (a quienes se les resalta su estación).
 */
class MapaController extends Controller
{
    public function __invoke(Request $request): View
    {
        $lineas = Linea::with(['estaciones', 'horarios'])
            ->withCount('buses')
            ->orderBy('codigo_linea')
            ->get();

        return view('mapa', [
            'lineas' => $lineas,
            'lineasMapa' => self::datos($lineas),
            'resaltar' => $request->user()->empleado?->operador?->id_estacion_asignada,
        ]);
    }

    /** Datos que usa el mapa (Leaflet): líneas, color, si es circular y estaciones en orden. */
    public static function datos(Collection $lineas): array
    {
        return $lineas->map(fn (Linea $linea) => [
            'codigo' => $linea->codigo_linea,
            'nombre' => $linea->nombre,
            'color' => $linea->color,
            'circular' => (bool) $linea->ruta_circular,
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
