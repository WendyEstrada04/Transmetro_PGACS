@extends('layouts.app')

@section('titulo', 'Buses')

@section('contenido')
    @php
        $colorEstado = ['en_ruta' => '#162133', 'en_espera' => '#8eb9fc', 'en_mantenimiento' => '#f59e0b', 'fuera_de_servicio' => '#99acc9'];
        $totalFlota = array_sum($porEstado);
        $lineaActual = request('linea');
        $sinPilotoActivo = request('piloto') === 'sin';
        // Los filtros se combinan: al cambiar uno se conservan los demás.
        $filtro = fn (array $cambios) => route('admin.buses.index', array_filter(array_merge(request()->only(['linea', 'placa', 'piloto']), $cambios), fn ($v) => $v !== null && $v !== ''));
        // Letra legible sobre blanco para colores de línea claros (amarillo, gris…).
        $legible = fn ($color) => $color && (0.299 * hexdec(substr($color, 1, 2)) + 0.587 * hexdec(substr($color, 3, 2)) + 0.114 * hexdec(substr($color, 5, 2))) / 255 > 0.6
            ? "color-mix(in srgb, {$color} 55%, #162133)" : ($color ?: '#404d63');
    @endphp

    <x-titulo titulo="Flota de buses" subtitulo="Unidades de la flota con su ruta, ubicación y ocupación en tiempo real.">
        <a href="{{ route('admin.buses.create') }}" class="btn btn-primario">+ Nuevo bus</a>
    </x-titulo>

    {{-- Estado de la flota: una barra con las proporciones y las cifras --}}
    <section>
        <div class="flex h-3 overflow-hidden rounded-full bg-tm-100">
            @foreach ($estados as $clave => $texto)
                <div class="crecer-x h-full" style="width: {{ $totalFlota ? ($porEstado[$clave] ?? 0) * 100 / $totalFlota : 0 }}%; background: {{ $colorEstado[$clave] }}"></div>
            @endforeach
        </div>
        <div class="mt-5 grid grid-cols-2 gap-6 md:grid-cols-4">
            @foreach ($estados as $clave => $texto)
                <div class="border-l-[3px] pl-4" style="border-color: {{ $colorEstado[$clave] }}">
                    <p class="text-[38px] font-black leading-none tracking-[-0.05em]" data-contar="{{ $porEstado[$clave] ?? 0 }}">{{ $porEstado[$clave] ?? 0 }}</p>
                    <p class="rotulo mt-2 !text-[10px] text-acero">{{ $texto }}</p>
                </div>
            @endforeach
        </div>
    </section>

    {{-- Buscador por placa y apartado de unidades sin piloto --}}
    <form method="GET" action="{{ route('admin.buses.index') }}" class="mt-12 grid gap-6 md:grid-cols-[minmax(0,1fr)_auto] md:items-end">
        <div class="flex items-end gap-4 border-b-2 border-noche pb-2">
            <svg class="mb-2.5 h-6 w-6 shrink-0 text-acero" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <label class="flex-1">
                <span class="rotulo !text-[10px] text-acero">Placa</span>
                <input name="placa" value="{{ request('placa') }}" placeholder="Buscar placa…" autocomplete="off" data-buscar-placa
                       class="mt-1 w-full border-0 bg-transparent p-0 text-[22px] font-extrabold uppercase tracking-tight text-noche placeholder:normal-case placeholder:text-niebla focus:ring-0 focus:outline-none">
            </label>
            @foreach (request()->only(['linea', 'piloto']) as $clave => $valor)
                <input type="hidden" name="{{ $clave }}" value="{{ $valor }}">
            @endforeach
            <button class="btn btn-primario btn-chico mb-1.5">Buscar</button>
            @if (request('placa'))
                <a href="{{ $filtro(['placa' => null]) }}" class="mb-2.5 text-[12px] font-bold text-acero hover:text-noche">Limpiar</a>
            @endif
        </div>
        <a href="{{ $filtro(['piloto' => $sinPilotoActivo ? null : 'sin']) }}" data-filtro-sin-piloto
           class="flex items-center gap-2 self-end rounded-full px-4 py-2 text-[13px] font-black transition-all duration-300 hover:-translate-y-0.5 {{ $sinPilotoActivo ? 'bg-amber-500 text-white shadow-md' : 'bg-white text-amber-800 shadow-sm' }}">
            Sin piloto asignado
            <span class="rounded-full px-2 py-0.5 text-[11px] {{ $sinPilotoActivo ? 'bg-white/25' : 'bg-amber-100' }}">{{ $sinPiloto }}</span>
        </a>
    </form>

    @if ($sinPilotoActivo)
        <div class="mt-6 rounded-xl border border-amber-200 bg-amber-50 px-5 py-4 text-[13.5px] text-amber-900" data-aviso-sin-piloto>
            <b>Unidades sin piloto asignado.</b> No pueden ponerse en ruta ni salir como refuerzo hasta tener piloto.
            Para asignarlo: <a href="{{ route('admin.personal.index', ['rol' => 'piloto']) }}" class="font-bold underline">Personal</a> → piloto → <i>Bus que conduce</i>.
        </div>
    @endif

    {{-- Filtro por línea: los códigos en su color --}}
    <nav class="mt-8 flex flex-wrap items-center gap-2">
        <span class="rotulo mr-2 !text-[10px] text-acero">Línea</span>
        @foreach ([['', 'Todas', '#162133']] as [$valor, $texto, $color])
            <a href="{{ $filtro(['linea' => null]) }}"
               class="rounded-full px-4 py-1.5 text-[13px] font-black transition-all duration-300 {{ $lineaActual ? 'bg-white text-noche shadow-sm hover:-translate-y-0.5' : 'bg-noche text-white' }}">{{ $texto }}</a>
        @endforeach
        @foreach ($lineas as $linea)
            @php $activa = $lineaActual === $linea->id_linea; @endphp
            <a href="{{ $filtro(['linea' => $linea->id_linea]) }}"
               class="rounded-full px-4 py-1.5 text-[13px] font-black transition-all duration-300 hover:-translate-y-0.5 {{ $activa ? 'text-white shadow-md' : 'bg-white shadow-sm' }}"
               style="{{ $activa ? 'background: '.$linea->color.'; color: '.$linea->colorTexto() : 'color: '.$legible($linea->color) }}">{{ $linea->codigo_linea }}</a>
        @endforeach
        <a href="{{ $filtro(['linea' => 'ninguna']) }}"
           class="rounded-full px-4 py-1.5 text-[13px] font-bold transition-all duration-300 hover:-translate-y-0.5 {{ $lineaActual === 'ninguna' ? 'bg-noche text-white' : 'bg-white text-acero shadow-sm' }}">Sin línea</a>
    </nav>

    {{-- Flota: cada bus como una ficha con su línea, estado y ocupación --}}
    <div class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @forelse ($buses as $bus)
            @php
                $pct = (float) $bus->ocupacion_actual_pct;
                $arco = 2 * M_PI * 26;
                $colorLinea = $bus->color_linea ?? '#99acc9';
            @endphp
            <a href="{{ route('admin.buses.edit', $bus) }}"
               class="revelar group relative flex overflow-hidden rounded-xl bg-white shadow-sm transition-all duration-500 hover:-translate-y-1 hover:shadow-md"
               style="--retraso: {{ ($loop->index % 3) * 70 }}ms">
                <span class="w-2 shrink-0 transition-all duration-500 group-hover:w-3" style="background: {{ $colorLinea }}"></span>
                <div class="flex min-w-0 flex-1 items-center gap-4 p-5">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <span class="rounded-md px-2 py-0.5 text-[11px] font-black" style="background: color-mix(in srgb, {{ $colorLinea }} 18%, white); color: {{ $legible($bus->color_linea) }}">{{ $bus->codigo_linea ?? 'Sin línea' }}</span>
                            <span class="text-[11px] font-bold text-acero" data-tipo-unidad>{{ $bus->tipoUnidad() }}</span>
                            <span class="flex items-center gap-1.5 text-[11px] font-bold text-pizarra">
                                <span class="h-1.5 w-1.5 rounded-full" style="background: {{ $colorEstado[$bus->estado_logico] ?? '#99acc9' }}"></span>
                                {{ $estados[$bus->estado_logico] ?? $bus->estado_logico }}
                            </span>
                        </div>
                        <p class="mt-3 text-[26px] font-black leading-none tracking-[0.02em]">{{ $bus->placa }}</p>
                        <p class="mt-2 truncate text-[12.5px] font-semibold {{ $bus->piloto_nombre ? 'text-pizarra' : 'text-amber-700' }}">{{ $bus->piloto_nombre ?? 'Sin piloto asignado' }}</p>
                        {{-- Dónde va el bus en su ruta --}}
                        <div class="mt-2 text-[12px] leading-snug" data-ubicacion>
                            @if ($bus->estacion_actual)
                                <p class="truncate"><span class="text-acero">En</span> <b class="text-noche">{{ $bus->estacion_actual }}</b> <span class="tabular-nums text-acero">↑{{ $bus->subieron_actual }} ↓{{ $bus->bajaron_actual }}</span></p>
                                <p class="truncate"><span class="text-acero">Próxima</span> <b class="text-emerald-700">{{ $bus->estacion_proxima ?? 'fin del recorrido' }}</b></p>
                            @elseif ($bus->id_linea_asignada)
                                <p class="text-acero">Sin viaje en curso{{ $bus->codigo_bahia ? ' · en su parqueo' : '' }}</p>
                            @else
                                <p class="text-acero">Sin ruta asignada</p>
                            @endif
                        </div>
                        <p class="truncate text-[11.5px] text-acero">
                            {{ $bus->codigo_bahia ? 'Parqueo '.$bus->codigo_bahia.' · '.$bus->parqueo_estacion : 'Sin parqueo' }} · <b class="{{ $bus->pasajeros_a_bordo >= $bus->capacidad_maxima ? 'text-red-600' : 'text-pizarra' }}" data-a-bordo>{{ $bus->pasajeros_a_bordo }}/{{ $bus->capacidad_maxima }} a bordo{{ $bus->pasajeros_a_bordo >= $bus->capacidad_maxima ? ' · lleno' : '' }}</b>
                        </p>
                    </div>
                    {{-- Ocupación en anillo --}}
                    <div class="relative h-[68px] w-[68px] shrink-0">
                        <svg class="h-full w-full -rotate-90" viewBox="0 0 64 64">
                            <circle cx="32" cy="32" r="26" stroke="#e6edf9" stroke-width="7" fill="none"/>
                            <circle cx="32" cy="32" r="26" fill="none" stroke-width="7" stroke-linecap="round"
                                    stroke="{{ $pct >= 90 ? '#ef4444' : ($pct < 25 ? '#99acc9' : '#162133') }}"
                                    class="anillo" style="--arco: {{ $arco }}; stroke-dasharray: {{ $arco * min($pct, 100) / 100 }} {{ $arco }}"/>
                        </svg>
                        <span class="absolute inset-0 flex flex-col items-center justify-center leading-none">
                            <span class="text-[15px] font-black tabular-nums">{{ round($pct) }}</span>
                            <span class="text-[9px] font-bold text-acero">%</span>
                        </span>
                        @if ($pct < 25 && $bus->id_linea_asignada)
                            <span class="absolute -bottom-2 left-1/2 -translate-x-1/2 whitespace-nowrap rounded-full bg-amber-100 px-1.5 text-[9.5px] font-black text-amber-800">+5 min</span>
                        @endif
                    </div>
                </div>
            </a>
        @empty
            <p class="col-span-full py-16 text-center text-[15px] text-acero">{{ request('placa') ? 'No hay unidades con la placa «'.request('placa').'».' : ($sinPilotoActivo ? 'Todas las unidades tienen piloto asignado.' : 'No hay unidades con este filtro.') }}</p>
        @endforelse
    </div>
    <div class="mt-8">{{ $buses->links() }}</div>
@endsection
