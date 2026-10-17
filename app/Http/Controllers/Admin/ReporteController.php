<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Linea;
use App\Models\Municipio;
use App\Services\Reportes;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Punto 15: reportes de estaciones, líneas y buses asignados a ellas (más los de la operación del día).
 * Se ven en pantalla y se imprimen o guardan en PDF desde el navegador con un diseño propio para papel:
 * encabezado del Transmetro, cifras clave, tabla agrupada por línea y número de página.
 * Las consultas SQL quedan en el documento del proyecto (docs/Consultas_Reportes.sql).
 */
class ReporteController extends Controller
{
    public function index(): View
    {
        return view('admin.reportes.index', ['reportes' => collect(Reportes::definiciones())->groupBy('seccion', true)]);
    }

    public function show(Request $request, string $reporte, Reportes $reportes): View
    {
        abort_unless(array_key_exists($reporte, Reportes::definiciones()), 404);
        $definicion = Reportes::definicion($reporte);

        $filtros = $request->validate([
            'linea' => ['nullable', Rule::exists('lineas', 'codigo_linea')],
            'municipio' => ['nullable', Rule::exists('municipios', 'nombre')],
        ]);
        $filtros = array_filter(array_intersect_key($filtros, array_flip($definicion['filtros'])));
        $filas = $reportes->ejecutar($reporte, $filtros);
        $lineas = Linea::enOrden()->get(['codigo_linea', 'nombre', 'color']);

        return view('admin.reportes.show', [
            'clave' => $reporte,
            'reporte' => $definicion,
            'filas' => $filas,
            'resumen' => Reportes::resumen($reporte, $filas),
            'filtros' => $filtros,
            'lineas' => $lineas->mapWithKeys(fn ($l) => [$l->codigo_linea => "{$l->codigo_linea} · {$l->nombre}"])->all(),
            'colores' => $lineas->mapWithKeys(fn ($l) => [$l->codigo_linea => [$l->color, $l->colorTexto()]])->all(),
            'municipios' => Municipio::orderBy('nombre')->pluck('nombre', 'nombre')->all(),
        ]);
    }
}
