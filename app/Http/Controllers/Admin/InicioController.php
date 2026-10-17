<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\MapaController;
use App\Models\AlertaSistema;
use App\Models\Linea;
use App\Services\SimuladorOperacion;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Pantalla principal del administrador: mapa plano con las rutas por color.
 * Al elegir una ruta se abre a la derecha su panel (PanelLineaController) con pasajeros,
 * estaciones, personal, buses, alertas y horario; así todo se gestiona desde una sola pantalla.
 */
class InicioController extends Controller
{
    public function __invoke(SimuladorOperacion $simulador): View
    {
        $lineas = Linea::with(['estaciones', 'horarios'])
            ->withCount('buses')
            ->enOrden()
            ->get();

        return view('admin.inicio', [
            'lineas' => $lineas,
            'lineasMapa' => collect(MapaController::datos($lineas))
                ->map(fn ($l, $i) => $l + ['panel' => route('admin.lineas.panel', $lineas[$i])])
                ->all(),
            'totales' => $this->totales(),
            // Alertas con el nombre de su estación en la misma consulta.
            'alertas' => AlertaSistema::where('alertas_sistema.estado', 'abierta')
                ->join('estaciones', 'estaciones.id_estacion', '=', 'alertas_sistema.id_estacion')
                ->select('alertas_sistema.*', 'estaciones.nombre as estacion_nombre')
                ->orderByDesc('alertas_sistema.fecha_creacion')->limit(20)->get(),
            'simulacionActiva' => $simulador->activa(),
        ]);
    }

    /** Los contadores del panel en una sola consulta (cada consulta a la base remota cuesta tiempo). */
    private function totales(): array
    {
        // "Hoy" se calcula con la hora de Guatemala (la base remota puede estar en UTC).
        $hoy = now()->startOfDay()->toDateTimeString();
        $t = DB::selectOne(<<<'SQL'
            SELECT (SELECT count(*) FROM estaciones) AS estaciones,
                   (SELECT count(*) FROM buses WHERE estado_logico = 'en_ruta') AS en_ruta,
                   (SELECT count(*) FROM buses WHERE id_linea_asignada IS NOT NULL) AS buses_en_linea,
                   (SELECT count(*) FROM alertas_sistema WHERE estado = 'abierta') AS alertas,
                   (SELECT count(*) FROM transacciones_pago WHERE fecha_hora >= ?) AS pagos,
                   (SELECT coalesce(sum(cantidad), 0) FROM bajadas_bus WHERE fecha_hora >= ?) AS bajadas,
                   (SELECT coalesce(sum(pasajeros_a_bordo), 0) FROM buses) AS a_bordo,
                   (SELECT count(*) FROM buses WHERE estado_logico = 'en_ruta' AND pasajeros_a_bordo >= capacidad_maxima) AS llenos,
                   (SELECT coalesce(sum(aforo_actual), 0) FROM estaciones) AS personas
            SQL, [$hoy, $hoy]);

        return [
            'estaciones' => (int) $t->estaciones,
            'busesEnRuta' => (int) $t->en_ruta,
            'busesEnLinea' => (int) $t->buses_en_linea,
            'alertasAbiertas' => (int) $t->alertas,
            'pagosHoy' => (int) $t->pagos,
            'bajadasHoy' => (int) $t->bajadas,
            'aBordo' => (int) $t->a_bordo,
            'busesLlenos' => (int) $t->llenos,
            'personas' => (int) $t->personas,
        ];
    }
}
