<?php

namespace App\Http\Controllers\Operador;

use App\Http\Controllers\Admin\EstacionController as AdminEstacionController;
use App\Http\Controllers\Concerns\ManejaErroresBD;
use App\Http\Controllers\Controller;
use App\Models\Acceso;
use App\Models\AlertaSistema;
use App\Models\BitacoraAuditoria;
use App\Models\Bus;
use App\Models\Empleado;
use App\Models\Estacion;
use App\Models\MantenimientoBus;
use App\Models\Molinete;
use App\Models\Operador;
use App\Models\Seguridad;
use App\Models\TransaccionPago;
use App\Services\CobroPasaje;
use App\Services\ConteoPasajeros;
use App\Services\MonitoreoBuses;
use App\Services\SimuladorOperacion;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Pantalla del operador (punto 16): "una PC de trabajo para cada estación de bus" con un operador que la controla.
 * Cada operador está asignado a UNA estación y desde la PC de esa estación:
 *  - ve cuánta gente espera y el aforo, y pide otro bus cuando hace falta (punto 14);
 *  - atiende las alertas de su estación (estación saturada o bus lleno que dejó gente esperando);
 *  - ve los buses detenidos en la estación y los que vienen en camino, con su ocupación;
 *  - controla los molinetes de los accesos de la estación y ve a los guardias de turno (punto 10);
 *  - registra a mano un pase o unas bajadas si el molinete de un bus detenido falla (contingencia, punto 18);
 *  - reporta fallas de los buses que pasan por la estación (el taller las atiende desde la administración).
 * El administrador no tiene acceso a esta pantalla.
 */
class EstacionController extends Controller
{
    use ManejaErroresBD;

    public const MOTIVOS_PASE = [
        'tarjeta_no_leida' => 'La tarjeta no se pudo leer en el validador',
        'sistema_caido' => 'El sistema de cobro no responde',
    ];

    public const MOTIVOS_BAJADA = [
        'contador_fallo' => 'El molinete de salida no contó a las personas',
        'sistema_caido' => 'El sistema no responde',
    ];

    public function show(Request $request): View
    {
        $operador = $this->operadorConEstacion($request);
        $estacion = $operador->estacion->load(['municipio', 'lineas.horarios', 'parqueos.bus']);
        $estacion->setRelation('accesos', $this->accesos($estacion));

        // Buses: los que están detenidos en la estación y los que vienen hacia ella (misma cuenta que el monitoreo en vivo).
        $ahora = MonitoreoBuses::ahoraMs();
        $posiciones = collect(app(MonitoreoBuses::class)->posiciones());
        $detenidos = $posiciones->filter(fn ($b) => $b['desde'] === $estacion->id_estacion && $ahora < $b['salida'])->values();
        $enCamino = $posiciones->filter(fn ($b) => $b['hacia'] === $estacion->id_estacion && $ahora >= $b['salida'])
            ->map(fn ($b) => $b + ['minutos' => max(0, (int) ceil(($b['salida'] + $b['duracion'] - $ahora) / 60000))])
            ->sortBy('minutos')->values();
        $nombres = Estacion::whereIn('id_estacion', $posiciones->pluck('desde')->merge($posiciones->pluck('hacia'))->filter()->unique())
            ->pluck('nombre', 'id_estacion');

        $alertas = AlertaSistema::abiertas()->where('id_estacion', $estacion->id_estacion)->with('bus:id_bus,placa')->orderByDesc('fecha_creacion')->get();

        $hoy = DB::selectOne(<<<'SQL'
            SELECT (SELECT count(*) FROM transacciones_pago WHERE id_estacion = :e1 AND fecha_hora >= :d1) AS subieron,
                   (SELECT coalesce(sum(monto), 0) FROM transacciones_pago WHERE id_estacion = :e2 AND fecha_hora >= :d2) AS cobrado,
                   (SELECT coalesce(sum(cantidad), 0) FROM bajadas_bus WHERE id_estacion = :e3 AND fecha_hora >= :d3) AS bajaron
            SQL, ['e1' => $estacion->id_estacion, 'e2' => $estacion->id_estacion, 'e3' => $estacion->id_estacion,
            'd1' => $dia = now()->startOfDay()->toDateTimeString(), 'd2' => $dia, 'd3' => $dia]);

        // Cobros hechos en esta estación (un pago por cada persona que subió a un bus aquí).
        $pases = TransaccionPago::query()
            ->leftJoin('buses as b', 'b.id_bus', '=', 'transacciones_pago.id_bus')
            ->leftJoin('tarjetas as ta', 'ta.id_tarjeta', '=', 'transacciones_pago.id_tarjeta')
            ->where('transacciones_pago.id_estacion', $estacion->id_estacion)
            ->orderByDesc('transacciones_pago.fecha_hora')->limit(12)
            ->get(['transacciones_pago.*', 'b.placa as bus_placa', 'ta.numero_tarjeta as tarjeta_numero']);

        return view('operador.estacion', [
            'operador' => $operador,
            'estacion' => $estacion,
            'detenidos' => $detenidos,
            'enCamino' => $enCamino,
            'nombres' => $nombres,
            'alertas' => $alertas,
            'hoy' => ['subieron' => (int) $hoy->subieron, 'cobrado' => (float) $hoy->cobrado, 'bajaron' => (int) $hoy->bajaron],
            'pases' => $pases,
            'busesFalla' => $this->busesDeLaEstacion($estacion)->pluck('placa', 'id_bus')->all(),
            'fallas' => MantenimientoBus::where('id_empleado_reporta', $operador->id_empleado)->with('bus:id_bus,placa')
                ->orderByDesc('fecha_reporte')->limit(10)->get(),
            'metodosPago' => TransaccionPago::METODOS,
            'motivos' => self::MOTIVOS_PASE,
            'motivosBajada' => self::MOTIVOS_BAJADA,
            'estadosAcceso' => AdminEstacionController::ESTADOS_ACCESO,
            'simulacionActiva' => app(SimuladorOperacion::class)->activa(),
        ]);
    }

    /** Pide otro bus para la estación (crea la alerta de refuerzo si no hay una abierta). */
    public function solicitarRefuerzo(Request $request): RedirectResponse
    {
        $operador = $this->operadorConEstacion($request);
        $estacion = $operador->estacion;

        if (AlertaSistema::abiertas()->where('id_estacion', $estacion->id_estacion)->whereIn('tipo_alerta', AlertaSistema::DE_REFUERZO)->exists()) {
            return $this->volver('estacion')->with('estado', 'Ya hay un pedido de otro bus abierto para esta estación.');
        }

        AlertaSistema::create([
            'id_estacion' => $estacion->id_estacion, 'tipo_alerta' => 'refuerzo_bus', 'estado' => 'abierta', 'fecha_creacion' => now(),
            'descripcion' => "Otro bus pedido por el operador de {$estacion->nombre}: {$estacion->aforo_actual} de {$estacion->aforo_maximo} personas esperando.",
        ]);

        return $this->volver('estacion')->with('estado', 'Pedido de otro bus enviado a la central.');
    }

    /** Atiende una alerta de su estación: si pide un bus (estación saturada o bus lleno) se envía uno de reserva. */
    public function atenderAlerta(Request $request, AlertaSistema $alerta, SimuladorOperacion $simulador): RedirectResponse
    {
        $operador = $this->operadorConEstacion($request);
        abort_unless($alerta->id_estacion === $operador->id_estacion_asignada, 403, 'Esa alerta no es de tu estación.');
        if ($alerta->estado !== 'abierta') {
            // Pasa cuando la central (o el sistema, a los 90 s de un bus lleno) ya la atendió mientras la pantalla seguía abierta.
            return $this->volver('estacion')->with('estado', $alerta->pideBus()
                ? 'Esa alerta ya fue atendida: la central ya envió otro bus.'
                : 'Esa alerta ya fue atendida.');
        }

        $placa = $alerta->pideBus()
            ? DB::transaction(fn () => $simulador->despacharRefuerzo($alerta, $request->user()->id))
            : null;

        if ($placa) {
            return $this->volver('estacion')->with('estado', "Alerta atendida: el bus {$placa} llegó a la estación y recogió pasajeros.");
        }

        $alerta->update(['estado' => 'atendida', 'fecha_cierre' => now()]);
        $this->bitacora($request, 'alertas_sistema', $alerta->id_alerta, 'UPDATE', ['estado' => 'abierta'], ['estado' => 'atendida']);

        return $this->volver('estacion')->with('estado', $alerta->pideBus()
            ? 'Alerta marcada como atendida. No hay buses de reserva disponibles: la central debe enviar uno.'
            : 'Alerta marcada como atendida.');
    }

    /** Cambia el estado del molinete de un acceso de su estación (activo, en mantenimiento o inactivo). */
    public function cambiarEstadoAcceso(Request $request, Acceso $acceso): RedirectResponse
    {
        $operador = $this->operadorConEstacion($request);
        abort_unless($acceso->id_estacion === $operador->id_estacion_asignada, 403, 'Ese acceso no es de tu estación.');
        $estado = $request->validate(['estado_operativo' => ['required', Rule::in(array_keys(AdminEstacionController::ESTADOS_ACCESO))]])['estado_operativo'];

        try {
            $antes = Molinete::where('id_acceso', $acceso->id_acceso)->value('estado_operativo');
            Molinete::where('id_acceso', $acceso->id_acceso)->update(['estado_operativo' => $estado]);
            $this->bitacora($request, 'accesos', $acceso->id_acceso, 'UPDATE', ['estado_operativo' => $antes], ['estado_operativo' => $estado]);
        } catch (QueryException $e) {
            return $this->volverConErrorBD($e);
        }

        return $this->volver('accesos')->with('estado', "{$acceso->ubicacion_fisica}: molinete ".mb_strtolower(AdminEstacionController::ESTADOS_ACCESO[$estado]).'.');
    }

    /**
     * Pase manual (contingencia): el validador de un bus detenido en la estación no lee la tarjeta o el cobro no responde.
     * Se registra en el molinete de entrada de ese bus, en esta estación, y queda en la bitácora con el motivo.
     */
    public function registrarPase(Request $request, CobroPasaje $cobro, ConteoPasajeros $conteo): RedirectResponse
    {
        $operador = $this->operadorConEstacion($request);
        $datos = $request->validate([
            'id_bus' => ['required', 'uuid'],
            'metodo_pago' => ['required', Rule::in(array_keys(TransaccionPago::METODOS))],
            'numero_tarjeta' => ['required_if:metodo_pago,tarjeta_ciudadana', 'nullable', 'string', 'max:30'],
            'motivo' => ['required', Rule::in(array_keys(self::MOTIVOS_PASE))],
        ], [
            'id_bus.required' => 'Elige el bus detenido en la estación.',
            'numero_tarjeta.required_if' => 'Escribe el número impreso en la Tarjeta Ciudadana.',
        ]);

        $bus = $this->busEnLaEstacion($datos['id_bus'], $operador, $conteo, 'pase');
        $entrada = $bus->molineteEntrada;
        abort_unless($entrada, 422, 'Ese bus no tiene molinete de entrada.');

        $pago = $cobro->cobrar($entrada, $datos['metodo_pago'], $datos['numero_tarjeta'] ?? null, manual: true);
        $this->bitacora($request, 'transacciones_pago', $pago->id_transaccion, 'INSERT', null,
            ['registro' => 'manual', 'motivo' => $datos['motivo'], 'metodo_pago' => $datos['metodo_pago'],
                'bus' => $bus->placa, 'estacion' => $operador->estacion->nombre]);

        return $this->volver('buses')->with('estado', ($pago->viaje_gratis
            ? 'Pase manual registrado: viaje gratis de la Tarjeta Ciudadana'
            : 'Pase manual registrado: Q'.number_format((float) $pago->monto, 2).' cobrados')." en el bus {$bus->placa}.");
    }

    /** Bajadas manuales (contingencia): el molinete de salida de un bus detenido en la estación no contó a las personas. */
    public function registrarBajadas(Request $request, ConteoPasajeros $conteo): RedirectResponse
    {
        $operador = $this->operadorConEstacion($request);
        $datos = $request->validate([
            'id_bus_bajada' => ['required', 'uuid'],
            'cantidad' => ['required', 'integer', 'min:1', 'max:300'],
            'motivo_bajada' => ['required', Rule::in(array_keys(self::MOTIVOS_BAJADA))],
        ], ['id_bus_bajada.required' => 'Elige el bus detenido en la estación.']);

        $bus = $this->busEnLaEstacion($datos['id_bus_bajada'], $operador, $conteo, 'bajadas');
        if ((int) $datos['cantidad'] > $bus->pasajeros_a_bordo) {
            throw ValidationException::withMessages(['cantidad' => "En el bus {$bus->placa} van {$bus->pasajeros_a_bordo} personas a bordo."]);
        }
        $salida = $bus->molineteSalida;
        abort_unless($salida, 422, 'Ese bus no tiene molinete de salida.');

        $bajada = $conteo->registrarBajadas($salida, (int) $datos['cantidad'], manual: true);
        $this->bitacora($request, 'bajadas_bus', $bajada->id_bajada, 'INSERT', null,
            ['registro' => 'manual', 'motivo' => $datos['motivo_bajada'], 'cantidad' => $bajada->cantidad,
                'bus' => $bus->placa, 'estacion' => $operador->estacion->nombre]);

        return $this->volver('buses')->with('estado', "Bajadas registradas: {$bajada->cantidad} del bus {$bus->placa}.");
    }

    /** Reporta la falla de un bus que pasa por la estación. Una falla de prioridad alta lo saca de servicio. */
    public function reportarFalla(Request $request): RedirectResponse
    {
        $operador = $this->operadorConEstacion($request);
        $datos = $request->validate([
            'id_bus_falla' => ['required', Rule::in($this->busesDeLaEstacion($operador->estacion)->pluck('id_bus')->all())],
            'categoria' => ['required', Rule::in(array_keys(MantenimientoBus::CATEGORIAS))],
            'prioridad' => ['required', Rule::in(array_keys(MantenimientoBus::PRIORIDADES))],
            'descripcion' => ['required', 'string', 'min:5', 'max:500'],
        ], ['id_bus_falla.required' => 'Elige el bus.', 'id_bus_falla.in' => 'Elige un bus de las líneas que pasan por tu estación.']);

        $bus = Bus::findOrFail($datos['id_bus_falla']);
        DB::transaction(function () use ($bus, $operador, $datos, $request) {
            $registro = MantenimientoBus::create([
                'id_bus' => $bus->id_bus, 'id_empleado_reporta' => $operador->id_empleado, 'tipo' => 'falla',
                'categoria' => $datos['categoria'], 'prioridad' => $datos['prioridad'], 'estado' => 'reportado',
                'descripcion' => $datos['descripcion']." (reportado en {$operador->estacion->nombre})", 'fecha_reporte' => now(),
            ]);
            if ($datos['prioridad'] === 'alta' && $bus->estado_logico !== 'en_mantenimiento') {
                $antes = $bus->estado_logico;
                $bus->update(['estado_logico' => 'en_mantenimiento']);
                $this->bitacora($request, 'buses', $bus->id_bus, 'UPDATE', ['estado_logico' => $antes], ['estado_logico' => 'en_mantenimiento']);
            }
            $this->bitacora($request, 'mantenimientos_bus', $registro->id_mantenimiento, 'INSERT', null,
                ['bus' => $bus->placa] + array_diff_key($datos, ['id_bus_falla' => true]));
        });

        return $this->volver('buses')->with('estado', $datos['prioridad'] === 'alta'
            ? "Falla reportada. El bus {$bus->placa} quedó fuera de servicio (en mantenimiento)."
            : "Falla del bus {$bus->placa} reportada al taller.");
    }

    /** Accesos de la estación con su molinete y sus guardias, en UNA consulta. */
    private function accesos(Estacion $estacion)
    {
        $filas = DB::table('accesos as a')
            ->where('a.id_estacion', $estacion->id_estacion)
            ->orderBy('a.ubicacion_fisica')
            ->get([
                'a.id_acceso', 'a.id_estacion', 'a.ubicacion_fisica',
                DB::raw('(SELECT row_to_json(m) FROM (SELECT id_molinete, ip_address, estado_operativo FROM molinetes WHERE id_acceso = a.id_acceso) m) AS molinete'),
                DB::raw("(SELECT coalesce(json_agg(json_build_object('id_empleado', s.id_empleado, 'numero_placa_asignada', s.numero_placa_asignada,
                            'nombre_completo', em.nombre_completo) ORDER BY em.nombre_completo), '[]')
                            FROM seguridad s JOIN empleados em ON em.id_empleado = s.id_empleado
                           WHERE s.id_acceso_asignado = a.id_acceso) AS guardias"),
            ]);

        return (new Acceso)->newCollection($filas->map(function ($f) {
            $acceso = (new Acceso)->newFromBuilder(['id_acceso' => $f->id_acceso, 'id_estacion' => $f->id_estacion, 'ubicacion_fisica' => $f->ubicacion_fisica]);
            $molinete = json_decode($f->molinete ?? 'null', true);
            $acceso->setRelation('molinete', $molinete ? (new Molinete)->newFromBuilder($molinete + ['id_acceso' => $f->id_acceso, 'tipo' => 'estacion']) : null);
            $acceso->setRelation('guardias', (new Seguridad)->newCollection(collect(json_decode($f->guardias, true))->map(function ($g) {
                $guardia = (new Seguridad)->newFromBuilder(['id_empleado' => $g['id_empleado'], 'numero_placa_asignada' => $g['numero_placa_asignada']]);

                return $guardia->setRelation('empleado', (new Empleado)->newFromBuilder(['id_empleado' => $g['id_empleado'], 'nombre_completo' => $g['nombre_completo']]));
            })->all()));

            return $acceso;
        })->all());
    }

    /** Buses (con línea) de las líneas que pasan por la estación: a ellos se les puede reportar una falla. */
    private function busesDeLaEstacion(Estacion $estacion)
    {
        return Bus::whereIn('id_linea_asignada', $estacion->lineas()->pluck('lineas.id_linea'))
            ->whereIn('estado_logico', ['en_ruta', 'en_espera'])
            ->orderBy('placa')->get(['id_bus', 'placa']);
    }

    /** El bus elegido debe estar detenido (su última parada del viaje en curso es esta estación). */
    private function busEnLaEstacion(string $idBus, Operador $operador, ConteoPasajeros $conteo, string $campo): Bus
    {
        $bus = Bus::with(['molineteEntrada', 'molineteSalida'])->find($idBus);
        $parada = $bus ? $conteo->paradaActual($bus) : null;
        if (! $bus || $parada?->id_estacion !== $operador->id_estacion_asignada) {
            throw ValidationException::withMessages([$campo => 'Ese bus no está detenido en tu estación.']);
        }

        return $bus;
    }

    /** Vuelve a la pantalla del operador, en la pestaña indicada. */
    private function volver(string $pestana): RedirectResponse
    {
        return redirect()->to(route('operador.inicio').'#'.$pestana);
    }

    /** Operador del usuario con su estación. Si no tiene estación asignada no puede usar la pantalla. */
    private function operadorConEstacion(Request $request): Operador
    {
        $operador = $request->user()->id_empleado
            ? Operador::with('estacion', 'empleado')->find($request->user()->id_empleado)
            : null;
        abort_unless($operador?->estacion, 403, 'Tu usuario no tiene una estación asignada. Pide al administrador que te asigne una en Personal.');

        return $operador;
    }

    private function bitacora(Request $request, string $tabla, string $id, string $accion, ?array $antes, ?array $despues): void
    {
        BitacoraAuditoria::create([
            'id_usuario' => $request->user()->id,
            'id_empleado_usuario' => $request->user()->id_empleado,
            'tabla_afectada' => $tabla,
            'id_registro_afectado' => $id,
            'accion' => $accion,
            'datos_previos' => $antes,
            'datos_nuevos' => $despues,
        ]);
    }
}
