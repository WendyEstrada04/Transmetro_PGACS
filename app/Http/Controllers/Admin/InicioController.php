<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AlertaSistema;
use App\Models\Bus;
use App\Models\Estacion;
use App\Models\Linea;
use App\Models\TransaccionPago;
use Illuminate\View\View;

/**
 * Panel del administrador (municipalidad): resumen general del sistema.
 */
class InicioController extends Controller
{
    public function __invoke(): View
    {
        $lineas = Linea::with('municipio')
            ->withCount(['estaciones', 'buses'])
            ->orderBy('codigo_linea')
            ->get();

        return view('admin.inicio', [
            'totales' => [
                'lineas' => $lineas->count(),
                'estaciones' => Estacion::count(),
                'buses' => Bus::count(),
                'busesEnLinea' => Bus::whereNotNull('id_linea_asignada')->count(),
                'alertasAbiertas' => AlertaSistema::abiertas()->count(),
                'pagosHoy' => TransaccionPago::whereDate('fecha_hora', today())->count(),
            ],
            'lineas' => $lineas,
            'alertas' => AlertaSistema::abiertas()->with('estacion')->orderByDesc('fecha_creacion')->get(),
            'estaciones' => Estacion::with('municipio')
                ->orderByRaw('aforo_actual::float / aforo_maximo DESC')
                ->get(),
        ]);
    }
}
