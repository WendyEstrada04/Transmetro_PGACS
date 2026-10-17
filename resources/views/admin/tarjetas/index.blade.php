@extends('layouts.app')

@section('titulo', 'Tarjetas')

@section('contenido')
    @php
        // Cada tipo de tarjeta con su diseño (fondo y color de letra).
        $diseno = [
            'vecino' => ['linear-gradient(115deg, #6b5fd3 0%, #3a2f9e 38%, #1f1a6b 100%)', '#ffffff', '#3a2f9e'],
            'adulto_mayor' => ['linear-gradient(115deg, #fff27a 0%, #ffd23f 40%, #ffb627 100%)', '#2a2366', '#ffd23f'],
            'discapacidad' => ['linear-gradient(115deg, #8fd0ff 0%, #4a97e0 40%, #2a68b0 100%)', '#ffffff', '#4a97e0'],
        ];
        $tipoActual = request('tipo');
        // Datos de cada tarjeta para la vista previa (se muestran al elegirla en la lista).
        $datos = $tarjetas->getCollection()->map(fn ($t) => [
            'numero' => $t->numeroFormateado(),
            'tipo' => $tipos[$t->tipo_tarjeta] ?? $t->tipo_tarjeta,
            'titulo' => ['vecino' => 'VECINO', 'adulto_mayor' => 'ADULTO MAYOR', 'discapacidad' => 'PERSONA CON DISCAPACIDAD'][$t->tipo_tarjeta] ?? '',
            'titular' => $t->nombre_titular ?: 'Al portador',
            'saldo' => 'Q'.number_format($t->saldo, 2),
            'gratis' => $t->tipo_tarjeta === 'adulto_mayor' ? '4/día' : $t->viajes_gratis_restantes,
            'viajes' => $t->transacciones_count,
            'emitida' => $t->fecha_emision?->format('d/m/Y') ?? '—',
            'estado' => $t->estado,
            'estadoTexto' => $t->estadoTexto(),
            'fondo' => ($diseno[$t->tipo_tarjeta] ?? $diseno['vecino'])[0],
            'tinta' => ($diseno[$t->tipo_tarjeta] ?? $diseno['vecino'])[1],
            'url' => route('admin.tarjetas.show', $t),
        ])->values();
    @endphp

    <x-titulo titulo="Tarjeta Ciudadana" subtitulo="Consulta y control de estado de las tarjetas Vecino, Adulto mayor y Persona con discapacidad. La emisión y las recargas se realizan en los puntos de atención y de recarga." />

    {{-- Búsqueda y tipo --}}
    <form method="GET" class="grid gap-6 md:grid-cols-[minmax(0,1fr)_auto] md:items-end">
        <div class="flex items-end gap-4 border-b-2 border-noche pb-2">
            <svg class="mb-2.5 h-6 w-6 shrink-0 text-acero" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <label class="flex-1">
                <span class="rotulo !text-[10px] text-acero">Número o titular</span>
                <input name="buscar" value="{{ request('buscar') }}" placeholder="Buscar tarjeta…"
                       class="mt-1 w-full border-0 bg-transparent p-0 text-[22px] font-extrabold tracking-tight text-noche placeholder:text-niebla focus:ring-0 focus:outline-none">
            </label>
            @if ($tipoActual)<input type="hidden" name="tipo" value="{{ $tipoActual }}">@endif
            @if ($estadoActual)<input type="hidden" name="estado" value="{{ $estadoActual }}">@endif
            <button class="btn btn-primario btn-chico mb-1.5">Buscar</button>
        </div>
        <div class="flex flex-wrap gap-2">
            @foreach (['' => 'Todas'] + $tipos as $clave => $texto)
                <a href="{{ route('admin.tarjetas.index', array_filter(['tipo' => $clave, 'estado' => $estadoActual, 'buscar' => request('buscar')])) }}"
                   class="rounded-full px-4 py-1.5 text-[12.5px] font-extrabold transition-all duration-300 hover:-translate-y-0.5 {{ (string) $tipoActual === (string) $clave ? 'bg-noche text-white' : 'bg-white text-pizarra shadow-sm' }}">{{ $texto }}</a>
            @endforeach
        </div>
    </form>

    {{-- Estado: activas, bloqueadas, dadas de baja… con cuántas hay de cada una --}}
    <nav class="mt-6 flex flex-wrap items-center gap-2" aria-label="Estado de la tarjeta" data-filtro-estado>
        <span class="rotulo mr-2 !text-[10px] text-acero">Estado</span>
        @foreach (['' => 'Todas', 'activa' => 'Activas', 'bloqueada' => 'Bloqueadas', 'baja' => 'Dadas de baja', 'vencida' => 'Vencidas', 'reemplazada' => 'Reemplazadas'] as $clave => $texto)
            @php $n = $clave === '' ? $conteo->sum() : (int) ($conteo[$clave] ?? 0); @endphp
            @continue($clave !== '' && $n === 0 && ! in_array($clave, ['activa', 'bloqueada', 'baja'], true))
            <a href="{{ route('admin.tarjetas.index', array_filter(['estado' => $clave, 'tipo' => $tipoActual, 'buscar' => request('buscar')])) }}"
               @if ((string) $estadoActual === (string) $clave) aria-current="true" @endif
               class="flex items-center gap-2 rounded-full px-4 py-1.5 text-[12.5px] font-extrabold transition-all duration-300 hover:-translate-y-0.5 {{ (string) $estadoActual === (string) $clave ? 'bg-noche text-white' : 'bg-white text-pizarra shadow-sm' }}">
                {{ $texto }}
                <span class="rounded-full px-1.5 text-[11px] {{ (string) $estadoActual === (string) $clave ? 'bg-white/20' : ($clave === 'bloqueada' && $n > 0 ? 'bg-red-100 text-red-700' : 'bg-noche/[0.06]') }}">{{ $n }}</span>
            </a>
        @endforeach
    </nav>

    <div class="mt-10 grid items-start gap-8 lg:grid-cols-[minmax(0,1.25fr)_minmax(0,1fr)]">
        {{-- Lista de tarjetas --}}
        <div class="overflow-hidden rounded-xl bg-white shadow-sm">
            <div class="grid grid-cols-[minmax(0,1fr)_auto_auto] gap-4 border-b border-noche/10 px-5 py-3">
                <span class="rotulo !text-[10px] text-acero">Tarjeta</span>
                <span class="rotulo w-24 text-right !text-[10px] text-acero">Saldo</span>
                <span class="rotulo w-20 text-right !text-[10px] text-acero">Estado</span>
            </div>
            <ul role="listbox" aria-label="Tarjetas" id="lista-tarjetas">
                @forelse ($tarjetas as $tarjeta)
                    <li>
                        <button type="button" role="option" data-indice="{{ $loop->index }}" aria-selected="{{ $loop->first ? 'true' : 'false' }}"
                                class="fila-tarjeta grid w-full grid-cols-[minmax(0,1fr)_auto_auto] items-center gap-4 border-b border-noche/[0.06] px-5 py-3.5 text-left">
                            <span class="flex min-w-0 items-center gap-3">
                                <span class="h-7 w-11 shrink-0 rounded-md shadow-sm" style="background: {{ ($diseno[$tarjeta->tipo_tarjeta] ?? $diseno['vecino'])[0] }}"></span>
                                <span class="min-w-0">
                                    <span class="block text-[14.5px] font-black tracking-[0.06em] tabular-nums">{{ $tarjeta->numeroFormateado() }}</span>
                                    <span class="block truncate text-[12px] text-acero">{{ $tipos[$tarjeta->tipo_tarjeta] ?? $tarjeta->tipo_tarjeta }} · {{ $tarjeta->nombre_titular ?: 'Al portador' }}</span>
                                </span>
                            </span>
                            <span class="w-24 text-right text-[15px] font-black tabular-nums">Q{{ number_format($tarjeta->saldo, 2) }}</span>
                            <span class="w-20 text-right">@include('admin.tarjetas._estado', ['estado' => $tarjeta->estado])</span>
                        </button>
                    </li>
                @empty
                    <li class="px-5 py-16 text-center text-[15px] text-acero">No hay tarjetas con este filtro.</li>
                @endforelse
            </ul>
        </div>

        {{-- Vista previa de la tarjeta elegida --}}
        @if ($datos->isNotEmpty())
            <aside class="lg:sticky lg:top-24" aria-live="polite">
                <p class="rotulo mb-4 !text-[10px] text-acero">Tarjeta seleccionada</p>
                {{-- El frente de la Tarjeta Ciudadana, igual que en la ficha --}}
                <div class="tarjeta-escena">
                    <div id="vista-tarjeta" class="tarjeta-fisica tarjeta-entra relative overflow-hidden p-6">
                        <div class="pointer-events-none absolute -right-16 top-0 h-full w-1/2 skew-x-[-18deg] bg-white/10"></div>
                        <div class="relative flex h-full flex-col items-center text-center">
                            <p class="mt-1 text-[19px] font-black italic tracking-wide" data-campo="titulo"></p>
                            <div class="my-auto flex items-center gap-5">
                                <span class="block h-11 w-14 rounded-lg bg-gradient-to-br from-amber-200 to-amber-400 shadow-inner"></span>
                                <svg class="h-11 w-11 opacity-80" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M8 8a6 6 0 0 1 0 8M12 5a10 10 0 0 1 0 14M16 2.5a14 14 0 0 1 0 19"/></svg>
                            </div>
                            <p class="text-[16px] font-black leading-tight tracking-wide">TARJETA CIUDADANA</p>
                            <p class="text-[14px] font-semibold italic leading-tight tracking-wider opacity-90">CIUDAD INTEGRADA</p>
                        </div>
                        <span data-campo="sello" hidden
                              class="absolute right-5 top-1/2 -translate-y-1/2 -rotate-12 rounded-md border-2 border-red-500 bg-white/85 px-2.5 py-0.5 text-[13px] font-black uppercase tracking-[0.2em] text-red-600"></span>
                    </div>
                </div>

                {{-- Número, titular y saldo de la tarjeta elegida --}}
                <div class="mt-5 flex items-end justify-between gap-4">
                    <div class="min-w-0">
                        <p class="font-mono text-[20px] font-bold tracking-wider tabular-nums" data-campo="numero"></p>
                        <p class="truncate text-[13px] text-acero"><span data-campo="tipo"></span> · <span data-campo="titular"></span></p>
                    </div>
                    <p class="shrink-0 text-[28px] font-black leading-none tracking-[-0.03em]" data-campo="saldo"></p>
                </div>

                <dl class="mt-6 grid grid-cols-3 border-y border-noche/[0.08] text-center">
                    <div class="py-4"><dt class="rotulo !text-[9.5px] text-acero">Viajes</dt><dd class="mt-1 text-[24px] font-black" data-campo="viajes"></dd></div>
                    <div class="border-x border-noche/[0.08] py-4"><dt class="rotulo !text-[9.5px] text-acero">Gratis</dt><dd class="mt-1 text-[24px] font-black" data-campo="gratis"></dd></div>
                    <div class="py-4"><dt class="rotulo !text-[9.5px] text-acero">Emitida</dt><dd class="mt-1.5 text-[15px] font-black" data-campo="emitida"></dd></div>
                </dl>
                <a href="#" data-campo="url" class="btn btn-primario mt-6 w-full">Ver ficha y control de estado →</a>
            </aside>
        @endif
    </div>
    <div class="mt-8">{{ $tarjetas->links() }}</div>
@endsection

@push('scripts')
    <script>
        (() => {
            const tarjetas = @json($datos);
            const vista = document.getElementById('vista-tarjeta');
            const lista = document.getElementById('lista-tarjetas');
            if (!vista || !tarjetas.length) return;
            const campo = (n) => document.querySelector(`[data-campo="${n}"]`);

            function mostrar(i, animar = true) {
                const t = tarjetas[i];
                vista.style.background = t.fondo;
                vista.style.color = t.tinta;
                vista.classList.toggle('grayscale', t.estado !== 'activa');
                ['titulo', 'numero', 'tipo', 'titular', 'saldo', 'viajes', 'gratis', 'emitida'].forEach((n) => (campo(n).textContent = t[n]));
                const sello = campo('sello');
                sello.hidden = t.estado === 'activa';
                sello.textContent = t.estadoTexto;
                campo('url').href = t.url;
                lista.querySelectorAll('[data-indice]').forEach((b) => b.setAttribute('aria-selected', String(Number(b.dataset.indice) === i)));
                if (animar) { vista.classList.remove('tarjeta-entra'); void vista.offsetWidth; vista.classList.add('tarjeta-entra'); }
            }

            lista.addEventListener('click', (e) => {
                const b = e.target.closest('[data-indice]');
                if (b) mostrar(Number(b.dataset.indice));
            });
            // Doble clic: abre la ficha de la tarjeta.
            lista.addEventListener('dblclick', (e) => {
                const b = e.target.closest('[data-indice]');
                if (b) location.href = tarjetas[Number(b.dataset.indice)].url;
            });
            mostrar(0, false);
        })();
    </script>
@endpush
