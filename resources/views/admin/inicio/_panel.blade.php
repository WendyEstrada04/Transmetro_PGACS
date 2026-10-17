{{--
    Panel de una ruta (pantalla principal del administrador). Se abre como cortina desde la derecha.
    Variables: PanelLinea::datos() → $linea, $municipio, $estaciones, $buses, $alertas, $pasajeros, $personal, $flota, $aforo, $silueta.
--}}
@use('App\Models\HorarioLinea')
@use('App\Models\Linea')
@php
    $color = $linea->color ?: '#404d63';
    $claro = $linea->colorTexto() !== '#ffffff';       // colores claros (amarillo, gris…) llevan letra oscura
    $tinta = $claro ? '#162133' : '#ffffff';
    $legible = $claro ? "color-mix(in srgb, {$color} 55%, #162133)" : $color;
    $hoy = $linea->horarioDelDia();
    $enServicio = $linea->enServicio();
    $estadosBus = [
        'en_ruta' => ['En ruta', 'bg-emerald-50 text-emerald-700 ring-emerald-600/20'],
        'en_espera' => ['En espera', 'bg-tm-100 text-tm-700 ring-tm-500/20'],
        'fuera_de_servicio' => ['Fuera de servicio', 'bg-slate-100 text-slate-600 ring-slate-500/20'],
        'en_mantenimiento' => ['Mantenimiento', 'bg-amber-50 text-amber-800 ring-amber-600/20'],
    ];
    $barra = fn ($pct) => $pct >= 150 ? 'bg-red-500' : ($pct >= 100 ? 'bg-amber-400' : 'bg-tm-500');
    $iniciales = fn ($nombre) => collect(preg_split('/\s+/', trim($nombre)))->filter()->take(2)->map(fn ($p) => mb_substr($p, 0, 1))->implode('');
    $dias = ['D', 'L', 'M', 'X', 'J', 'V', 'S'];
    $totalPersonal = $personal['choferes'] + $personal['operadores'] + $personal['guardias'];
    $pctAforo = $aforo['maximo'] ? round($aforo['actual'] * 100 / $aforo['maximo']) : 0;
@endphp

<div class="flex h-full flex-col bg-white"
     data-panel="{{ $linea->codigo_linea }}"
     data-estaciones='@json($estaciones->map(fn ($e) => ['id' => $e->id_estacion, 'pct' => $e->pct, 'alerta' => $e->alertas > 0])->values())'>

    {{-- Cabecera en el color de la ruta, con el dibujo de su recorrido --}}
    <header class="relative shrink-0 overflow-hidden px-7 pb-6 pt-5" style="background: {{ $color }}; color: {{ $tinta }}">
        <div class="pointer-events-none absolute inset-0" style="background: radial-gradient(420px 220px at 100% 0%, rgb(255 255 255 / .22), transparent 70%), linear-gradient(180deg, transparent 40%, rgb(22 33 51 / {{ $claro ? '.06' : '.28' }}))"></div>
        @if ($silueta['d'])
            <svg class="pointer-events-none absolute right-5 top-[70px] h-[130px] w-[210px]" viewBox="0 0 240 150" fill="none" aria-hidden="true">
                <path d="{{ $silueta['d'] }}" stroke="currentColor" stroke-opacity=".38" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"
                      class="dibujar" style="--largo: {{ $silueta['largo'] }}; --retraso: 250ms"/>
                @foreach ($silueta['paradas'] as [$x, $y])
                    <circle cx="{{ $x }}" cy="{{ $y }}" r="3.2" fill="currentColor" fill-opacity=".55" class="estallar" style="--retraso: {{ 600 + $loop->index * 45 }}ms"/>
                @endforeach
            </svg>
        @endif

        <div class="relative flex items-center justify-between">
            <p class="rotulo aparecer opacity-80">Línea · {{ $municipio }}</p>
            <button type="button" data-cerrar aria-label="Cerrar panel"
                    class="flex h-10 w-10 items-center justify-center rounded-full ring-1 ring-current/35 transition-all duration-500 hover:rotate-90 hover:bg-white/20">
                <svg class="h-4 w-4" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M3.5 3.5l7 7M10.5 3.5l-7 7"/></svg>
            </button>
        </div>
        <p class="relative mt-1 text-[104px] font-black leading-[0.82] tracking-[-0.065em]">
            <span class="mascara"><span style="--retraso: 120ms">{{ $linea->codigo_linea }}</span></span>
        </p>
        <h2 class="aparecer relative mt-4 max-w-[75%] text-[21px] font-extrabold leading-tight tracking-tight" style="--retraso: 260ms">{{ $linea->nombre }}</h2>
        <div class="aparecer relative mt-4 flex flex-wrap items-center gap-x-4 gap-y-1 text-[12.5px] font-semibold" style="--retraso: 360ms">
            <span class="flex items-center gap-2">
                <span class="h-2 w-2 rounded-full {{ $enServicio ? 'latido bg-emerald-400' : 'bg-current opacity-50' }}"></span>
                {{ $enServicio ? 'En servicio' : ($linea->estado === 'activa' ? 'Fuera de horario' : 'Línea '.$linea->estado) }}
            </span>
            @if ($hoy)<span class="opacity-75">Hoy {{ $hoy->inicio() }}–{{ $hoy->fin() }}</span>@endif
            <span class="opacity-75">{{ Linea::TIPOS_SERVICIO[$linea->tipo_servicio] ?? $linea->tipo_servicio }}</span>
        </div>
    </header>

    {{-- Pestañas --}}
    <nav class="pestanas shrink-0 px-7" role="tablist">
        <span class="indicador" aria-hidden="true"></span>
        @foreach ([
            ['resumen', 'Resumen', null],
            ['estaciones', 'Estaciones', $estaciones->count()],
            ['personal', 'Personal', $totalPersonal],
            ['buses', 'Buses', $flota['total']],
            ['reportes', 'Reportes', null],
        ] as [$clave, $texto, $cuenta])
            <button type="button" role="tab" data-ir="{{ $clave }}" aria-selected="{{ $loop->first ? 'true' : 'false' }}">
                {{ $texto }}@if ($cuenta !== null)<sup class="ml-0.5 text-[9px] opacity-70">{{ $cuenta }}</sup>@endif
            </button>
        @endforeach
    </nav>

    <div class="flex-1 overflow-y-auto overscroll-contain px-7 pb-10 pt-2" data-desplazable>

        {{-- ============ RESUMEN ============ --}}
        <section data-seccion="resumen" class="cascada">
            <div class="cifras mt-4" style="--i: 0">
                @foreach ([
                    ['Subieron hoy', $pasajeros['hoy'], 0, '', 'Un pago por persona en el molinete de entrada · '.number_format($pasajeros['mes']).' en 30 días'],
                    ['Bajaron hoy', $pasajeros['bajaronHoy'], 0, '', 'Contadas en el molinete de salida'],
                    ['A bordo ahora', $pasajeros['aBordo'], 0, '', 'Dentro de los buses en este momento'.($pasajeros['llenos'] ? ' · '.$pasajeros['llenos'].' '.($pasajeros['llenos'] === 1 ? 'bus lleno' : 'buses llenos') : '')],
                    ['Recaudado hoy', $pasajeros['recaudadoHoy'], 2, 'Q', 'Q'.number_format($pasajeros['recaudadoMes'], 2).' en 30 días'],
                    ['Esperando en estaciones', $aforo['actual'], 0, '', 'Capacidad de las estaciones de la línea: '.number_format($aforo['maximo'])." · {$pctAforo} % ocupado"],
                    ['Buses en ruta', $flota['enRuta'], 0, '', "de {$flota['total']} asignados · van en promedio al {$flota['ocupacionPromedio']} % de su capacidad"],
                ] as [$etiqueta, $valor, $decimales, $prefijo, $nota])
                    <div>
                        <p class="rotulo !text-[10px] text-acero">{{ $etiqueta }}</p>
                        <p class="mt-2 text-[36px] font-black leading-none tracking-[-0.04em] text-noche tabular-nums"
                           data-contar="{{ $valor }}" data-decimales="{{ $decimales }}" data-prefijo="{{ $prefijo }}">{{ $prefijo }}{{ number_format($valor, $decimales) }}</p>
                        <p class="mt-2 text-[11.5px] leading-snug text-acero">{{ $nota }}</p>
                    </div>
                @endforeach
            </div>

            {{-- Pasajeros de los últimos 7 días --}}
            <div class="mt-8" style="--i: 1">
                <div class="mb-4 flex items-baseline justify-between">
                    <p class="text-[17px] font-black tracking-tight">Pasajeros por día</p>
                    <p class="rotulo !text-[10px] text-acero">Últimos 7 días</p>
                </div>
                <div class="flex h-28 items-end gap-2.5">
                    @foreach ($pasajeros['semana'] as $dia)
                        @php $esHoy = $dia['dia']->isToday(); @endphp
                        <div class="flex h-full flex-1 flex-col items-center justify-end gap-1.5" title="{{ $dia['dia']->format('d/m') }}: {{ $dia['pases'] }} pases">
                            <span class="text-[10.5px] font-bold tabular-nums text-pizarra">{{ $dia['pases'] ?: '' }}</span>
                            <div class="crecer w-full rounded-t-[4px] {{ $dia['pases'] ? '' : 'bg-tm-100' }}"
                                 style="height: {{ max(3, round($dia['pases'] * 100 / $pasajeros['maximoSemana'])) }}%; --i: {{ $loop->index }}; {{ $dia['pases'] ? 'background: '.($esHoy ? $color : '#162133').';' : '' }} {{ $esHoy && $claro ? 'box-shadow: inset 0 0 0 1px rgb(22 33 51 / .25);' : '' }}"></div>
                            <span class="text-[10.5px] font-bold {{ $esHoy ? 'text-noche' : 'text-acero' }}">{{ $esHoy ? 'Hoy' : $dias[$dia['dia']->dayOfWeek] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Flota (punto 5: entre n y 2n buses) --}}
            <div class="mt-8 border-t border-noche/[0.08] pt-6" style="--i: 2">
                <div class="flex items-end justify-between gap-4">
                    <div>
                        <p class="rotulo !text-[10px] text-acero">Flota asignada</p>
                        <p class="mt-2 text-[28px] font-black leading-none tracking-[-0.03em]">{{ $flota['total'] }} <span class="text-[15px] font-extrabold text-acero">buses</span></p>
                    </div>
                    <span class="etiqueta ring-1 {{ $flota['dentroDelRango'] ? 'bg-emerald-50 text-emerald-700 ring-emerald-600/20' : 'bg-red-50 text-red-700 ring-red-600/20' }}">
                        Permitido {{ $flota['minimo'] }}–{{ $flota['maximo'] }} {{ $flota['dentroDelRango'] ? '✓' : '✗' }}
                    </span>
                </div>
                @if ($flota['total'])
                    <div class="crecer-x mt-4 flex h-2.5 overflow-hidden rounded-full bg-tm-100">
                        <div style="width: {{ $flota['enRuta'] * 100 / $flota['total'] }}%; background: #162133"></div>
                        <div style="width: {{ $flota['enEspera'] * 100 / $flota['total'] }}%; background: #8eb9fc"></div>
                        <div style="width: {{ $flota['fueraDeServicio'] * 100 / $flota['total'] }}%; background: #99acc9"></div>
                    </div>
                @endif
                <div class="mt-3 flex flex-wrap gap-x-5 gap-y-1 text-[12px] font-semibold text-pizarra">
                    <span class="flex items-center gap-1.5"><i class="h-2 w-2 rounded-full bg-noche"></i>{{ $flota['enRuta'] }} en ruta</span>
                    <span class="flex items-center gap-1.5"><i class="h-2 w-2 rounded-full bg-cielo"></i>{{ $flota['enEspera'] }} en espera</span>
                    <span class="flex items-center gap-1.5"><i class="h-2 w-2 rounded-full bg-niebla"></i>{{ $flota['fueraDeServicio'] }} fuera de servicio</span>
                    <span class="text-acero">{{ $pasajeros['viajesEnCurso'] }} viajes en curso</span>
                </div>
            </div>

            {{-- Alertas --}}
            <div class="mt-8" style="--i: 3">
                <p class="rotulo mb-3 !text-[10px] text-acero">Alertas</p>
                @forelse ($alertas as $alerta)
                    <button type="button" data-estacion="{{ $alerta->id_estacion }}"
                            class="group mb-2 block w-full border-l-[3px] border-red-500 bg-red-50/70 py-3 pl-4 pr-3 text-left transition-colors hover:bg-red-50">
                        <p class="flex items-center gap-2 text-[14px] font-extrabold text-red-800">
                            <span class="latido h-2 w-2 rounded-full bg-red-500"></span>
                            {{ $alerta->estacion }}{{ $alerta->tipo_alerta === 'bus_lleno' ? ' · bus lleno' : '' }}
                            <span class="ml-auto text-[11px] font-semibold text-red-700/70">{{ $alerta->fecha_creacion->format('H:i') }}</span>
                        </p>
                        <p class="mt-0.5 text-[12.5px] text-red-800/80">{{ $alerta->descripcion }}</p>
                    </button>
                @empty
                    <p class="flex items-center gap-2.5 text-[13px] font-semibold text-pizarra">
                        <span class="flex h-6 w-6 items-center justify-center rounded-full bg-emerald-50 text-emerald-600 ring-1 ring-emerald-600/20">
                            <svg class="h-3.5 w-3.5" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3.5 8.5l3 3 6-7"/></svg>
                        </span>
                        Sin alertas abiertas en esta ruta
                    </p>
                @endforelse
            </div>

            {{-- Datos de la ruta --}}
            <dl class="mt-8 border-t border-noche/[0.08] text-[13px]" style="--i: 4">
                @foreach ([
                    ['Distancia', number_format($linea->distancia_total_km, 2).' km'],
                    ['Tarifa', 'Q'.number_format($linea->tarifa, 2)],
                    ['Estaciones', $estaciones->count().' ('.$estaciones->where('transbordo', true)->count().' de transbordo)'],
                    ['Recorrido', $linea->ruta_circular ? 'Circular' : 'Ida y regreso'],
                ] as [$dato, $valor])
                    <div class="flex justify-between border-b border-noche/[0.08] py-3"><dt class="text-acero">{{ $dato }}</dt><dd class="font-bold">{{ $valor }}</dd></div>
                @endforeach
                @foreach (HorarioLinea::TIPOS_DIA as $tipo => $nombreDia)
                    @php $h = $linea->horarios->firstWhere('tipo_dia', $tipo); @endphp
                    <div class="flex justify-between border-b border-noche/[0.08] py-3"><dt class="text-acero">{{ $nombreDia }}</dt><dd class="font-bold tabular-nums">{{ $h ? $h->inicio().' – '.$h->fin() : 'Sin servicio' }}</dd></div>
                @endforeach
            </dl>

            <div class="mt-8 -mx-7 border-t border-noche/[0.07]" style="--i: 5">
                @foreach ([
                    [route('admin.lineas.show', $linea), 'Ficha completa', 'Estaciones, buses y horario'],
                    [route('admin.lineas.show', $linea).'#estado', 'Estado de la línea', 'Activa, en mantenimiento o inactiva'],
                    [route('admin.estaciones.index'), 'Estaciones y accesos', 'Estado de los molinetes de cada estación'],
                ] as [$url, $texto, $ayuda])
                    <a href="{{ $url }}" class="menu-item !px-7">
                        <span>
                            <span class="block text-[17px] font-extrabold tracking-tight text-noche">{{ $texto }}</span>
                            <span class="block text-[12px] text-acero">{{ $ayuda }}</span>
                        </span>
                        <svg class="flecha h-5 w-5 shrink-0 text-noche" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M4 10h12M11 5l5 5-5 5"/></svg>
                    </a>
                @endforeach
            </div>
        </section>

        {{-- ============ ESTACIONES ============ --}}
        <section data-seccion="estaciones" hidden>
            <p class="mb-4 mt-4 text-[12px] leading-relaxed text-acero">Selecciona una estación para ubicarla en el mapa. ↑ abordajes y ↓ descensos del día en esta ruta.</p>
            <ol class="cascada">
                @foreach ($estaciones as $e)
                    <li class="relative flex gap-4" style="--i: {{ min($loop->index, 12) }}">
                        <div class="relative flex w-5 shrink-0 justify-center">
                            <span class="absolute w-1 {{ $loop->first ? 'top-4' : 'top-0' }} {{ $loop->last ? 'h-4' : 'bottom-0' }}" style="background: {{ $color }}"></span>
                            <span class="relative mt-3 h-4 w-4 rounded-full border-4 bg-white {{ $e->transbordo ? 'outline-2 outline-offset-1 outline-noche' : '' }}"
                                  style="border-color: {{ $e->alertas ? '#ef4444' : $color }}"></span>
                        </div>
                        <button type="button" data-estacion="{{ $e->id_estacion }}"
                                class="group min-w-0 flex-1 border-b border-noche/[0.06] py-3 text-left">
                            <div class="flex items-baseline justify-between gap-3">
                                <span class="truncate text-[14.5px] font-extrabold tracking-tight transition-transform duration-500 group-hover:translate-x-1">{{ $e->nombre }}</span>
                                <span class="shrink-0 text-[12px] font-bold tabular-nums {{ $e->pct >= 150 ? 'text-red-600' : 'text-acero' }}">{{ $e->pct }} %</span>
                            </div>
                            <div class="mt-2 h-1 overflow-hidden rounded-full bg-tm-100">
                                <div class="crecer-x h-full rounded-full {{ $barra($e->pct) }}" style="width: {{ min($e->pct / 2, 100) }}%"></div>
                            </div>
                            <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-0.5 text-[11.5px] text-acero">
                                <span class="tabular-nums">{{ $e->aforo_actual }}/{{ $e->aforo_maximo }} personas</span>
                                <span class="tabular-nums" data-sube-baja>↑ {{ number_format($e->subieron) }} · ↓ {{ number_format($e->bajaron) }}</span>
                                <span class="{{ $e->guardias ? '' : 'font-bold text-amber-700' }}" title="{{ collect($e->guardias)->pluck('nombre')->implode(', ') }}">
                                    {{ count($e->guardias) }} {{ count($e->guardias) === 1 ? 'guardia' : 'guardias' }}
                                </span>
                                @if ($e->transbordo)<span class="font-bold text-noche">Transbordo</span>@endif
                                @if ($e->alertas)<span class="font-bold text-red-600">Otro bus pedido</span>@endif
                            </div>
                        </button>
                    </li>
                @endforeach
            </ol>
            <a href="{{ route('admin.estaciones.index') }}" class="enlace mt-6 inline-block text-[13px]">Ver todas las estaciones →</a>
        </section>

        {{-- ============ PERSONAL: chofer de cada bus; operador y seguridad de cada estación ============ --}}
        <section data-seccion="personal" hidden class="cascada">
            <div class="mt-6" style="--i: 0">
                <div class="flex items-end justify-between border-b-2 border-noche pb-2">
                    <div>
                        <p class="text-[22px] font-black leading-none tracking-[-0.03em]">Chofer de cada bus</p>
                        <p class="mt-1 text-[11.5px] text-acero">Bus y piloto asignado</p>
                    </div>
                    <span class="text-[28px] font-black leading-none tracking-[-0.04em] tabular-nums" style="color: {{ $legible }}">{{ $personal['equipos']->count() }}</span>
                </div>
                <ul>
                    @forelse ($personal['equipos'] as $equipo)
                        <li class="grid grid-cols-[4.5rem_minmax(0,1fr)] gap-3 border-b border-noche/[0.06] py-2.5">
                            <span class="self-start rounded-md px-2 py-1 text-center text-[12.5px] font-black tracking-[0.04em]"
                                  style="background: color-mix(in srgb, {{ $color }} 18%, white); color: {{ $legible }}">{{ $equipo->placa }}</span>
                            <div class="min-w-0 text-[12.5px] leading-snug">
                                <p class="truncate"><span class="text-acero">Chofer</span> <b class="{{ $equipo->piloto ? '' : 'text-amber-700' }}">{{ $equipo->piloto ?? 'Sin asignar' }}</b></p>
                            </div>
                        </li>
                    @empty
                        <li class="py-3 text-[12.5px] font-bold text-amber-700">La ruta no tiene buses asignados.</li>
                    @endforelse
                </ul>
            </div>

            <div class="mt-8" style="--i: 1">
                <div class="flex items-end justify-between border-b-2 border-noche pb-2">
                    <div>
                        <p class="text-[22px] font-black leading-none tracking-[-0.03em]">Cada estación</p>
                        <p class="mt-1 text-[11.5px] text-acero">Operador en la PC de la estación y guardias en sus accesos</p>
                    </div>
                    <span class="text-[28px] font-black leading-none tracking-[-0.04em] tabular-nums" style="color: {{ $legible }}">{{ $personal['operadores'] + $personal['guardias'] }}</span>
                </div>
                <ul>
                    @foreach ($personal['seguridad'] as $grupo)
                        <li class="border-b border-noche/[0.06] py-2.5">
                            <p class="text-[13.5px] font-extrabold">{{ $grupo->orden }}. {{ $grupo->estacion }}</p>
                            <p class="truncate text-[12px] text-pizarra"><span class="text-acero">Operador</span> <b class="{{ $grupo->operador ? '' : 'text-amber-700' }}">{{ $grupo->operador ?? 'Sin asignar' }}</b></p>
                            @forelse ($grupo->guardias as $g)
                                <p class="truncate text-[12px] text-pizarra">{{ $g['nombre'] }} <span class="text-acero">· {{ $g['acceso'] }}{{ $g['placa'] ? ' · placa '.$g['placa'] : '' }}</span></p>
                            @empty
                                <p class="text-[12px] font-bold text-amber-700">Sin guardia asignado</p>
                            @endforelse
                        </li>
                    @endforeach
                </ul>
            </div>
            <a href="{{ route('admin.personal.index') }}" class="enlace mt-6 inline-block text-[13px]" style="--i: 2">Administrar personal →</a>
        </section>

        {{-- ============ BUSES ============ --}}
        <section data-seccion="buses" hidden>
            <ul class="cascada mt-2">
                @forelse ($buses as $bus)
                    @php [$estadoTexto, $estadoClase] = $estadosBus[$bus->estado_logico] ?? [$bus->estado_logico, 'bg-slate-100 text-slate-600 ring-slate-500/20']; @endphp
                    <li class="border-b border-noche/[0.06] py-3.5" style="--i: {{ min($loop->index, 12) }}">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-[16px] font-black tracking-[0.04em]">{{ $bus->placa }} <span class="text-[11px] font-bold tracking-normal text-acero">{{ \App\Models\Bus::TIPOS_UNIDAD[$bus->tipo_unidad][0] ?? '' }}</span></span>
                            <span class="etiqueta ring-1 {{ $estadoClase }}">{{ $estadoTexto }}</span>
                        </div>
                        <p class="mt-0.5 truncate text-[12.5px] text-pizarra"><span class="text-acero">Chofer</span> <b>{{ $bus->piloto ?? 'sin asignar' }}</b></p>
                        <div class="mt-2 flex items-center gap-3">
                            <div class="h-1 flex-1 overflow-hidden rounded-full bg-tm-100">
                                <div class="crecer-x h-full rounded-full {{ $bus->ocupacion_actual_pct >= 90 ? 'bg-red-500' : ($bus->ocupacion_actual_pct < 25 ? 'bg-niebla' : 'bg-noche') }}" style="width: {{ min($bus->ocupacion_actual_pct, 100) }}%"></div>
                            </div>
                            <span class="w-24 text-right text-[12px] font-bold tabular-nums {{ $bus->lleno ? 'text-red-600' : 'text-pizarra' }}">{{ $bus->pasajeros_a_bordo }}/{{ $bus->capacidad_maxima }}{{ $bus->lleno ? ' lleno' : ' · '.round($bus->ocupacion_actual_pct).' %' }}</span>
                        </div>
                        <p class="mt-1.5 truncate text-[11.5px] text-acero">
                            @if ($bus->ultima_parada) En {{ $bus->ultima_parada }} (↑{{ $bus->ultima_subieron }} ↓{{ $bus->ultima_bajaron }}){{ $bus->proxima_parada ? ' → próxima: '.$bus->proxima_parada : ' · fin del recorrido' }} @endif
                            @if ($bus->ultima_parada && $bus->codigo_bahia) · @endif
                            @if ($bus->codigo_bahia) Parqueo {{ $bus->codigo_bahia }}{{ $bus->parqueo_estacion ? ' en '.$bus->parqueo_estacion : '' }} @endif
                        </p>
                    </li>
                @empty
                    <li class="py-3 text-[12.5px] font-bold text-amber-700">La ruta no tiene buses asignados.</li>
                @endforelse
            </ul>
            <a href="{{ route('admin.buses.index') }}" class="enlace mt-6 inline-block text-[13px]">Administrar buses →</a>
        </section>
        {{-- ============ REPORTES (punto 15) ============ --}}
        <section data-seccion="reportes" hidden class="cascada">
            <p class="mb-2 mt-5 text-[12px] leading-relaxed text-acero" style="--i: 0">Reportes de la línea {{ $linea->codigo_linea }}. Se ven en pantalla y se imprimen o guardan en PDF.</p>
            <div class="-mx-7 border-t border-noche/[0.07]">
                @foreach (\App\Services\Reportes::definiciones() as $clave => $reporte)
                    <div class="menu-item !px-7" style="--i: {{ $loop->index + 1 }}">
                        <a href="{{ route('admin.reportes.show', [$clave, 'linea' => $linea->codigo_linea]) }}" class="min-w-0 flex-1">
                            <span class="block text-[16px] font-extrabold tracking-tight text-noche">{{ $reporte['titulo'] }}</span>
                            <span class="block text-[11.5px] text-acero">{{ $reporte['descripcion'] }}</span>
                        </a>
                        <svg class="flecha h-5 w-5 shrink-0 text-noche" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M4 10h12M11 5l5 5-5 5"/></svg>
                    </div>
                @endforeach
            </div>
            <a href="{{ route('admin.reportes.index') }}" class="enlace mt-6 inline-block text-[13px]" style="--i: 7">Reportes de toda la red →</a>
        </section>
    </div>
</div>
