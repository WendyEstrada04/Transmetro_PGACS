@extends('layouts.app')

@section('titulo', 'Estaciones')

@section('contenido')
    <x-titulo titulo="Estaciones" subtitulo="Red de estaciones con su aforo, accesos y molinetes de ingreso.">
        <a href="{{ route('admin.lineas.index') }}" class="btn btn-secundario">Líneas</a>
        <a href="{{ route('admin.municipios.index') }}" class="btn btn-secundario">Municipios</a>
    </x-titulo>

    <div class="mb-4 flex flex-wrap items-center gap-3">
        <input id="filtro-estaciones" type="search" class="w-full max-w-xs rounded-md border border-slate-300 bg-white px-3 py-2 text-sm focus:border-tm-500 focus:outline-none" placeholder="Buscar estación o municipio…" aria-label="Buscar estación">
        <p class="text-sm text-slate-500"><b id="cuenta-estaciones">{{ $estaciones->count() }}</b> de {{ $estaciones->count() }} estaciones</p>
    </div>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @foreach ($estaciones as $estacion)
            @php
                $pct = $estacion->aforo_maximo > 0 ? min(100, round($estacion->aforo_actual * 100 / $estacion->aforo_maximo)) : 0;
                $refuerzo = $estacion->requiereRefuerzo();
            @endphp
            <div class="caja" data-estacion="{{ mb_strtolower($estacion->nombre.' '.$estacion->municipio->nombre) }}">
                <a href="{{ route('admin.estaciones.show', $estacion) }}" class="enlace block truncate font-semibold leading-tight">{{ $estacion->nombre }}</a>
                <p class="text-xs text-slate-500">{{ $estacion->municipio->nombre }}</p>

                <p class="mt-3 flex flex-wrap gap-1">
                    @forelse ($estacion->lineas as $linea)
                        <span class="flex h-6 min-w-6 items-center justify-center rounded px-1.5 text-xs font-bold" style="background: {{ $linea->color }}; color: {{ $linea->colorTexto() }}" title="{{ $linea->nombre }}">{{ $linea->codigo_linea }}</span>
                    @empty
                        <span class="etiqueta bg-amber-100 text-amber-800">Sin líneas</span>
                    @endforelse
                </p>

                <div class="mt-3">
                    <div class="flex items-baseline justify-between text-xs">
                        <span class="text-slate-500">Aforo</span>
                        <span class="{{ $refuerzo ? 'font-semibold text-red-600' : 'text-slate-600' }}">{{ $estacion->aforo_actual }}/{{ $estacion->aforo_maximo }}{{ $refuerzo ? ' · requiere refuerzo' : '' }}</span>
                    </div>
                    <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-slate-100">
                        <div class="h-full rounded-full {{ $refuerzo ? 'bg-red-500' : 'bg-tm-500' }}" style="width: {{ $pct }}%"></div>
                    </div>
                </div>

                <p class="mt-3 text-xs text-slate-500">{{ $estacion->accesos_count }} accesos · {{ $estacion->parqueos_count }} parqueos</p>
            </div>
        @endforeach
    </div>
@endsection

@push('scripts')
    <script>
        (() => {
            const caja = document.getElementById('filtro-estaciones'), cuenta = document.getElementById('cuenta-estaciones');
            const tarjetas = [...document.querySelectorAll('[data-estacion]')];
            caja.addEventListener('input', () => {
                const q = caja.value.trim().toLowerCase();
                let n = 0;
                tarjetas.forEach((t) => { const ver = t.dataset.estacion.includes(q); t.hidden = !ver; if (ver) n++; });
                cuenta.textContent = n;
            });
        })();
    </script>
@endpush
