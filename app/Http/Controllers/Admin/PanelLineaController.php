<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Linea;
use App\Services\PanelLinea;
use Illuminate\View\View;

/**
 * Panel lateral de una línea en la pantalla principal: se pide al hacer clic en una ruta del mapa
 * y se refresca solo mientras la simulación está activa.
 */
class PanelLineaController extends Controller
{
    public function __invoke(Linea $linea, PanelLinea $panel): View
    {
        return view('admin.inicio._panel', $panel->datos($linea));
    }
}
