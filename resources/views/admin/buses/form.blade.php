@extends('layouts.app')

@section('titulo', $bus->exists ? 'Editar bus' : 'Nuevo bus')

@section('contenido')
    <x-titulo :titulo="$bus->exists ? 'Bus '.$bus->placa : 'Nuevo bus'" :volver="route('admin.buses.index')">
        @if ($bus->exists)
            <x-eliminar :accion="route('admin.buses.destroy', $bus)" texto="Eliminar bus" class="btn"
                        confirmar="¿Eliminar este bus? Su parqueo quedará libre." />
        @endif
    </x-titulo>

    <div class="grid gap-6 lg:grid-cols-3">
        <form method="POST" class="caja space-y-4 lg:col-span-2"
              action="{{ $bus->exists ? route('admin.buses.update', $bus) : route('admin.buses.store') }}">
            @csrf
            @if ($bus->exists) @method('PUT') @endif

            <div class="grid gap-4 md:grid-cols-2">
                <x-campo name="placa" label="Placa" :value="$bus->placa" required maxlength="15" />
                <x-campo name="capacidad_maxima" label="Capacidad máxima (pasajeros)" type="number" min="1" max="500" :value="$bus->capacidad_maxima" required />
                <x-seleccion name="id_linea_asignada" label="Línea asignada" :opciones="$lineas" :value="$bus->id_linea_asignada"
                             vacio="Sin línea" ayuda="Si la línea ya tiene el máximo (2 × estaciones), el sistema no lo permitirá." />
                <x-seleccion name="estado_logico" label="Estado" :opciones="$estados" :value="$bus->estado_logico" required />
                <x-campo name="ocupacion_actual_pct" label="Ocupación actual (%)" type="number" min="0" max="100" step="0.01"
                         :value="$bus->ocupacion_actual_pct" required ayuda="Con menos del 25 % espera 5 minutos más en cada estación." />
                @unless ($bus->exists)
                    <x-seleccion name="id_parqueo" label="Parqueo" :opciones="$parqueosLibres" required vacio="Selecciona un parqueo libre…"
                                 ayuda="Todo bus debe tener un parqueo (punto 6)." />
                @endunless
            </div>

            <button class="btn btn-primario">Guardar</button>
        </form>

        @if ($bus->exists)
            <div class="caja h-fit space-y-3">
                <h2 class="font-semibold text-tm-900">Parqueo (punto 7)</h2>
                <p class="text-sm">Actual: <b>{{ $bus->parqueo ? $bus->parqueo->estacion->nombre.' · '.$bus->parqueo->codigo_bahia : 'Sin parqueo' }}</b></p>
                @if ($parqueosLibres)
                    <form method="POST" action="{{ route('admin.buses.parqueo', $bus) }}" class="space-y-3">
                        @csrf
                        @method('PUT')
                        <x-seleccion name="id_parqueo_nuevo" label="Cambiar a" :opciones="$parqueosLibres" required vacio="Selecciona…" />
                        <button class="btn btn-secundario w-full">Cambiar parqueo</button>
                    </form>
                    <p class="text-xs text-slate-500">El cambio se hace en una sola operación: el bus nunca queda sin parqueo.</p>
                @else
                    <p class="text-sm text-slate-400">No hay parqueos libres. Agrega bahías desde Estaciones.</p>
                @endif
            </div>
        @endif
    </div>
@endsection
