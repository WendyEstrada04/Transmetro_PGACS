@extends('layouts.app')

@section('titulo', $estacion->nombre)

@push('head')
    <style>
        .chip-bus { display: inline-flex; align-items: center; border-radius: 999px; padding: 2px 10px; font-size: 12px; font-weight: 900;
                    background: color-mix(in srgb, var(--c) 18%, white); color: #162133; box-shadow: inset 0 0 0 1.5px var(--c); }
    </style>
@endpush

@section('contenido')
    @php
        use App\Models\AlertaSistema;
        use App\Models\MantenimientoBus;
        use App\Models\Molinete;

        $pct = $estacion->porcentajeAforo();
        $barra = $pct >= 150 ? 'bg-red-500' : ($pct >= 100 ? 'bg-amber-400' : 'bg-noche');
        $pedida = $alertas->whereIn('tipo_alerta', AlertaSistema::DE_REFUERZO)->isNotEmpty();
        $guardias = $estacion->accesos->sum(fn ($a) => $a->guardias->count());
        $estadoMant = ['reportado' => 'bg-amber-50 text-amber-800 ring-amber-600/20', 'en_proceso' => 'bg-tm-50 text-pizarra ring-pizarra/20', 'resuelto' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20'];
        $opcionesDetenidos = $detenidos->mapWithKeys(fn ($b) => [$b['id'] => $b['placa'].' · '.$b['linea'].' · '.$b['pasajeros'].'/'.$b['capacidad'].' a bordo'])->all();
        // Pestaña que se abre si el formulario volvió con errores.
        $pestanaError = $errors->any() ? ($errors->hasAny(['estado_operativo']) ? 'accesos' : 'buses') : null;
    @endphp

    <x-titulo :titulo="$estacion->nombre" seccion="Operador · PC de la estación" ilustracion="pulso"
              :subtitulo="$estacion->municipio->nombre.' · '.$estacion->accesos->count().' accesos · Actualizado a las '.now()->format('H:i')" />

    {{-- Estación y operador --}}
    <div class="revelar mb-8 grid gap-px overflow-hidden rounded-xl bg-noche/[0.08] shadow-sm sm:grid-cols-2 lg:grid-cols-4">
        <div class="bg-white p-5">
            <p class="rotulo !text-[10px] text-acero">Esperando ahora</p>
            <p class="mt-1.5 text-[30px] font-black leading-none tracking-[-0.04em] tabular-nums {{ $pct >= 150 ? 'text-red-600' : '' }}" data-esperando>{{ number_format($estacion->aforo_actual) }}</p>
            <p class="mt-2 text-[12px] text-acero">de {{ number_format($estacion->aforo_maximo) }} de aforo · {{ round($pct) }} %</p>
        </div>
        <div class="bg-white p-5">
            <p class="rotulo !text-[10px] text-acero">Líneas que pasan</p>
            <p class="mt-2 flex flex-wrap gap-1.5">
                @foreach ($estacion->lineas as $l)
                    <span class="rounded-md px-2 py-0.5 text-[14px] font-black {{ $l->enServicio() ? '' : 'opacity-40' }}" style="background: {{ $l->color }}; color: {{ $l->colorTexto() }}"
                          title="{{ $l->nombre }} · {{ $l->enServicio() ? 'en servicio' : 'fuera de horario' }}">{{ $l->codigo_linea }}</span>
                @endforeach
            </p>
            <p class="mt-2 text-[12px] text-acero">{{ $estacion->lineas->filter->enServicio()->count() }} de {{ $estacion->lineas->count() }} en servicio ahora</p>
        </div>
        <div class="bg-white p-5">
            <p class="rotulo !text-[10px] text-acero">Buses</p>
            <p class="mt-1.5 text-[30px] font-black leading-none tracking-[-0.04em] tabular-nums">{{ $detenidos->count() }}</p>
            <p class="mt-2 text-[12px] text-acero">en la estación · {{ $enCamino->count() }} en camino</p>
        </div>
        <div class="bg-white p-5">
            <p class="rotulo !text-[10px] text-acero">Operador</p>
            <p class="mt-1.5 truncate text-[17px] font-extrabold">{{ $operador->empleado->nombre_completo }}</p>
            <p class="mt-1 text-[12px] text-acero">{{ $operador->anios_experiencia_monitoreo }} años en monitoreo</p>
        </div>
    </div>

    @if ($simulacionActiva)
        <div class="mb-6 flex items-center gap-3 rounded-xl bg-white px-5 py-3 text-sm text-pizarra shadow-sm"><span class="latido h-2 w-2 rounded-full bg-emerald-500"></span>
            Modo simulación activo.
        </div>
    @endif

    @if ($alertas->isNotEmpty())
        <a href="#estacion" data-ir-a="estacion" class="mb-6 flex items-center justify-between gap-3 rounded-xl border border-red-200 bg-red-50 px-5 py-3 text-sm text-red-800">
            <span><b>{{ $alertas->count() }} {{ $alertas->count() === 1 ? 'alerta abierta' : 'alertas abiertas' }}</b> en tu estación: {{ $alertas->map(fn ($a) => AlertaSistema::TIPOS[$a->tipo_alerta] ?? $a->tipo_alerta)->unique()->join(', ') }}.</span>
            <span class="shrink-0 font-bold">Ver →</span>
        </a>
    @endif

    {{-- Pestañas --}}
    <div id="tablero-operador" class="rounded-xl bg-white shadow-sm">
        <nav class="pestanas px-6" role="tablist">
            <span class="indicador" aria-hidden="true"></span>
            @foreach ([
                ['estacion', 'Estación', $alertas->count() ?: null],
                ['buses', 'Buses', $detenidos->count() + $enCamino->count()],
                ['accesos', 'Accesos y seguridad', $guardias],
                ['pasajeros', 'Pasajeros', null],
            ] as [$clave, $texto, $cuenta])
                <button type="button" role="tab" data-ir="{{ $clave }}" aria-selected="false">
                    {{ $texto }}@if ($cuenta !== null)<sup class="ml-0.5 text-[9px] {{ $clave === 'estacion' ? 'text-red-600' : 'opacity-70' }}">{{ $cuenta }}</sup>@endif
                </button>
            @endforeach
        </nav>

        <div class="p-6">
            {{-- ============ ESTACIÓN: aforo, alertas y pedir otro bus (punto 14) ============ --}}
            <section data-seccion="estacion" hidden>
                <div class="mb-6 rounded-xl bg-noche px-7 py-6 text-white">
                    <div class="flex flex-wrap items-end justify-between gap-6">
                        <div>
                            <p class="rotulo !text-[11px] text-cielo">Personas esperando</p>
                            <p class="mt-2 text-[48px] font-black leading-none tracking-[-0.04em] tabular-nums">{{ number_format($estacion->aforo_actual) }}
                                <span class="text-[18px] font-bold text-niebla">/ {{ number_format($estacion->aforo_maximo) }}</span></p>
                        </div>
                        <div class="text-right">
                            @if ($pedida)
                                <p class="rounded-md bg-red-500/20 px-3 py-2 text-[13px] font-bold text-red-200">Otro bus pedido: la central lo está enviando</p>
                            @else
                                <form method="POST" action="{{ route('operador.refuerzo') }}">
                                    @csrf
                                    <button class="btn btn-primario !bg-white !text-noche">Pedir otro bus</button>
                                </form>
                            @endif
                        </div>
                    </div>
                    <div class="mt-5 h-2 overflow-hidden rounded-full bg-white/15"><span class="block h-full rounded-full {{ $barra }}" style="width: {{ min($pct / 2, 100) }}%"></span></div>
                    <p class="mt-2 flex justify-between text-[12px] text-niebla"><span>{{ round($pct) }} % del aforo</span><span>Al 150 % el sistema pide otro bus solo</span></p>
                </div>

                @foreach ($alertas as $alerta)
                    <div class="mb-3 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-red-300 bg-red-50 p-4" data-alerta>
                        <div class="text-sm">
                            <p class="font-semibold text-red-800">⚠ {{ AlertaSistema::TIPOS[$alerta->tipo_alerta] ?? str_replace('_', ' ', $alerta->tipo_alerta) }}{{ $alerta->bus ? ' · bus '.$alerta->bus->placa : '' }}</p>
                            <p class="text-red-700">{{ $alerta->descripcion }}</p>
                            <p class="text-xs text-red-600">{{ $alerta->fecha_creacion->format('d/m/Y H:i') }}</p>
                        </div>
                        <form method="POST" action="{{ route('operador.alertas.atender', $alerta) }}">
                            @csrf
                            <button class="rounded-md bg-red-600 px-3 py-2 text-sm font-medium text-white hover:bg-red-700">
                                {{ $alerta->pideBus() ? 'Enviar otro bus' : 'Marcar como atendida' }}
                            </button>
                        </form>
                    </div>
                @endforeach

                <div class="cifras !grid-cols-2 md:!grid-cols-3">
                    @foreach ([
                        ['Subieron hoy aquí', number_format($hoy['subieron']), 'Pagos en el molinete de entrada de los buses · Q'.number_format($hoy['cobrado'], 2)],
                        ['Bajaron hoy aquí', number_format($hoy['bajaron']), 'Contadas en el molinete de salida de los buses'],
                        ['Parqueos', $estacion->parqueos->count(), $estacion->parqueos->isEmpty() ? 'La estación no tiene parqueos' : $estacion->parqueos->filter->bus->count().' ocupados por buses'],
                    ] as [$etiqueta, $valor, $nota])
                        <div>
                            <p class="rotulo !text-[10px] text-acero">{{ $etiqueta }}</p>
                            <p class="mt-2 text-[32px] font-black leading-none tracking-[-0.04em] tabular-nums">{{ $valor }}</p>
                            <p class="mt-2 text-[11.5px] leading-snug text-acero">{{ $nota }}</p>
                        </div>
                    @endforeach
                </div>

                <p class="mb-3 mt-8 text-[15px] font-black tracking-tight">Horario de las líneas hoy</p>
                <ul>
                    @foreach ($estacion->lineas as $l)
                        @php $h = $l->horarioDelDia(); @endphp
                        <li class="flex items-center justify-between gap-3 border-b border-noche/[0.06] py-2.5 text-[13.5px]">
                            <span class="flex min-w-0 items-center gap-2">
                                <span class="rounded px-1.5 py-0.5 text-[12px] font-black" style="background: {{ $l->color }}; color: {{ $l->colorTexto() }}">{{ $l->codigo_linea }}</span>
                                <span class="truncate font-semibold">{{ $l->nombre }}</span>
                            </span>
                            <span class="shrink-0 text-[12px] {{ $l->enServicio() ? 'font-bold text-emerald-700' : 'text-acero' }}">
                                {{ $h ? $h->inicio().'–'.$h->fin() : 'Sin horario' }} · {{ $l->enServicio() ? 'En servicio' : 'Fuera de horario' }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            </section>

            {{-- ============ BUSES: detenidos, en camino, contingencia y fallas ============ --}}
            <section data-seccion="buses" hidden>
                <div class="grid gap-6 lg:grid-cols-2">
                    <div>
                        <p class="mb-3 text-[15px] font-black tracking-tight">En la estación ahora</p>
                        <ul data-detenidos>
                            @forelse ($detenidos as $b)
                                <li class="flex items-center justify-between gap-3 border-b border-noche/[0.06] py-3">
                                    <span class="flex min-w-0 items-center gap-2">
                                        <span class="chip-bus" style="--c: {{ $b['color'] }}">{{ $b['placa'] }}</span>
                                        <span class="text-[13px] text-pizarra">{{ $b['linea'] }} · hacia {{ $nombres[$b['hacia']] ?? '—' }}</span>
                                    </span>
                                    <span class="shrink-0 text-[12px] tabular-nums {{ $b['lleno'] ? 'font-bold text-red-600' : 'text-acero' }}">{{ $b['pasajeros'] }}/{{ $b['capacidad'] }} a bordo{{ $b['lleno'] ? ' · lleno' : '' }}</span>
                                </li>
                            @empty
                                <li class="py-6 text-center text-[13px] text-acero">No hay buses detenidos en la estación en este momento.</li>
                            @endforelse
                        </ul>
                    </div>
                    <div>
                        <p class="mb-3 text-[15px] font-black tracking-tight">Vienen en camino</p>
                        <ul data-en-camino>
                            @forelse ($enCamino as $b)
                                <li class="flex items-center justify-between gap-3 border-b border-noche/[0.06] py-3">
                                    <span class="flex min-w-0 items-center gap-2">
                                        <span class="chip-bus" style="--c: {{ $b['color'] }}">{{ $b['placa'] }}</span>
                                        <span class="truncate text-[13px] text-pizarra">{{ $b['linea'] }} · desde {{ $nombres[$b['desde']] ?? '—' }}</span>
                                    </span>
                                    <span class="shrink-0 text-right text-[12px] tabular-nums text-acero">
                                        <b class="text-noche">{{ $b['minutos'] <= 0 ? 'Llegando' : 'En '.$b['minutos'].' min' }}</b><br>{{ $b['pasajeros'] }}/{{ $b['capacidad'] }} a bordo
                                    </span>
                                </li>
                            @empty
                                <li class="py-6 text-center text-[13px] text-acero">Ningún bus viene en camino hacia la estación.</li>
                            @endforelse
                        </ul>
                    </div>
                </div>

                <div class="mt-8 grid gap-6 lg:grid-cols-3">
                    {{-- Contingencia: tarjeta que no se lee o cobro caído en un bus detenido aquí --}}
                    <form method="POST" action="{{ route('operador.pases') }}" class="rounded-lg border-2 border-dashed border-niebla/60 p-5">
                        @csrf
                        <p class="text-[16px] font-black tracking-tight">Registrar pase manual</p>
                        <p class="mb-4 mt-1 text-[12px] leading-relaxed text-acero">Solo si el validador de un bus detenido aquí no lee la tarjeta o el cobro no responde. Queda en la bitácora.</p>
                        <div class="space-y-3">
                            <x-seleccion name="id_bus" label="Bus detenido en la estación" :opciones="$opcionesDetenidos" required vacio="Elige el bus…" />
                            <x-seleccion name="motivo" label="Motivo" :opciones="$motivos" required vacio="Elige el motivo…" />
                            <x-seleccion name="metodo_pago" label="Forma de pago" :opciones="$metodosPago" required />
                            <x-campo name="numero_tarjeta" label="Número impreso atrás de la Tarjeta Ciudadana" maxlength="30" placeholder="00 0040 0001" ayuda="O el número que da el código QR." />
                            @error('pase')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                            <button class="btn btn-primario w-full" @disabled($detenidos->isEmpty())>Registrar pase</button>
                        </div>
                    </form>

                    <form method="POST" action="{{ route('operador.bajadas') }}" class="rounded-lg border-2 border-dashed border-niebla/60 p-5">
                        @csrf
                        <p class="text-[16px] font-black tracking-tight">Registrar bajadas manual</p>
                        <p class="mb-4 mt-1 text-[12px] leading-relaxed text-acero">Solo si el molinete de salida de un bus detenido aquí no contó a las personas que bajaron.</p>
                        <div class="space-y-3">
                            <x-seleccion name="id_bus_bajada" label="Bus detenido en la estación" :opciones="$opcionesDetenidos" required vacio="Elige el bus…" />
                            <x-seleccion name="motivo_bajada" label="Motivo" :opciones="$motivosBajada" required vacio="Elige el motivo…" />
                            <x-campo name="cantidad" label="Personas que bajaron" type="number" min="1" value="1" required />
                            @error('bajadas')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                            <button class="btn btn-secundario w-full" @disabled($detenidos->isEmpty())>Registrar bajadas</button>
                        </div>
                    </form>

                    <form method="POST" action="{{ route('operador.fallas') }}" class="rounded-lg bg-papel p-5">
                        @csrf
                        <p class="text-[16px] font-black tracking-tight">Reportar falla de un bus</p>
                        <p class="mb-4 mt-1 text-[12px] leading-relaxed text-acero">El chofer avisa de una falla al pasar por la estación. Una falla de prioridad alta saca el bus de servicio; el taller la atiende desde la administración.</p>
                        <div class="space-y-3">
                            <x-seleccion name="id_bus_falla" label="Bus" :opciones="$busesFalla" required vacio="Elige el bus…" />
                            <x-seleccion name="categoria" label="Parte del bus" :opciones="MantenimientoBus::CATEGORIAS" required vacio="Elige…" />
                            <x-seleccion name="prioridad" label="Prioridad" :opciones="MantenimientoBus::PRIORIDADES" value="media" required />
                            <div>
                                <label for="descripcion" class="rotulo mb-2 block !text-[10.5px] text-pizarra">Descripción <span class="text-red-600">*</span></label>
                                <textarea id="descripcion" name="descripcion" rows="3" required maxlength="500"
                                          class="w-full rounded-lg border border-niebla/50 bg-white px-3.5 py-2.5 text-[15px] text-noche transition duration-300 hover:border-niebla focus:border-noche focus:ring-4 focus:ring-cielo/35 focus:outline-none">{{ old('descripcion') }}</textarea>
                                @error('descripcion')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                            </div>
                            <button class="btn btn-primario w-full">Reportar falla</button>
                        </div>
                    </form>
                </div>

                @if ($fallas->isNotEmpty())
                    <p class="mb-3 mt-8 text-[15px] font-black tracking-tight">Fallas que reportaste</p>
                    <ul>
                        @foreach ($fallas as $m)
                            <li class="flex flex-wrap items-center justify-between gap-2 border-b border-noche/[0.06] py-2.5 text-[13px]">
                                <span>Bus <b>{{ $m->bus?->placa }}</b> · {{ MantenimientoBus::CATEGORIAS[$m->categoria] ?? $m->categoria }} · {{ $m->fecha_reporte->format('d/m H:i') }}</span>
                                <span class="etiqueta ring-1 {{ $estadoMant[$m->estado] }}">{{ MantenimientoBus::ESTADOS[$m->estado] }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            {{-- ============ ACCESOS Y SEGURIDAD (puntos 3 y 10) ============ --}}
            <section data-seccion="accesos" hidden>
                <p class="mb-4 text-[12.5px] text-acero">Molinetes de ingreso de cada acceso de la estación y los agentes de seguridad asignados.</p>
                @error('estado_operativo')<p class="mb-3 text-sm text-red-600">{{ $message }}</p>@enderror
                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    @foreach ($estacion->accesos as $acceso)
                        <div class="rounded-lg border border-noche/[0.08] p-4" data-acceso>
                            <p class="flex items-baseline justify-between gap-2">
                                <span class="text-[15px] font-black">{{ $acceso->ubicacion_fisica }}</span>
                                <span class="text-[12px] {{ $acceso->molinete?->estado_operativo === 'activo' ? 'text-emerald-700' : 'font-bold text-amber-700' }}">
                                    Molinete {{ $acceso->molinete ? mb_strtolower(Molinete::ESTADOS[$acceso->molinete->estado_operativo] ?? $acceso->molinete->estado_operativo) : 'sin registrar' }}
                                </span>
                            </p>
                            @if ($acceso->molinete)
                                <form method="POST" action="{{ route('operador.accesos.estado', $acceso) }}" class="mt-3 flex items-center gap-2">
                                    @csrf @method('PATCH')
                                    <select name="estado_operativo" aria-label="Estado del molinete de {{ $acceso->ubicacion_fisica }}"
                                            class="flex-1 rounded-md border border-niebla/50 bg-white px-2 py-1.5 text-[13px]">
                                        @foreach ($estadosAcceso as $valor => $texto)
                                            <option value="{{ $valor }}" @selected($acceso->molinete->estado_operativo === $valor)>{{ $texto }}</option>
                                        @endforeach
                                    </select>
                                    <button class="btn btn-secundario btn-chico">Cambiar</button>
                                </form>
                            @endif
                            <p class="mt-3 text-[12.5px] {{ $acceso->guardias->isEmpty() ? 'font-bold text-red-600' : 'text-pizarra' }}">
                                <span class="text-acero">Seguridad:</span>
                                {{ $acceso->guardias->map(fn ($g) => $g->empleado->nombre_completo.($g->numero_placa_asignada ? ' ('.$g->numero_placa_asignada.')' : ''))->join(', ') ?: 'Sin guardia asignado' }}
                            </p>
                        </div>
                    @endforeach
                </div>

                @if ($estacion->parqueos->isNotEmpty())
                    <p class="mb-3 mt-8 text-[15px] font-black tracking-tight">Parqueos de la estación</p>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($estacion->parqueos->sortBy('codigo_bahia') as $p)
                            <span class="rounded-md bg-papel px-3 py-1.5 text-[12.5px]"><b>{{ $p->codigo_bahia }}</b> · {{ $p->bus?->placa ?? 'libre' }}</span>
                        @endforeach
                    </div>
                @endif
            </section>

            {{-- ============ PASAJEROS: cobros hechos en esta estación ============ --}}
            <section data-seccion="pasajeros" hidden>
                <p class="mb-3 text-[15px] font-black tracking-tight">Últimos cobros en la estación</p>
                <p class="mb-4 text-[12.5px] text-acero">Cada persona paga al subir, en el molinete de entrada del bus. Aquí se ven los que subieron en esta estación.</p>
                <div class="overflow-x-auto">
                    <table class="tabla">
                        <thead><tr><th>Hora</th><th>Bus</th><th>Forma de pago</th><th class="text-right">Cobro</th></tr></thead>
                        <tbody>
                            @forelse ($pases as $pase)
                                <tr>
                                    <td class="tabular-nums">{{ $pase->fecha_hora->format('d/m H:i:s') }}</td>
                                    <td>{{ $pase->bus_placa ?? '—' }}</td>
                                    <td>{{ $metodosPago[$pase->metodo_pago] ?? $pase->metodo_pago }}{{ $pase->tarjeta_numero ? ' · '.\App\Models\Tarjeta::formatearNumero($pase->tarjeta_numero) : '' }}</td>
                                    <td class="text-right">{{ $pase->viaje_gratis ? 'Gratis' : 'Q'.number_format($pase->monto, 2) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-center text-acero">Todavía no hay cobros en esta estación.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        (() => {
            const raiz = document.getElementById('tablero-operador');
            const secciones = [...raiz.querySelectorAll('[data-seccion]')].map((s) => s.dataset.seccion);
            const indicador = raiz.querySelector('.pestanas .indicador');

            function mostrar(nombre, animar = true) {
                if (!secciones.includes(nombre)) nombre = 'estacion';
                raiz.querySelectorAll('[data-seccion]').forEach((s) => (s.hidden = s.dataset.seccion !== nombre));
                raiz.querySelectorAll('[data-ir]').forEach((b) => {
                    const activo = b.dataset.ir === nombre;
                    b.setAttribute('aria-selected', String(activo));
                    if (activo) {
                        if (!animar) indicador.style.transition = 'none';
                        indicador.style.left = b.offsetLeft + 'px';
                        indicador.style.width = b.offsetWidth + 'px';
                        if (!animar) { void indicador.offsetWidth; indicador.style.transition = ''; }
                    }
                });
                history.replaceState(null, '', '#' + nombre);
            }

            raiz.addEventListener('click', (e) => { const b = e.target.closest('[data-ir]'); if (b) mostrar(b.dataset.ir); });
            document.querySelectorAll('[data-ir-a]').forEach((a) => a.addEventListener('click', (e) => { e.preventDefault(); mostrar(a.dataset.irA); raiz.scrollIntoView({behavior: 'smooth'}); }));
            window.addEventListener('hashchange', () => mostrar(location.hash.slice(1)));
            mostrar(@json($pestanaError) ?? location.hash.slice(1), false);

            // Se actualiza sola (sin perder la pestaña), salvo que el operador esté escribiendo
            // o que la pestaña del navegador no esté a la vista.
            setInterval(() => {
                const activo = document.activeElement;
                if (document.hidden || (activo && ['INPUT', 'TEXTAREA', 'SELECT'].includes(activo.tagName))) return;
                window.location.reload();
            }, {{ $simulacionActiva ? 20000 : 60000 }});
        })();
    </script>
@endpush
