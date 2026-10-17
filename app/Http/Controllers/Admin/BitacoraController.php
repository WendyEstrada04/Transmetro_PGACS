<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BitacoraAuditoria;
use App\Models\Tarjeta;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Consulta de la bitácora de auditoría (RF-17, RNF-07): quién realizó cada operación, en qué módulo,
 * sobre qué registro, qué datos cambiaron y cuándo. Solo la consulta el administrador y no se puede
 * modificar ni borrar (trigger trg_bitacora_inmutable).
 */
class BitacoraController extends Controller
{
    /** Módulo que se muestra para cada tabla de la base de datos. */
    public const MODULOS = [
        'buses' => 'Buses',
        'empleados' => 'Personal',
        'tarjetas' => 'Tarjetas',
        'lineas' => 'Líneas',
        'estaciones' => 'Estaciones',
        'accesos' => 'Accesos',
        'molinetes' => 'Molinetes del bus',
        'alertas_sistema' => 'Alertas',
        'transacciones_pago' => 'Pases manuales',
        'bajadas_bus' => 'Bajadas manuales',
        'mantenimientos_bus' => 'Mantenimiento',
    ];

    public const ACCIONES = ['INSERT' => 'Registro', 'UPDATE' => 'Cambio', 'DELETE' => 'Eliminación'];

    /** Nombre de cada dato guardado en la bitácora. */
    public const CAMPOS = [
        'placa' => 'Placa', 'tipo_unidad' => 'Tipo de unidad', 'capacidad_maxima' => 'Capacidad', 'linea' => 'Línea',
        'estado_logico' => 'Estado del bus', 'parqueo' => 'Parqueo', 'estado' => 'Estado', 'estado_operativo' => 'Molinete',
        'motivo_estado' => 'Motivo', 'nombre_completo' => 'Nombre', 'puesto' => 'Puesto', 'activo' => 'Activo',
        'asignacion' => 'Asignación', 'usuario' => 'Usuario', 'datos_personales' => 'Datos personales',
        'estacion' => 'Estación', 'acceso' => 'Acceso', 'bus' => 'Bus', 'molinete' => 'Molinete', 'aforo_actual' => 'Personas esperando',
        'origen' => 'Origen', 'bus_refuerzo' => 'Bus enviado', 'subieron' => 'Subieron', 'metodo_pago' => 'Método de pago',
        'monto' => 'Monto', 'motivo' => 'Motivo', 'cantidad' => 'Cantidad', 'tipo' => 'Tipo', 'categoria' => 'Categoría',
        'prioridad' => 'Prioridad', 'descripcion' => 'Descripción',
    ];

    /** Texto legible de los códigos que se guardan en la bitácora (motivos de pases y bajadas manuales). */
    public const VALORES = [
        'tarjeta_no_leida' => 'Tarjeta no leída',
        'sistema_caido' => 'Sistema caído',
        'contador_fallo' => 'Falló el contador de salida',
    ];

    public function index(Request $request): View
    {
        $filtros = array_filter($request->only(['modulo', 'accion', 'usuario', 'desde', 'hasta', 'buscar']), fn ($v) => filled($v));

        $consulta = BitacoraAuditoria::query()
            ->when($filtros['modulo'] ?? null, fn ($q, $m) => $q->where('tabla_afectada', $m))
            ->when($filtros['accion'] ?? null, fn ($q, $a) => $q->where('accion', $a))
            ->when($filtros['usuario'] ?? null, fn ($q, $u) => $u === 'sistema' ? $q->whereNull('id_usuario') : $q->where('id_usuario', (int) $u))
            ->when($this->fecha($filtros['desde'] ?? null), fn ($q, $d) => $q->where('fecha_registro', '>=', $d->startOfDay()))
            ->when($this->fecha($filtros['hasta'] ?? null), fn ($q, $d) => $q->where('fecha_registro', '<=', $d->endOfDay()))
            ->when($filtros['buscar'] ?? null, function ($q, $texto) {
                // Busca en el registro y en los datos guardados (por ejemplo una placa o un nombre); con parámetros, sin inyección SQL.
                $like = '%'.mb_strtolower($texto).'%';
                $q->where(fn ($w) => $w->whereRaw('lower(id_registro_afectado) like ?', [$like])
                    ->orWhereRaw('lower(coalesce(datos_previos::text, \'\')) like ?', [$like])
                    ->orWhereRaw('lower(coalesce(datos_nuevos::text, \'\')) like ?', [$like])
                    // También por el nombre actual del registro: placa del bus, nombre del empleado, número de tarjeta,
                    // estación o línea. Así un cambio que solo guardó la capacidad aparece al buscar la placa.
                    ->orWhereIn('id_registro_afectado', DB::table('buses')->selectRaw('id_bus::text')->whereRaw('lower(placa) like ?', [$like]))
                    ->orWhereIn('id_registro_afectado', DB::table('empleados')->selectRaw('id_empleado::text')->whereRaw('lower(nombre_completo) like ?', [$like]))
                    ->orWhereIn('id_registro_afectado', DB::table('estaciones')->selectRaw('id_estacion::text')->whereRaw('lower(nombre) like ?', [$like]))
                    ->orWhereIn('id_registro_afectado', DB::table('lineas')->selectRaw('id_linea::text')->whereRaw('lower(codigo_linea) like ?', [$like]))
                    ->when(preg_replace('/\D/', '', $texto) !== '', fn ($w) => $w->orWhereIn('id_registro_afectado',
                        DB::table('tarjetas')->selectRaw('id_tarjeta::text')->where('numero_tarjeta', 'like', '%'.preg_replace('/\D/', '', $texto).'%'))));
            });

        $resumen = (clone $consulta)->reorder()
            ->selectRaw('count(*) AS total, count(*) FILTER (WHERE fecha_registro >= ?) AS hoy, count(DISTINCT id_usuario) AS usuarios', [Carbon::today()])
            ->selectRaw("count(*) FILTER (WHERE accion = 'INSERT') AS altas, count(*) FILTER (WHERE accion = 'UPDATE') AS cambios, count(*) FILTER (WHERE accion = 'DELETE') AS bajas")
            ->first();

        $registros = $consulta->with(['usuario.empleado.operador.estacion'])
            ->orderByDesc('fecha_registro')->orderByDesc('id_bitacora')
            ->paginate(25)->withQueryString();

        $nombres = $this->nombresDeRegistros($registros->getCollection());

        return view('admin.bitacora.index', [
            'registros' => $registros,
            'resumen' => $resumen,
            'filtros' => $filtros,
            'nombres' => $nombres,
            'modulos' => self::MODULOS,
            'acciones' => self::ACCIONES,
            'campos' => self::CAMPOS,
            'valores' => self::VALORES,
            'usuarios' => User::whereIn('id', BitacoraAuditoria::query()->whereNotNull('id_usuario')->distinct()->select('id_usuario'))
                ->orderBy('name')->get(['id', 'name', 'rol']),
        ]);
    }

    private function fecha(?string $valor): ?Carbon
    {
        try {
            return $valor ? Carbon::createFromFormat('Y-m-d', $valor) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Nombre legible de cada registro afectado (placa del bus, número de tarjeta, estación y acceso…),
     * buscado de una sola vez por tabla. Si el registro ya no existe se usa lo guardado en la bitácora.
     *
     * @return array<string, string> clave "tabla|id" => nombre
     */
    private function nombresDeRegistros(Collection $registros): array
    {
        $ids = $registros->whereNotNull('id_registro_afectado')->groupBy('tabla_afectada')
            ->map(fn ($g) => $g->pluck('id_registro_afectado')->unique()->values()->all());
        $nombres = [];
        $poner = function (string $tabla, $filas) use (&$nombres) {
            foreach ($filas as $id => $nombre) {
                $nombres["$tabla|$id"] = $nombre;
            }
        };

        if ($v = $ids['buses'] ?? null) {
            $poner('buses', DB::table('buses')->whereIn('id_bus', $v)->pluck('placa', 'id_bus'));
        }
        if ($v = $ids['empleados'] ?? null) {
            $poner('empleados', DB::table('empleados')->whereIn('id_empleado', $v)->pluck('nombre_completo', 'id_empleado'));
        }
        if ($v = $ids['tarjetas'] ?? null) {
            $poner('tarjetas', DB::table('tarjetas')->whereIn('id_tarjeta', $v)->pluck('numero_tarjeta', 'id_tarjeta')
                ->map(fn ($n) => Tarjeta::formatearNumero($n)));
        }
        if ($v = $ids['lineas'] ?? null) {
            $poner('lineas', DB::table('lineas')->whereIn('id_linea', $v)->pluck('codigo_linea', 'id_linea')->map(fn ($c) => "Línea $c"));
        }
        if ($v = $ids['estaciones'] ?? null) {
            $poner('estaciones', DB::table('estaciones')->whereIn('id_estacion', $v)->pluck('nombre', 'id_estacion'));
        }
        if ($v = $ids['accesos'] ?? null) {
            $poner('accesos', DB::table('accesos as a')->join('estaciones as e', 'e.id_estacion', '=', 'a.id_estacion')
                ->whereIn('a.id_acceso', $v)->selectRaw("a.id_acceso, e.nombre || ' · ' || a.ubicacion_fisica AS n")->pluck('n', 'id_acceso'));
        }
        if ($v = $ids['molinetes'] ?? null) {
            $poner('molinetes', DB::table('molinetes as m')->join('buses as b', 'b.id_bus', '=', 'm.id_bus')
                ->whereIn('m.id_molinete', $v)
                ->selectRaw("m.id_molinete, b.placa || CASE m.tipo WHEN 'entrada_bus' THEN ' · entrada' ELSE ' · salida' END AS n")->pluck('n', 'id_molinete'));
        }
        if ($v = $ids['alertas_sistema'] ?? null) {
            $poner('alertas_sistema', DB::table('alertas_sistema as a')->join('estaciones as e', 'e.id_estacion', '=', 'a.id_estacion')
                ->whereIn('a.id_alerta', $v)
                ->selectRaw("a.id_alerta, CASE a.tipo_alerta WHEN 'bus_lleno' THEN 'Bus lleno' WHEN 'refuerzo_bus' THEN 'Refuerzo' ELSE a.tipo_alerta END || ' · ' || e.nombre AS n")
                ->pluck('n', 'id_alerta'));
        }
        if ($v = $ids['mantenimientos_bus'] ?? null) {
            $poner('mantenimientos_bus', DB::table('mantenimientos_bus as m')->join('buses as b', 'b.id_bus', '=', 'm.id_bus')
                ->whereIn('m.id_mantenimiento', $v)->selectRaw("m.id_mantenimiento, b.placa || ' · ' || m.categoria AS n")->pluck('n', 'id_mantenimiento'));
        }
        if ($v = $ids['transacciones_pago'] ?? null) {
            $poner('transacciones_pago', DB::table('transacciones_pago as t')->leftJoin('buses as b', 'b.id_bus', '=', 't.id_bus')
                ->whereIn('t.id_transaccion', $v)->selectRaw("t.id_transaccion, 'Pase en ' || coalesce(b.placa, 'bus') AS n")->pluck('n', 'id_transaccion'));
        }
        if ($v = $ids['bajadas_bus'] ?? null) {
            $poner('bajadas_bus', DB::table('bajadas_bus as x')->leftJoin('buses as b', 'b.id_bus', '=', 'x.id_bus')
                ->whereIn('x.id_bajada', $v)->selectRaw("x.id_bajada, 'Bajadas en ' || coalesce(b.placa, 'bus') AS n")->pluck('n', 'id_bajada'));
        }

        return $nombres;
    }
}
