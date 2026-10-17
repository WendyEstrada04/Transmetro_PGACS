@extends('layouts.app')

@section('titulo', $estacion->nombre)

@section('contenido')
    <x-titulo :titulo="$estacion->nombre" :volver="route('admin.estaciones.index')"
              :subtitulo="$estacion->municipio->nombre.' · Líneas: '.($estacion->lineas->pluck('codigo_linea')->join(', ') ?: 'ninguna')" />

    <div class="mb-6 grid gap-4 md:grid-cols-3">
        <div class="caja">
            <p class="text-xs text-slate-500">Aforo</p>
            <p class="text-2xl font-semibold {{ $estacion->requiereRefuerzo() ? 'text-red-600' : 'text-tm-900' }}">
                {{ $estacion->aforo_actual }} / {{ $estacion->aforo_maximo }}
            </p>
            <p class="text-xs text-slate-500">{{ $estacion->porcentajeAforo() }} %</p>
        </div>
        <div class="caja">
            <p class="text-xs text-slate-500">Ubicación</p>
            <p class="font-medium">{{ $estacion->direccion ?? 'Sin dirección registrada' }}</p>
            <p class="text-xs text-slate-500">{{ $estacion->latitud }}, {{ $estacion->longitud }}</p>
        </div>
        <div class="caja">
            <p class="text-xs text-slate-500">Seguridad</p>
            @php $nGuardias = $estacion->accesos->sum(fn ($a) => $a->guardias->count()); @endphp
            <p class="text-2xl font-semibold text-tm-900">{{ $nGuardias }} {{ $nGuardias === 1 ? 'guardia' : 'guardias' }}</p>
            <p class="text-xs text-slate-500">en {{ $estacion->accesos->count() }} accesos</p>
        </div>
    </div>

    {{-- Accesos --}}
    <section class="mb-8">
        <h2 class="mb-3 text-lg font-semibold text-tm-900">Accesos, molinetes y seguridad</h2>
        <div class="space-y-3">
            @foreach ($estacion->accesos->sortBy('ubicacion_fisica') as $acceso)
                <div class="caja">
                    <form method="POST" action="{{ route('admin.estaciones.accesos.estado', [$estacion, $acceso]) }}" class="flex flex-wrap items-end gap-4">
                        @csrf
                        @method('PATCH')
                        <div class="min-w-48 flex-1">
                            <p class="text-xs text-slate-500">Acceso</p>
                            <p class="font-semibold">{{ $acceso->ubicacion_fisica }}</p>
                            <p class="text-xs text-slate-500">Molinete de ingreso · {{ $acceso->molinete?->ip_address ?? 'sin registrar' }}</p>
                        </div>
                        <label>
                            <span class="mb-1 block text-xs text-slate-500">Estado del molinete</span>
                            <select name="estado_operativo" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm">
                                @foreach ($estados as $valor => $texto)
                                    <option value="{{ $valor }}" @selected($acceso->molinete?->estado_operativo === $valor)>{{ $texto }}</option>
                                @endforeach
                            </select>
                        </label>
                        <button class="btn btn-secundario btn-chico">Cambiar estado</button>
                    </form>
                    <div class="mt-3 flex flex-wrap items-center justify-between gap-2 text-sm">
                        <p class="{{ $acceso->guardias->isEmpty() ? 'font-medium text-red-600' : 'text-slate-600' }}">
                            Seguridad: {{ $acceso->guardias->map(fn ($g) => $g->empleado->nombre_completo)->join(', ') ?: 'Sin agente asignado' }}
                        </p>
                    </div>
                </div>
            @endforeach
        </div>

    </section>

    {{-- Parqueos --}}
    <section>
        <h2 class="mb-3 text-lg font-semibold text-tm-900">Parqueos</h2>
        <div class="caja">
            @if ($estacion->parqueos->isEmpty())
                <p class="text-sm text-slate-400">Esta estación no tiene parqueos.</p>
            @else
                <div class="grid gap-2 sm:grid-cols-2 md:grid-cols-4">
                    @foreach ($estacion->parqueos->sortBy('codigo_bahia') as $parqueo)
                        <div class="flex items-center justify-between rounded-md border border-slate-200 px-3 py-2 text-sm">
                            <span><b>{{ $parqueo->codigo_bahia }}</b> · {{ $parqueo->bus?->placa ?? 'Libre' }}</span>
                        </div>
                    @endforeach
                </div>
            @endif

        </div>
    </section>
@endsection
