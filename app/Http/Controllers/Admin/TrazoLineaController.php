<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BitacoraAuditoria;
use App\Models\Linea;
use App\Services\ExportadorTrazos;
use App\Services\ImportadorKml;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;

/**
 * Editor del trazo de una línea en el mapa.
 * El administrador dibuja el recorrido haciendo clic sobre las calles (cada tramo se ajusta a las
 * calles con el servicio de rutas OSRM de OpenStreetMap) y puede arrastrar las estaciones a su
 * ubicación exacta. Aquí se valida y se guarda el resultado.
 */
class TrazoLineaController extends Controller
{
    /** Límites aproximados de Guatemala, para rechazar puntos fuera del país. */
    private const LATITUD = [13.5, 18.0];

    private const LONGITUD = [-92.5, -88.0];

    private const MAX_PUNTOS = 20000;

    public function edit(Linea $linea): View
    {
        $linea->load('estaciones');

        return view('admin.lineas.trazo', [
            'linea' => $linea,
            'estaciones' => $linea->estaciones->map(fn ($e) => [
                'id' => $e->id_estacion,
                'nombre' => $e->nombre,
                'direccion' => $e->direccion,
                'orden' => (int) $e->pivot->orden_parada,
                'lat' => (float) $e->latitud,
                'lon' => (float) $e->longitud,
            ])->values()->all(),
        ]);
    }

    public function update(Request $request, Linea $linea, ExportadorTrazos $exportador): RedirectResponse
    {
        $request->validate([
            'trazo' => ['required', 'json'],
            'estaciones' => ['nullable', 'json'],
        ]);

        $trazo = $this->puntos(json_decode($request->input('trazo'), true), 'trazo');
        if (count($trazo) === 1) {
            throw ValidationException::withMessages(['trazo' => 'El trazo necesita al menos 2 puntos.']);
        }

        $idsLinea = $linea->estaciones()->pluck('estaciones.id_estacion')->all();
        $estaciones = json_decode((string) $request->input('estaciones', '{}'), true) ?: [];
        if (! is_array($estaciones) || array_diff(array_keys($estaciones), $idsLinea)) {
            throw ValidationException::withMessages(['estaciones' => 'Hay estaciones que no pertenecen a esta línea.']);
        }
        foreach ($estaciones as $id => $punto) {
            $estaciones[$id] = $this->puntos([$punto], 'estaciones')[0];
        }

        $mensaje = $trazo ? 'Trazo de la ruta guardado ('.count($trazo).' puntos).' : 'Se borró el trazo de la ruta.';

        return redirect()->route('admin.lineas.show', $linea)
            ->with('estado', $mensaje.$this->guardar($request, $linea, $trazo, $estaciones, $exportador));
    }

    /**
     * Importa el recorrido y las estaciones del mapa oficial (archivo KML/KMZ de Google My Maps).
     * Las estaciones se reconocen por su nombre; el recorrido se arma uniendo las líneas del mapa.
     */
    public function importar(Request $request, Linea $linea, ImportadorKml $importador, ExportadorTrazos $exportador): RedirectResponse
    {
        $request->validate(['kml' => ['required', 'file', 'max:5120']], [
            'kml.required' => 'Elige el archivo KML o KMZ que descargaste del mapa oficial.',
        ]);
        $archivo = $request->file('kml');
        if (! in_array(strtolower($archivo->getClientOriginalExtension()), ['kml', 'kmz', 'xml'], true)) {
            throw ValidationException::withMessages(['kml' => 'El archivo debe ser .kml o .kmz.']);
        }

        try {
            $mapa = $importador->leerArchivo($archivo);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['kml' => $e->getMessage()]);
        }

        // Estaciones: cada punto del mapa se asigna a la estación de la línea con el mismo nombre.
        $linea->load('estaciones');
        $porNombre = $linea->estaciones->keyBy(fn ($e) => ImportadorKml::nombreNormalizado($e->nombre));
        $estaciones = [];
        foreach ($mapa['puntos'] as $punto) {
            $estacion = $porNombre->get(ImportadorKml::nombreNormalizado($punto['nombre']));
            if ($estacion && ! isset($estaciones[$estacion->id_estacion]) && $this->dentroDeGuatemala([$punto['lat'], $punto['lon']])) {
                $estaciones[$estacion->id_estacion] = [$punto['lat'], $punto['lon']];
            }
        }
        $faltan = $linea->estaciones->reject(fn ($e) => isset($estaciones[$e->id_estacion]))->pluck('nombre');

        // Recorrido: empieza en la estación 1 (con su ubicación oficial si vino en el mapa).
        $primera = $linea->estaciones->first();
        $inicio = $primera ? ($estaciones[$primera->id_estacion] ?? [(float) $primera->latitud, (float) $primera->longitud]) : null;
        $trazo = $this->puntos($importador->encadenar($mapa['tramos'], $inicio), 'kml');

        $mensaje = 'Mapa oficial importado: recorrido de '.count($trazo).' puntos y '
            .count($estaciones).' de '.$linea->estaciones->count().' estaciones en su ubicación exacta.';
        if ($faltan->isNotEmpty()) {
            $mensaje .= ' No se encontraron en el mapa: '.$faltan->implode(', ').' (puedes moverlas a mano).';
        }

        $mensaje .= $this->guardar($request, $linea, $trazo, $estaciones, $exportador);

        return redirect()->route('admin.lineas.trazo.edit', $linea)->with('estado', $mensaje);
    }

    /**
     * Guarda el trazo y las estaciones movidas, deja constancia en la bitácora y, en la laptop
     * (entorno local), también los escribe en database/seeders/Datos/trazos.json.
     */
    private function guardar(Request $request, Linea $linea, array $trazo, array $estaciones, ExportadorTrazos $exportador): string
    {
        DB::transaction(function () use ($linea, $trazo, $estaciones, $request) {
            $antes = ['puntos_trazo' => $linea->tieneTrazo() ? count($linea->trazo) : 0];
            $linea->update(['trazo' => $trazo ?: null]);

            foreach ($estaciones as $id => [$lat, $lon]) {
                DB::table('estaciones')->where('id_estacion', $id)->update(['latitud' => $lat, 'longitud' => $lon]);
            }

            BitacoraAuditoria::create([
                'id_usuario' => $request->user()->id,
                'id_empleado_usuario' => $request->user()->id_empleado,
                'tabla_afectada' => 'lineas',
                'id_registro_afectado' => $linea->id_linea,
                'accion' => 'UPDATE',
                'datos_previos' => $antes,
                'datos_nuevos' => ['puntos_trazo' => count($trazo), 'estaciones_movidas' => count($estaciones)],
            ]);
        });

        if (app()->environment('local')) {
            $exportador->exportar();

            return ' También quedó guardado en database/seeders/Datos/trazos.json.';
        }

        return '';
    }

    private function dentroDeGuatemala(array $punto): bool
    {
        return $punto[0] >= self::LATITUD[0] && $punto[0] <= self::LATITUD[1]
            && $punto[1] >= self::LONGITUD[0] && $punto[1] <= self::LONGITUD[1];
    }

    /**
     * Valida una lista de puntos [latitud, longitud] dentro de Guatemala y los redondea a 6 decimales.
     *
     * @return list<array{0: float, 1: float}>
     */
    private function puntos(mixed $lista, string $campo): array
    {
        if (! is_array($lista) || ! array_is_list($lista) || count($lista) > self::MAX_PUNTOS) {
            throw ValidationException::withMessages([$campo => 'El formato de los puntos no es válido.']);
        }

        return array_map(function ($punto) use ($campo) {
            $valido = is_array($punto) && array_is_list($punto) && count($punto) === 2
                && is_numeric($punto[0]) && is_numeric($punto[1])
                && $punto[0] >= self::LATITUD[0] && $punto[0] <= self::LATITUD[1]
                && $punto[1] >= self::LONGITUD[0] && $punto[1] <= self::LONGITUD[1];
            if (! $valido) {
                throw ValidationException::withMessages([$campo => 'Hay puntos fuera de Guatemala o con coordenadas inválidas.']);
            }

            return [round((float) $punto[0], 6), round((float) $punto[1], 6)];
        }, $lista);
    }
}
