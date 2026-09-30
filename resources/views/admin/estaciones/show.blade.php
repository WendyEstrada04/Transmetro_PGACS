@extends('layouts.app')

@section('titulo', $estacion->nombre)

@section('contenido')
    <x-titulo :titulo="$estacion->nombre" :volver="route('admin.estaciones.index')"
              :subtitulo="$estacion->municipio->nombre.' · Líneas: '.($estacion->lineas->pluck('codigo_linea')->join(', ') ?: 'ninguna')">
        <a href="{{ route('admin.estaciones.edit', $estacion) }}" class="btn btn-secundario">Editar datos</a>
    </x-titulo>

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
            <p class="text-xs text-slate-500">Operador(es) de la PC de trabajo</p>
            <p class="font-medium">{{ $estacion->operadores->map(fn ($o) => $o->empleado->nombre_completo)->join(', ') ?: 'Sin operador asignado' }}</p>
        </div>
    </div>

    {{-- Accesos --}}
    <section class="mb-8">
        <h2 class="mb-3 text-lg font-semibold text-tm-900">Accesos, molinetes y seguridad</h2>
        <div class="space-y-3">
            @foreach ($estacion->accesos->sortBy('ubicacion_fisica') as $acceso)
                <div class="caja">
                    <form method="POST" action="{{ route('admin.estaciones.accesos.update', [$estacion, $acceso]) }}" class="grid items-end gap-3 md:grid-cols-5">
                        @csrf
                        @method('PUT')
                        <div class="md:col-span-2">
                            <label class="mb-1 block text-xs text-slate-500">Ubicación</label>
                            <input name="ubicacion_fisica" value="{{ $acceso->ubicacion_fisica }}" required maxlength="100" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm">
                        </div>
                        <div>
                            <label class="mb-1 block text-xs text-slate-500">IP del molinete</label>
                            <input name="ip_address" value="{{ $acceso->molinete?->ip_address }}" required class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm">
                        </div>
                        <div>
                            <label class="mb-1 block text-xs text-slate-500">Estado del molinete</label>
                            <select name="estado_operativo" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm">
                                @foreach (['activo', 'inactivo', 'mantenimiento'] as $estadoMolinete)
                                    <option value="{{ $estadoMolinete }}" @selected($acceso->molinete?->estado_operativo === $estadoMolinete)>{{ ucfirst($estadoMolinete) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <button class="btn btn-secundario btn-chico">Guardar</button>
                    </form>
                    <div class="mt-3 flex flex-wrap items-center justify-between gap-2 text-sm">
                        <p class="{{ $acceso->guardias->isEmpty() ? 'font-medium text-red-600' : 'text-slate-600' }}">
                            Seguridad: {{ $acceso->guardias->map(fn ($g) => $g->empleado->nombre_completo)->join(', ') ?: 'SIN GUARDIA — asígnalo en Personal (punto 10)' }}
                        </p>
                        <x-eliminar :accion="route('admin.estaciones.accesos.destroy', [$estacion, $acceso])" texto="Quitar acceso"
                                    confirmar="¿Quitar este acceso y su molinete? La estación debe conservar al menos 2 accesos." />
                    </div>
                </div>
            @endforeach
        </div>

        <form method="POST" action="{{ route('admin.estaciones.accesos.store', $estacion) }}" class="caja mt-3 grid items-end gap-3 md:grid-cols-4">
            @csrf
            <div class="md:col-span-2"><x-campo name="ubicacion_fisica" label="Nuevo acceso — ubicación" required maxlength="100" /></div>
            <x-campo name="ip_address" label="IP del molinete" required />
            <button class="btn btn-primario">+ Agregar acceso</button>
        </form>
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
                            @unless ($parqueo->bus)
                                <x-eliminar :accion="route('admin.estaciones.parqueos.destroy', [$estacion, $parqueo])" texto="×" confirmar="¿Eliminar esta bahía de parqueo?" />
                            @endunless
                        </div>
                    @endforeach
                </div>
            @endif

            <form method="POST" action="{{ route('admin.estaciones.parqueos.store', $estacion) }}" class="mt-4 flex flex-wrap items-end gap-3">
                @csrf
                <div class="w-48"><x-campo name="codigo_bahia" label="Nueva bahía" required maxlength="20" placeholder="B-07" /></div>
                <button class="btn btn-primario">+ Agregar parqueo</button>
            </form>
        </div>
    </section>
@endsection
