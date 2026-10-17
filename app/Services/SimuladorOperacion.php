<?php

namespace App\Services;

use App\Models\AlertaSistema;
use App\Models\BitacoraAuditoria;
use App\Models\Bus;
use App\Models\Estacion;
use App\Models\Linea;
use App\Models\Tarjeta;
use App\Models\Viaje;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Simulador de la operación del Transmetro.
 *
 * Como no hay sensores reales, el simulador reproduce lo que pasaría en la calle para
 * que las reglas del enunciado se vean funcionar solas:
 *  - Llegan y se van pasajeros de las estaciones (como si los contaran los molinetes).
 *  - De vez en cuando hay una "hora pico" en una estación que la lleva arriba del 150 %:
 *    el trigger trg_estaciones_alerta_aforo crea la alerta de refuerzo (punto 14).
 *  - Los buses en ruta avanzan de estación en estación (punto 1). En cada parada el molinete de salida
 *    cuenta a los que bajan y el de entrada cobra a cada persona que sube (un pago por persona);
 *    si van por debajo del 25 %, esperan 5 min extra (punto 15).
 *  - Si un bus va lleno y quedó gente sin subir, se crea la alerta de bus lleno para mandar otro bus.
 *  - Si una alerta de refuerzo sigue abierta en el ciclo siguiente, se envía un bus de
 *    reserva de una línea que pase por la estación y la alerta queda atendida.
 *
 * Los buses avanzan en tiempo real: cada bus sale de la estación a su hora de salida y llega a la
 * siguiente cuando pasa el tiempo del tramo (a ~24 km/h), igual que lo dibuja el monitoreo en vivo.
 */
class SimuladorOperacion
{
    public const CLAVE_ACTIVA = 'simulacion.activa';

    public const CLAVE_EVENTOS = 'simulacion.eventos';

    public const CLAVE_CICLO = 'simulacion.ciclo';

    public const CLAVE_VISTAS = 'simulacion.alertas_vistas';

    /** Última señal del motor en segundo plano (php artisan transmetro:motor). */
    public const CLAVE_MOTOR = 'simulacion.motor';

    /** Última vez que una pantalla abierta pidió ver la simulación. */
    public const CLAVE_VISTA = 'simulacion.vista';

    /** Hora (timestamp) del último ciclo, en cualquier equipo conectado a la misma base de datos. */
    public const CLAVE_ULTIMO_CICLO = 'simulacion.ultimo_ciclo';

    /** Segundos mínimos entre dos ciclos pedidos por las pantallas abiertas. */
    public const PAUSA_MINIMA_PAGINA = 4;

    /** Buses que avanzan en cada ciclo (se rotan para no saturar la base remota). */
    public const BUSES_POR_CICLO = 40;

    /** Minutos después del cierre de la línea en que los buses que siguen en la calle se retiran. */
    public const GRACIA_CIERRE_MINUTOS = 15;

    /** Buses que deben estar circulando en cada línea mientras está en su horario (como en el recorrido normal). */
    public const BUSES_MINIMOS_EN_CALLE = 3;

    /** Velocidad media de los buses: segundos por kilómetro (~24 km/h) y tiempo mínimo de un tramo. */
    public const SEGUNDOS_POR_KM = 150;

    public const SEGUNDOS_MINIMO_TRAMO = 30;

    /**
     * Columnas de las líneas que usa el simulador. No se lee el trazo del mapa (unos 70 KB por consulta):
     * el simulador no lo necesita y así cada ciclo transfiere muy pocos datos desde Supabase.
     */
    public const COLUMNAS_LINEA = 'id_linea,id_municipio,codigo_linea,distancia_total_km,estado,nombre,tipo_servicio,tarifa,color,ruta_circular';

    /** Tiempo que tiene el operador para mandar otro bus ante una alerta de bus lleno antes de que lo haga la central. */
    public const SEGUNDOS_ESPERA_BUS_LLENO = 90;

    /** Saldo que la simulación nunca usa de las Tarjetas Ciudadanas. */
    private const RESERVA_TARJETA = 10;

    /** Horas pico del tráfico (lunes a viernes): los tramos tardan más. */
    public const TRAFICO_HORAS_PICO = [['06:00', '08:30'], ['17:00', '19:30']];

    /** Cuánto más tardan los tramos en hora pico (+40 %: de ~24 km/h a ~17 km/h). */
    public const TRAFICO_FACTOR_PICO = 1.4;

    /** Variación de cada tramo por semáforos, cruces o carros mal estacionados: entre −10 % y +30 %. */
    public const TRAFICO_VARIACION = [0.9, 1.3];

    /**
     * Segundos que tarda un bus en un tramo de esta distancia.
     * Con la hora en que salió de la estación y la clave del tramo (viaje y parada) se aplica el tráfico:
     * más lento en hora pico y con una variación propia de cada tramo. La clave hace que la variación sea
     * siempre la misma para ese tramo, así la simulación y el monitoreo en vivo calculan la misma llegada.
     */
    public static function segundosTramo(float $km, ?\DateTimeInterface $salida = null, ?string $clave = null): int
    {
        $base = max(self::SEGUNDOS_MINIMO_TRAMO, round($km * self::SEGUNDOS_POR_KM));
        $factor = $salida && config('transmetro.trafico', true) ? self::factorTrafico($salida, $clave) : 1.0;

        return (int) max(self::SEGUNDOS_MINIMO_TRAMO, round($base * $factor));
    }

    /** Factor del tráfico para un tramo que empieza a esta hora (1 = sin tráfico). */
    public static function factorTrafico(\DateTimeInterface $salida, ?string $clave = null): float
    {
        [$minimo, $maximo] = self::TRAFICO_VARIACION;
        $variacion = $clave === null ? 1.0 : $minimo + (crc32($clave) % 1001) / 1000 * ($maximo - $minimo);

        return round((self::esHoraPico($salida) ? self::TRAFICO_FACTOR_PICO : 1.0) * $variacion, 3);
    }

    /** Clave de un tramo: el viaje y la parada de la que sale el bus. */
    public static function claveTramo(string $idViaje, int $ordenVisita): string
    {
        return $idViaje.'|'.$ordenVisita;
    }

    /** ¿Es hora pico del tráfico? (lunes a viernes, mañana y tarde) */
    public static function esHoraPico(\DateTimeInterface $momento): bool
    {
        if ((int) $momento->format('N') > 5) {
            return false;
        }
        $hora = $momento->format('H:i');

        return collect(self::TRAFICO_HORAS_PICO)->contains(fn ($r) => $hora >= $r[0] && $hora < $r[1]);
    }

    /** Probabilidad de una hora pico automática en cada ciclo. */
    public const PROBABILIDAD_PICO = 0.2;

    /**
     * Estado del simulador mientras corre un ciclo. Se lee de la caché una sola vez al
     * inicio y se guarda una sola vez al final, para no consultar la base remota por cada evento.
     *
     * @var array{ciclo: int, eventos: array, vistas: array, sin_bus: array}|null
     */
    private ?array $estado = null;

    /**
     * Estado compartido de la simulación (encendida, motor, pantallas abiertas, último ciclo). Se guarda en la
     * base de datos y no en archivos: así lo ven todos los equipos que usan la misma base (la versión publicada
     * en Render y la laptop) y no se pierde cuando el servidor se reinicia.
     */
    private function compartido(): Repository
    {
        return Cache::store('database');
    }

    /** El motor en segundo plano avisa que está corriendo (cada minuto). */
    public function latido(): void
    {
        $this->compartido()->put(self::CLAVE_MOTOR, time(), 300);
    }

    /** ¿Hay un motor en segundo plano ejecutando los ciclos? (si no, los ejecuta la página abierta). */
    public function motorEnMarcha(): bool
    {
        return (int) $this->compartido()->get(self::CLAVE_MOTOR, 0) >= time() - 90;
    }

    /** Una pantalla abierta avisa que alguien está mirando la simulación. */
    public function marcarVista(): void
    {
        $this->compartido()->put(self::CLAVE_VISTA, time(), 300);
    }

    /** ¿Alguien tiene abierta una pantalla del sistema (en los últimos 30 segundos)? */
    public function alguienMirando(): bool
    {
        return (int) $this->compartido()->get(self::CLAVE_VISTA, 0) >= time() - 30;
    }

    public function segundosDesdeUltimoCiclo(): int
    {
        return time() - (int) $this->compartido()->get(self::CLAVE_ULTIMO_CICLO, 0);
    }

    /**
     * Ciclo pedido por una página abierta (monitoreo en vivo o consola de simulación). La página avisa que
     * alguien está mirando; si hay un motor en segundo plano, él hace el trabajo y la página solo lee el estado.
     * Si no hay motor, la página ejecuta el ciclo, como máximo uno cada PAUSA_MINIMA_PAGINA segundos aunque
     * haya varias pantallas abiertas.
     */
    public function cicloDesdePagina(?int $idUsuario = null): array
    {
        if (! $this->activa()) {
            return [];
        }
        $this->marcarVista();

        return $this->motorEnMarcha() || $this->segundosDesdeUltimoCiclo() < self::PAUSA_MINIMA_PAGINA ? [] : $this->ciclo($idUsuario);
    }

    /**
     * Una vuelta del motor en segundo plano. Con alguien mirando, un ciclo cada pocos segundos para que los buses
     * se vean avanzar; sin nadie mirando, un ciclo cada pocos minutos: la operación sigue calculándose las 24 horas
     * pero con muy poco tráfico hacia la base de datos (Supabase gratis: 5 GB de transferencia al mes).
     */
    public function cicloDelMotor(): array
    {
        $estado = $this->compartido()->many([self::CLAVE_ACTIVA, self::CLAVE_VISTA, self::CLAVE_ULTIMO_CICLO]);
        if (! (bool) ($estado[self::CLAVE_ACTIVA] ?? config('transmetro.simulacion_encendida'))) {
            return [];
        }
        $mirando = (int) ($estado[self::CLAVE_VISTA] ?? 0) >= time() - 30;
        $espera = $mirando ? (int) config('transmetro.motor_segundos_con_vista') : (int) config('transmetro.motor_segundos_sin_vista');

        return time() - (int) ($estado[self::CLAVE_ULTIMO_CICLO] ?? 0) >= $espera ? $this->ciclo() : [];
    }

    /**
     * ¿La simulación está encendida? El estado se guarda en la base de datos (tabla cache) y no en archivos,
     * para que siga encendida aunque el servidor se reinicie o se duerma (Render, plan gratis). Si nadie la ha
     * encendido ni pausado, se usa el valor de config/transmetro.php (encendida en la versión publicada).
     */
    public function activa(): bool
    {
        return (bool) $this->compartido()->get(self::CLAVE_ACTIVA, config('transmetro.simulacion_encendida'));
    }

    public function activar(): void
    {
        $this->compartido()->forever(self::CLAVE_ACTIVA, true);
        $this->evento('info', 'Simulación iniciada.');
    }

    public function pausar(): void
    {
        $this->compartido()->forever(self::CLAVE_ACTIVA, false);
        $this->evento('info', 'Simulación en pausa.');
    }

    public function numeroCiclo(): int
    {
        return $this->estado['ciclo'] ?? (int) $this->compartido()->get(self::CLAVE_CICLO, 0);
    }

    /** @return list<array{hora: string, tipo: string, texto: string}> */
    public function eventos(): array
    {
        return $this->estado['eventos'] ?? $this->compartido()->get(self::CLAVE_EVENTOS, []);
    }

    private function cargarEstado(): void
    {
        $valores = $this->compartido()->many([self::CLAVE_CICLO, self::CLAVE_EVENTOS, self::CLAVE_VISTAS, 'simulacion.sin_bus']);
        $this->estado = [
            'ciclo' => (int) ($valores[self::CLAVE_CICLO] ?? 0),
            'eventos' => $valores[self::CLAVE_EVENTOS] ?? [],
            'vistas' => $valores[self::CLAVE_VISTAS] ?? [],
            'sin_bus' => $valores['simulacion.sin_bus'] ?? [],
        ];
    }

    private function guardarEstado(): void
    {
        $this->compartido()->putMany([
            self::CLAVE_CICLO => $this->estado['ciclo'],
            self::CLAVE_EVENTOS => array_slice($this->estado['eventos'], 0, 60),
            self::CLAVE_VISTAS => $this->estado['vistas'],
            'simulacion.sin_bus' => array_slice($this->estado['sin_bus'], -100),
        ], now()->addYear());
    }

    /**
     * Ejecuta un ciclo de simulación. Devuelve los eventos generados en el ciclo.
     *
     * @return list<array{hora: string, tipo: string, texto: string}>
     */
    public function ciclo(?int $idUsuario = null): array
    {
        // El candado está en la base de datos: si la laptop y el servidor publicado piden un ciclo a la vez, solo uno lo ejecuta.
        $candado = $this->compartido()->lock('simulacion.ciclo.candado', 30);
        if (! $candado->get()) {
            return [];   // ya hay un ciclo corriendo
        }
        $this->compartido()->put(self::CLAVE_ULTIMO_CICLO, time(), 86400);

        try {
            $this->cargarEstado();
            $this->estado['ciclo']++;
            $antes = count($this->estado['eventos']);

            DB::transaction(function () use ($idUsuario) {
                $this->despacharRefuerzosPendientes($idUsuario);
                $this->moverPasajeros();
                if (mt_rand() / mt_getrandmax() < self::PROBABILIDAD_PICO) {
                    $this->horaPico();
                }
                $this->retirarBusesFueraDeHorario();
                $this->despacharSalidas();
                $this->moverBuses();
                $this->registrarAlertasNuevas();
            });

            $nuevos = array_slice($this->estado['eventos'], 0, max(0, count($this->estado['eventos']) - $antes));
            $this->guardarEstado();

            return $nuevos;
        } finally {
            $this->estado = null;
            $candado->release();
        }
    }

    /**
     * Llegada y salida de pasajeros en todas las estaciones de líneas activas.
     * Es una caminata aleatoria que tiende a un 40 % del aforo, como si los buses no
     * simulados se llevaran parte de la gente. Un solo UPDATE: el trigger revisa cada fila.
     */
    public function moverPasajeros(): void
    {
        $enServicio = $this->arregloUuid($this->lineasEnServicio());

        DB::update(<<<'SQL'
            UPDATE estaciones e
               SET aforo_actual = GREATEST(0, e.aforo_actual + ROUND(
                       (e.aforo_maximo * 0.40 - e.aforo_actual) * 0.25
                       + (random() - 0.5) * e.aforo_maximo * 0.16))::int
             WHERE EXISTS (SELECT 1 FROM linea_estacion le
                            WHERE le.id_estacion = e.id_estacion AND le.id_linea = ANY (?::uuid[]))
            SQL, [$enServicio]);

        // Estaciones cuyas líneas ya cerraron (o todavía no abren): la gente se va y quedan vacías.
        DB::update(<<<'SQL'
            UPDATE estaciones e
               SET aforo_actual = FLOOR(e.aforo_actual * 0.5)::int
             WHERE e.aforo_actual > 0
               AND NOT EXISTS (SELECT 1 FROM linea_estacion le
                                WHERE le.id_estacion = e.id_estacion AND le.id_linea = ANY (?::uuid[]))
            SQL, [$enServicio]);
    }

    /** Ids de las líneas que deben estar circulando ahora, según su horario. */
    public function lineasEnServicio(): array
    {
        return Linea::select(explode(',', self::COLUMNAS_LINEA))->with('horarios')->get()->filter(fn (Linea $l) => $l->operaAhora())->pluck('id_linea')->all();
    }

    /** Lista de uuid en el formato de arreglo de PostgreSQL. */
    private function arregloUuid(array $ids): string
    {
        return '{'.implode(',', $ids).'}';
    }

    /**
     * Pasada la hora de cierre (con unos minutos para terminar la vuelta), los buses que sigan en la calle
     * se retiran: así nada circula de noche aunque la simulación haya estado en pausa.
     */
    public function retirarBusesFueraDeHorario(): void
    {
        if (! config('transmetro.respetar_horarios', true)) {
            return;
        }
        $ahora = Carbon::now();
        $enServicio = $this->lineasEnServicio();

        foreach (Linea::select(explode(',', self::COLUMNAS_LINEA))->with('horarios')->where('estado', 'activa')->whereNotIn('id_linea', $enServicio)->get() as $linea) {
            $fin = $linea->horarioDelDia()?->fin();
            $cerro = $fin ? Carbon::parse("today {$fin}") : null;
            // Antes de abrir, lo que quedó de ayer se retira de inmediato; después del cierre, tras unos minutos de gracia.
            if ($cerro && $ahora->greaterThan($cerro) && $ahora->diffInMinutes($cerro, true) < self::GRACIA_CIERRE_MINUTOS) {
                continue;
            }
            $viajes = Viaje::where('id_linea', $linea->id_linea)->where('estado', 'en_curso')->get();
            if ($viajes->isEmpty()) {
                continue;
            }
            Viaje::whereIn('id_viaje', $viajes->pluck('id_viaje'))->update(['estado' => 'finalizado', 'fecha_hora_fin' => $ahora]);
            Bus::whereIn('id_bus', $viajes->pluck('id_bus'))->update(['estado_logico' => 'en_espera', 'pasajeros_a_bordo' => 0]);
            $this->evento('bus', $viajes->count()." buses se retiran de la {$linea->codigo_linea}: la línea está fuera de su horario"
                .($fin ? " (cerró a las {$fin})." : '.'));
        }
    }

    /**
     * Al abrir la línea (o si se quedó sin buses en la calle) salen buses en reserva hasta tener el mínimo
     * circulando: así el servicio arranca solo en su horario.
     */
    public function despacharSalidas(): void
    {
        if (! config('transmetro.respetar_horarios', true)) {
            return;
        }
        $lineas = Linea::select(explode(',', self::COLUMNAS_LINEA))->whereIn('id_linea', $this->lineasEnServicio())->get();
        foreach ($lineas as $linea) {
            $enCurso = Viaje::where('id_linea', $linea->id_linea)->where('estado', 'en_curso')->count();
            if ($enCurso >= self::BUSES_MINIMOS_EN_CALLE) {
                continue;
            }
            $buses = Bus::where('id_linea_asignada', $linea->id_linea)
                ->where('estado_logico', 'en_espera')
                ->whereHas('piloto')
                ->whereDoesntHave('viajes', fn ($q) => $q->where('estado', 'en_curso'))
                ->orderBy('placa')
                ->limit(self::BUSES_MINIMOS_EN_CALLE - $enCurso)
                ->lockForUpdate()
                ->get();
            foreach ($buses as $bus) {
                $bus->update(['estado_logico' => 'en_ruta', 'pasajeros_a_bordo' => 0]);
                Viaje::create(['id_bus' => $bus->id_bus, 'id_linea' => $linea->id_linea, 'fecha_hora_inicio' => Carbon::now(), 'estado' => 'en_curso']);
                $this->evento('bus', "{$bus->placa} sale a su recorrido en la {$linea->codigo_linea}: la línea está en su horario de servicio.");
            }
        }
    }

    /** Hora pico: una estación (al azar o la indicada) sube entre el 150 % y el 170 % de su aforo. */
    public function horaPico(?Estacion $estacion = null): Estacion
    {
        $enServicio = $this->lineasEnServicio();
        $estacion ??= Estacion::query()
            ->whereHas('lineas', fn ($q) => $q->whereIn('lineas.id_linea', $enServicio ?: Linea::where('estado', 'activa')->pluck('id_linea')))
            ->inRandomOrder()
            ->firstOrFail();

        // Si ya estaba saturada pero sin alerta abierta, primero "baja" un poco para que el
        // trigger vea el cruce del 150 % y cree la alerta de nuevo.
        $limite = (int) ceil($estacion->aforo_maximo * 1.5);
        if ($estacion->aforo_actual >= $limite
            && ! AlertaSistema::abiertas()->where('id_estacion', $estacion->id_estacion)->exists()) {
            $estacion->update(['aforo_actual' => $limite - 1]);
        }

        $objetivo = (int) ceil($estacion->aforo_maximo * mt_rand(152, 170) / 100);
        $nuevo = max($estacion->aforo_actual, $objetivo);
        $llegan = $nuevo - $estacion->aforo_actual;
        $estacion->update(['aforo_actual' => $nuevo]);

        $this->evento('pico', "Hora pico en {$estacion->nombre}: llegaron {$llegan} pasajeros ({$nuevo}/{$estacion->aforo_maximo}).");

        return $estacion;
    }

    /**
     * Avanza a su siguiente estación los buses que ya llegaron (puntos 1 y 15): los que salieron de su
     * última estación hace al menos el tiempo del tramo. Los que todavía van en camino siguen su viaje.
     */
    public function moverBuses(): void
    {
        $ahora = Carbon::now();
        $viajes = Viaje::query()
            ->where('viajes.estado', 'en_curso')
            ->whereHas('bus.piloto')   // sin piloto la unidad no circula
            ->with(['bus.linea:'.self::COLUMNAS_LINEA, 'linea:'.self::COLUMNAS_LINEA])
            ->withMax('visitas', 'orden_visita')
            ->withMax('visitas', 'hora_salida')
            ->get();

        if ($viajes->isEmpty()) {
            return;
        }

        $paradas = DB::table('linea_estacion')
            ->whereIn('id_linea', $viajes->pluck('id_linea')->unique())
            ->orderBy('orden_parada')
            ->get(['id_linea', 'id_estacion', 'orden_parada', 'distancia_previa_km'])
            ->groupBy('id_linea');

        // Solo los buses que ya llegaron a su siguiente estación (los que recién empiezan su viaje, de una vez).
        $viajes = $viajes->filter(function (Viaje $viaje) use ($paradas, $ahora) {
            if (! $viaje->visitas_max_hora_salida) {
                return true;
            }
            $siguiente = $paradas->get($viaje->id_linea, collect())->values()->get((int) $viaje->visitas_max_orden_visita);
            $salida = Carbon::parse($viaje->visitas_max_hora_salida);
            $llegada = $salida->copy()->addSeconds(self::segundosTramo((float) ($siguiente->distancia_previa_km ?? 0), $salida,
                self::claveTramo($viaje->id_viaje, (int) $viaje->visitas_max_orden_visita)));

            return $llegada->lessThanOrEqualTo($ahora);
        })->sortBy(fn ($v) => $v->visitas_max_hora_salida ?? '')->take(self::BUSES_POR_CICLO)->values();

        if ($viajes->isEmpty()) {
            return;
        }

        // Primero se decide a qué estación llega cada bus; los que terminaron su recorrido se cierran aparte.
        $llegadas = [];
        foreach ($viajes as $viaje) {
            $recorrido = $paradas->get($viaje->id_linea, collect())->values();
            $siguiente = (int) ($viaje->visitas_max_orden_visita ?? 0) + 1;

            if ($siguiente > $recorrido->count()) {
                $this->terminarViaje($viaje);

                continue;
            }
            // En la última estación del recorrido bajan todos y nadie sube (el bus inicia otra vuelta).
            $llegadas[] = [$viaje, $recorrido[$siguiente - 1]->id_estacion, $siguiente, $siguiente === $recorrido->count()];
        }

        $r = $this->procesarParadas($llegadas, $ahora);

        $this->evento('bus', $viajes->count().' buses avanzaron a su siguiente estación: subieron '.$r['subieron']
            .' pasajeros (molinete de entrada, Q'.number_format($r['cobrado'], 2).' cobrados) y bajaron '.$r['bajaron'].' (molinete de salida).');
        if ($r['espera']) {
            $this->evento('espera', 'Menos del 25 % de ocupación, esperan 5 min extra: '.implode('; ', array_slice($r['espera'], 0, 4))
                .(count($r['espera']) > 4 ? ' y '.(count($r['espera']) - 4).' más.' : '.'));
        }
    }

    /**
     * El bus llega a una estación fuera de su recorrido normal (bus de refuerzo): bajan y suben pasajeros
     * por sus molinetes, se guarda la visita y se aplica la espera extra si va por debajo del 25 %.
     *
     * @return array{estacion: string, subieron: int, bajaron: int, quedaron: int, ocupacion: float, espera_extra: bool}
     */
    public function detenerBus(Viaje $viaje, string $idEstacion, int $orden, bool $refuerzo = false): array
    {
        $r = $this->procesarParadas([[$viaje, $idEstacion, $orden, false]], Carbon::now(), $refuerzo);

        return $r['paradas'][0];
    }

    /**
     * Llegada de varios buses a sus estaciones, con pocas escrituras para todos:
     *  1. se guarda la visita de cada bus,
     *  2. el molinete de salida cuenta a los que bajan (bajadas_bus),
     *  3. el molinete de entrada cobra a cada persona que sube (un pago por persona),
     *  4. si un bus va lleno y quedó gente sin subir, se crea la alerta 'bus_lleno'.
     * Los triggers de la base suman y restan los pasajeros del bus y de la visita, y descuentan
     * de la estación a los que subieron.
     *
     * @param  list<array{0: Viaje, 1: string, 2: int, 3: bool}>  $llegadas  [viaje, estación, orden, última parada]
     */
    private function procesarParadas(array $llegadas, Carbon $ahora, bool $refuerzo = false): array
    {
        $resultado = ['subieron' => 0, 'bajaron' => 0, 'cobrado' => 0.0, 'espera' => [], 'paradas' => []];
        if (! $llegadas) {
            return $resultado;
        }

        // Una sola lectura (bloqueada) del aforo de las estaciones y de los molinetes de los buses.
        $estaciones = Estacion::whereIn('id_estacion', array_unique(array_column($llegadas, 1)))
            ->lockForUpdate()
            ->get(['id_estacion', 'nombre', 'aforo_actual', 'aforo_maximo'])
            ->keyBy('id_estacion');
        $idsBuses = array_map(fn ($l) => $l[0]->id_bus, $llegadas);
        $molinetes = DB::table('molinetes')->whereIn('id_bus', $idsBuses)
            ->get(['id_molinete', 'id_bus', 'tipo', 'estado_operativo'])
            ->keyBy(fn ($m) => $m->id_bus.'|'.$m->tipo);
        $aBordo = Bus::whereIn('id_bus', $idsBuses)->lockForUpdate()->pluck('pasajeros_a_bordo', 'id_bus');
        $conAlerta = AlertaSistema::abiertas()->where('tipo_alerta', 'bus_lleno')->pluck('id_estacion')->flip();

        $visitas = $bajadas = $subidas = $alertas = $llenos = [];
        foreach ($llegadas as [$viaje, $idEstacion, $orden, $ultima]) {
            $bus = $viaje->bus;
            $estacion = $estaciones[$idEstacion];
            $entrada = $molinetes[$bus->id_bus.'|entrada_bus'] ?? null;
            $salida = $molinetes[$bus->id_bus.'|salida_bus'] ?? null;
            $capacidad = max(1, (int) $bus->capacidad_maxima);
            $antes = (int) ($aBordo[$bus->id_bus] ?? 0);

            $p = self::repartirParada($antes, $capacidad, (int) $estacion->aforo_actual, $ultima, $refuerzo);
            // Un molinete fuera de servicio no registra: nadie paga ni se cuenta por esa puerta.
            if ($salida?->estado_operativo !== 'activo') {
                $p['bajaron'] = 0;
            }
            if ($entrada?->estado_operativo !== 'activo') {
                $p['subieron'] = 0;
                $p['quedaron'] = 0;
            }
            $p['a_bordo'] = $antes - $p['bajaron'] + $p['subieron'];
            $estacion->aforo_actual -= $p['subieron'];   // para el siguiente bus que llegue a la misma estación
            $aBordo[$bus->id_bus] = $p['a_bordo'];
            $ocupacion = round($p['a_bordo'] * 100 / $capacidad, 2);
            $espera = ! $ultima && $ocupacion < 25;   // punto 15 (en la última estación el bus termina su vuelta)

            $idVisita = (string) Str::uuid();
            $visitas[] = [
                'id_visita' => $idVisita,
                'id_viaje' => $viaje->id_viaje,
                'id_estacion' => $idEstacion,
                'orden_visita' => $orden,
                'hora_llegada' => $ahora->toDateTimeString(),
                'hora_salida' => $ahora->copy()->addMinutes(1 + ($espera ? 5 : 0))->toDateTimeString(),
                'ocupacion_al_llegar_pct' => round($antes * 100 / $capacidad, 2),
            ];
            if ($p['bajaron'] > 0) {
                $bajadas[] = ['id_bajada' => (string) Str::uuid(), 'id_molinete' => $salida->id_molinete, 'id_bus' => $bus->id_bus,
                    'id_estacion' => $idEstacion, 'id_visita' => $idVisita, 'cantidad' => $p['bajaron'], 'fecha_hora' => $ahora->toDateTimeString()];
            }
            for ($k = 0; $k < $p['subieron']; $k++) {
                $subidas[] = [$entrada->id_molinete, $bus, $idEstacion, $idVisita, $viaje->id_linea];
            }
            if ($p['quedaron'] > 0 && $p['a_bordo'] >= $capacidad) {
                $llenos[] = "{$bus->placa} en {$estacion->nombre} (quedaron {$p['quedaron']})";
                if (! isset($conAlerta[$idEstacion])) {
                    $conAlerta[$idEstacion] = true;
                    $alertas[] = ['id_alerta' => (string) Str::uuid()]
                        + app(ConteoPasajeros::class)->filaAlerta($bus, $idEstacion, $estacion->nombre, $p['quedaron'], $ahora);
                }
            }
            if ($espera) {
                $resultado['espera'][] = $bus->placa.' en '.$estacion->nombre.' ('.number_format($ocupacion, 0).' %)';
            }
            $resultado['subieron'] += $p['subieron'];
            $resultado['bajaron'] += $p['bajaron'];
            $resultado['paradas'][] = ['estacion' => $estacion->nombre, 'subieron' => $p['subieron'], 'bajaron' => $p['bajaron'],
                'quedaron' => $p['quedaron'], 'ocupacion' => $ocupacion, 'espera_extra' => $espera];
        }

        // Pocas escrituras para todos los buses del ciclo. Primero bajan y después suben (como en la puerta del bus).
        DB::table('visitas_viaje')->insert($visitas);
        foreach (array_chunk($bajadas, 500) as $lote) {
            DB::table('bajadas_bus')->insert($lote);
        }
        $pagos = $this->cobrarSubidas($subidas, $ahora);
        foreach (array_chunk($pagos, 500) as $lote) {
            DB::table('transacciones_pago')->insert($lote);
        }
        $resultado['cobrado'] = (float) array_sum(array_column($pagos, 'monto'));
        if ($alertas) {
            DB::table('alertas_sistema')->insert($alertas);
        }
        Bus::whereIn('id_bus', $idsBuses)->where('estado_logico', '!=', 'en_ruta')->update(['estado_logico' => 'en_ruta']);

        if ($llenos) {
            $this->evento('lleno', 'Bus lleno, quedó gente sin subir: '.implode('; ', array_slice($llenos, 0, 4))
                .(count($llenos) > 4 ? ' y '.(count($llenos) - 4).' más' : '').'. Se pide otro bus para la ruta.');
        }

        return $resultado;
    }

    /**
     * Cuántos bajan (molinete de salida) y cuántos suben (molinete de entrada) cuando un bus llega a una estación.
     *  - Bajan entre el 15 % y el 50 % de los que van a bordo; en la última estación del recorrido bajan todos.
     *  - De la gente que espera, entre el 12 % y el 30 % va para esta línea (los demás esperan otra línea);
     *    a un bus de refuerzo se suben todos los que quepan.
     *  - Suben los que caben; los que no caben "quedaron" esperando (bus lleno).
     *
     * @return array{bajaron: int, subieron: int, quedaron: int, a_bordo: int}
     */
    public static function repartirParada(int $aBordo, int $capacidad, int $esperando, bool $ultimaParada = false, bool $refuerzo = false): array
    {
        $aBordo = max(0, min($aBordo, $capacidad));
        $bajan = $ultimaParada ? $aBordo : (int) round($aBordo * mt_rand(15, 50) / 100);
        $aBordo -= $bajan;
        $quieren = $ultimaParada ? 0 : ($refuerzo ? max(0, $esperando) : (int) round(max(0, $esperando) * mt_rand(12, 30) / 100));
        $suben = max(0, min($quieren, $capacidad - $aBordo));

        return ['bajaron' => $bajan, 'subieron' => $suben, 'quedaron' => $quieren - $suben, 'a_bordo' => $aBordo + $suben];
    }

    /**
     * Un pago por cada persona que sube. Unos pagan con Tarjeta Ciudadana (viaje gratis o saldo de la tarjeta)
     * y otros con tarjeta bancaria sin contacto. Los saldos de las tarjetas se actualizan en una sola consulta.
     *
     * @param  list<array{0: string, 1: Bus, 2: string, 3: string, 4: string}>  $subidas  [molinete, bus, estación, visita, línea]
     */
    private function cobrarSubidas(array $subidas, Carbon $ahora): array
    {
        if (! $subidas) {
            return [];
        }
        $tarifas = DB::table('lineas')->pluck('tarifa', 'id_linea');
        // Las tarjetas de prueba guardan una reserva de Q10 (así siempre hay tarjetas con saldo para probar a mano).
        $tarjetas = Tarjeta::where('estado', 'activa')
            ->where(fn ($q) => $q->where('viajes_gratis_restantes', '>', 0)->orWhere('tipo_tarjeta', 'adulto_mayor')->orWhere('saldo', '>=', self::RESERVA_TARJETA + 1))
            ->inRandomOrder()->limit(80)->lockForUpdate()
            ->get(['id_tarjeta', 'tipo_tarjeta', 'saldo', 'viajes_gratis_restantes'])->values();
        // Adulto mayor: viajes de cortesía que ya usó hoy (4 diarios).
        $cortesiasHoy = DB::table('transacciones_pago')->whereIn('id_tarjeta', $tarjetas->where('tipo_tarjeta', 'adulto_mayor')->pluck('id_tarjeta'))
            ->where('viaje_gratis', true)->where('fecha_hora', '>=', $ahora->copy()->startOfDay()->toDateTimeString())
            ->groupBy('id_tarjeta')->selectRaw('id_tarjeta, count(*) AS n')->pluck('n', 'id_tarjeta')->map(fn ($n) => (int) $n)->all();
        $usadas = [];

        $pagos = [];
        foreach ($subidas as $k => [$idMolinete, $bus, $idEstacion, $idVisita, $idLinea]) {
            $tarifa = (float) ($tarifas[$idLinea] ?? 1);
            $fila = ['id_transaccion' => (string) Str::uuid(), 'id_molinete' => $idMolinete, 'id_tarjeta' => null,
                'monto' => $tarifa, 'metodo_pago' => 'tarjeta_bancaria', 'viaje_gratis' => false,
                'fecha_hora' => $ahora->copy()->addSeconds(intdiv($k % 60, 4))->toDateTimeString(),
                'id_linea' => $idLinea, 'id_bus' => $bus->id_bus, 'id_estacion' => $idEstacion, 'id_visita' => $idVisita];

            $tarjeta = $tarjetas->isNotEmpty() && mt_rand(1, 100) <= 65 ? $tarjetas[mt_rand(0, $tarjetas->count() - 1)] : null;
            if ($tarjeta && $tarjeta->tipo_tarjeta === 'adulto_mayor' && ($cortesiasHoy[$tarjeta->id_tarjeta] ?? 0) < Tarjeta::CORTESIA_DIARIA_ADULTO_MAYOR) {
                $cortesiasHoy[$tarjeta->id_tarjeta] = ($cortesiasHoy[$tarjeta->id_tarjeta] ?? 0) + 1;
                $fila = ['metodo_pago' => 'tarjeta_ciudadana', 'id_tarjeta' => $tarjeta->id_tarjeta, 'monto' => 0, 'viaje_gratis' => true] + $fila;
            } elseif ($tarjeta && $tarjeta->viajes_gratis_restantes > 0) {
                $tarjeta->viajes_gratis_restantes--;
                $fila = ['metodo_pago' => 'tarjeta_ciudadana', 'id_tarjeta' => $tarjeta->id_tarjeta, 'monto' => 0, 'viaje_gratis' => true] + $fila;
                $usadas[$tarjeta->id_tarjeta] = $tarjeta;
            } elseif ($tarjeta && (float) $tarjeta->saldo - $tarifa >= self::RESERVA_TARJETA) {
                $tarjeta->saldo = round((float) $tarjeta->saldo - $tarifa, 2);
                $fila = ['metodo_pago' => 'tarjeta_ciudadana', 'id_tarjeta' => $tarjeta->id_tarjeta] + $fila;
                $usadas[$tarjeta->id_tarjeta] = $tarjeta;
            }
            $pagos[] = $fila;
        }

        if ($usadas) {
            $filas = implode(', ', array_fill(0, count($usadas), '(?::uuid, ?::numeric, ?::int)'));
            $parametros = [];
            foreach ($usadas as $t) {
                array_push($parametros, $t->id_tarjeta, $t->saldo, $t->viajes_gratis_restantes);
            }
            DB::update("UPDATE tarjetas t SET saldo = v.saldo, viajes_gratis_restantes = v.gratis
                          FROM (VALUES {$filas}) AS v(id, saldo, gratis) WHERE t.id_tarjeta = v.id", $parametros);
        }

        return $pagos;
    }

    /** El bus llega al final del recorrido: cierra el viaje y sale otra vuelta o pasa a reserva. */
    private function terminarViaje(Viaje $viaje): void
    {
        $viaje->update(['estado' => 'finalizado', 'fecha_hora_fin' => Carbon::now()]);

        // Fuera del horario de la línea el bus termina su recorrido y se retira: no sale otra vuelta.
        if (! in_array($viaje->id_linea, $this->lineasEnServicio(), true)) {
            $viaje->bus->update(['estado_logico' => 'en_espera', 'pasajeros_a_bordo' => 0]);
            $this->evento('bus', "{$viaje->bus->placa} terminó su recorrido en la {$viaje->linea->codigo_linea} y se retira: la línea está fuera de su horario.");

            return;
        }

        $enCurso = Viaje::where('id_linea', $viaje->id_linea)->where('estado', 'en_curso')->count();
        if ($enCurso >= 3) {
            $viaje->bus->update(['estado_logico' => 'en_espera', 'pasajeros_a_bordo' => 0]);
            $this->evento('bus', "{$viaje->bus->placa} terminó su recorrido en la {$viaje->linea->codigo_linea} y queda en reserva.");

            return;
        }

        Viaje::create(['id_bus' => $viaje->id_bus, 'id_linea' => $viaje->id_linea,
            'fecha_hora_inicio' => Carbon::now(), 'estado' => 'en_curso']);
        $viaje->bus->update(['pasajeros_a_bordo' => 0]);
        $this->evento('bus', "{$viaje->bus->placa} terminó su recorrido en la {$viaje->linea->codigo_linea} e inicia otra vuelta.");
    }

    /** Muestra en el registro las alertas creadas por el trigger durante el ciclo (las de bus lleno ya se anotaron al crearse). */
    private function registrarAlertasNuevas(): void
    {
        $vistas = $this->estado['vistas'] ?? $this->compartido()->get(self::CLAVE_VISTAS, []);
        $nuevas = AlertaSistema::abiertas()->whereIn('tipo_alerta', AlertaSistema::DE_REFUERZO)
            ->whereNotIn('id_alerta', array_keys($vistas))
            ->with('estacion')
            ->get();

        foreach ($nuevas as $alerta) {
            $vistas[$alerta->id_alerta] = $this->numeroCiclo();
            if ($alerta->tipo_alerta !== 'refuerzo_bus') {
                continue;
            }
            $this->evento('alerta', "ALERTA: {$alerta->estacion->nombre} superó el 150 % de su aforo "
                ."({$alerta->estacion->aforo_actual}/{$alerta->estacion->aforo_maximo}). Se solicita bus de refuerzo.");
        }
        if ($this->estado !== null) {
            $this->estado['vistas'] = $vistas;
        } else {
            $this->compartido()->forever(self::CLAVE_VISTAS, $vistas);
        }
    }

    /**
     * Alertas que ya se vieron en un ciclo anterior: el centro de control envía el refuerzo.
     * A las de bus lleno se les da un momento para que el operador mande el bus; si nadie lo hace, lo manda la central.
     */
    private function despacharRefuerzosPendientes(?int $idUsuario): void
    {
        $vistas = $this->estado['vistas'] ?? $this->compartido()->get(self::CLAVE_VISTAS, []);
        $ciclo = $this->numeroCiclo();
        if (! $vistas) {
            return;   // ninguna alerta pendiente vista en ciclos anteriores
        }

        $limiteLleno = Carbon::now()->subSeconds(self::SEGUNDOS_ESPERA_BUS_LLENO);
        AlertaSistema::abiertas()->whereIn('tipo_alerta', AlertaSistema::DE_REFUERZO)
            ->with(['estacion', 'bus'])
            ->get()
            ->filter(fn ($a) => isset($vistas[$a->id_alerta]) && $vistas[$a->id_alerta] < $ciclo
                && ($a->tipo_alerta !== 'bus_lleno' || $a->fecha_creacion->lessThanOrEqualTo($limiteLleno)))
            ->each(fn ($a) => $this->despacharRefuerzo($a, $idUsuario));
    }

    /**
     * Envía un bus de reserva (en espera) de una línea que pase por la estación de la alerta.
     * Si la alerta es de bus lleno, se prefiere un bus de la misma línea (la ruta con demanda).
     * El bus llega a la estación, recoge pasajeros (cobrando a cada uno en su molinete de entrada)
     * y la alerta queda atendida.
     * Devuelve la placa del bus enviado o null si no había buses de reserva.
     */
    public function despacharRefuerzo(AlertaSistema $alerta, ?int $idUsuario = null): ?string
    {
        $estacion = $alerta->estacion;
        $paradas = DB::table('linea_estacion')
            ->join('lineas', 'lineas.id_linea', '=', 'linea_estacion.id_linea')
            ->where('linea_estacion.id_estacion', $estacion->id_estacion)
            ->where('lineas.estado', 'activa')
            ->pluck('linea_estacion.orden_parada', 'linea_estacion.id_linea');
        // Solo salen de refuerzo los buses de las líneas que están en su horario.
        $enServicio = $this->lineasEnServicio();
        $paradas = $paradas->filter(fn ($orden, $idLinea) => in_array($idLinea, $enServicio, true));

        // Primero un bus en reserva; si no hay, uno de la línea que no esté haciendo un recorrido simulado.
        $lineaPreferida = $alerta->tipo_alerta === 'bus_lleno' ? $alerta->bus?->id_linea_asignada : null;
        $bus = Bus::whereIn('id_linea_asignada', $paradas->keys())
            ->whereIn('estado_logico', ['en_espera', 'en_ruta'])
            ->whereHas('piloto')   // solo sale de refuerzo una unidad con piloto
            ->whereDoesntHave('viajes', fn ($q) => $q->where('estado', 'en_curso'))
            ->with('linea:'.self::COLUMNAS_LINEA)
            ->when($lineaPreferida, fn ($q) => $q->orderByRaw('CASE WHEN id_linea_asignada = ? THEN 0 ELSE 1 END', [$lineaPreferida]))
            ->orderByRaw("CASE WHEN estado_logico = 'en_espera' THEN 0 ELSE 1 END")
            ->orderBy('placa')
            ->lockForUpdate()
            ->first();

        if (! $bus) {
            $avisadas = $this->estado['sin_bus'] ?? $this->compartido()->get('simulacion.sin_bus', []);
            if (! in_array($alerta->id_alerta, $avisadas, true)) {
                $this->evento('alerta', "Sin buses disponibles para {$estacion->nombre}: la alerta sigue abierta.");
                if ($this->estado !== null) {
                    $this->estado['sin_bus'][] = $alerta->id_alerta;
                } else {
                    $this->compartido()->forever('simulacion.sin_bus', array_slice([...$avisadas, $alerta->id_alerta], -100));
                }
            }

            return null;
        }

        $bus->update(['estado_logico' => 'en_ruta', 'pasajeros_a_bordo' => 0]);
        $viaje = Viaje::create(['id_bus' => $bus->id_bus, 'id_linea' => $bus->id_linea_asignada,
            'fecha_hora_inicio' => Carbon::now(), 'estado' => 'en_curso']);
        $viaje->setRelation('bus', $bus);
        $antes = $estacion->aforo_actual;
        $parada = $this->detenerBus($viaje, $estacion->id_estacion, (int) $paradas[$bus->id_linea_asignada], true);

        $alerta->update(['estado' => 'atendida', 'fecha_cierre' => Carbon::now()]);
        BitacoraAuditoria::create([
            'id_usuario' => $idUsuario,
            'tabla_afectada' => 'alertas_sistema',
            'id_registro_afectado' => $alerta->id_alerta,
            'accion' => 'UPDATE',
            'datos_previos' => ['estado' => 'abierta', 'aforo_actual' => $antes],
            'datos_nuevos' => ['estado' => 'atendida', 'bus_refuerzo' => $bus->placa, 'subieron' => $parada['subieron']],
        ]);

        $motivo = $alerta->tipo_alerta === 'bus_lleno' ? 'Otro bus por bus lleno' : 'Refuerzo';
        $this->evento('refuerzo', "{$motivo}: {$bus->placa} ({$bus->linea->codigo_linea}) llegó a {$estacion->nombre}; "
            ."subieron {$parada['subieron']} pasajeros y la alerta quedó atendida.");

        return $bus->placa;
    }

    /** Deja las estaciones con un aforo normal y limpia el registro de eventos. */
    public function reiniciar(): void
    {
        DB::update('UPDATE estaciones SET aforo_actual = ROUND(aforo_maximo * (0.2 + random() * 0.3))::int');
        $this->compartido()->forget(self::CLAVE_EVENTOS);
        $this->compartido()->forget(self::CLAVE_VISTAS);
        $this->compartido()->forget('simulacion.sin_bus');
        $this->compartido()->forever(self::CLAVE_CICLO, 0);
        $this->evento('info', 'Aforos reiniciados (entre el 20 % y el 50 %).');
    }

    /** Agrega un evento al registro visible en la pantalla (los 60 más recientes). */
    public function evento(string $tipo, string $texto): void
    {
        $evento = ['hora' => Carbon::now()->format('H:i:s'), 'tipo' => $tipo,
            'texto' => Str::limit($texto, 300), 'ciclo' => $this->numeroCiclo()];

        if ($this->estado !== null) {   // dentro de un ciclo: se guarda al final
            array_unshift($this->estado['eventos'], $evento);

            return;
        }
        $eventos = $this->compartido()->get(self::CLAVE_EVENTOS, []);
        array_unshift($eventos, $evento);
        $this->compartido()->forever(self::CLAVE_EVENTOS, array_slice($eventos, 0, 60));
    }
}
