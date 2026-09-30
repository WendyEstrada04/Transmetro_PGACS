@extends('layouts.app')

@section('titulo', 'Personal')

@section('contenido')
    <x-titulo titulo="Personal" subtitulo="Pilotos, guardias de seguridad y operadores de estación.">
        <a href="{{ route('admin.personal.create', ['rol' => request('rol')]) }}" class="btn btn-primario">+ Nuevo empleado</a>
    </x-titulo>

    <form method="GET" class="mb-4 flex flex-wrap items-end gap-2">
        <div class="w-44">
            <x-seleccion name="rol" label="Rol" :opciones="$roles" :value="request('rol')" vacio="Todos" onchange="this.form.submit()" />
        </div>
        <div class="w-64">
            <x-campo name="buscar" label="Buscar por nombre o DPI" :value="request('buscar')" />
        </div>
        <button class="btn btn-secundario">Buscar</button>
    </form>

    <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
        <table class="tabla">
            <thead>
                <tr><th>Nombre</th><th>DPI</th><th>Rol</th><th>Asignación</th><th>Estado</th><th></th></tr>
            </thead>
            <tbody>
                @forelse ($empleados as $empleado)
                    <tr>
                        <td class="font-medium">{{ $empleado->nombre_completo }}</td>
                        <td>{{ $empleado->dpi }}</td>
                        <td>{{ $roles[$empleado->rol] }}</td>
                        <td>
                            @switch($empleado->rol)
                                @case('piloto')
                                    Bus {{ $empleado->piloto?->bus?->placa ?? 'sin asignar' }}
                                    @break
                                @case('seguridad')
                                    {{ $empleado->seguridad?->acceso ? $empleado->seguridad->acceso->estacion->nombre.' · '.$empleado->seguridad->acceso->ubicacion_fisica : 'Sin acceso asignado' }}
                                    @break
                                @case('operador')
                                    {{ $empleado->operador?->estacion?->nombre ?? 'Sin estación' }}
                                    @if ($empleado->usuario)
                                        <span class="etiqueta bg-tm-100 text-tm-700">usuario: {{ $empleado->usuario->email }}</span>
                                    @endif
                                    @break
                            @endswitch
                        </td>
                        <td>
                            <span class="etiqueta {{ $empleado->activo ? 'bg-tm-100 text-tm-700' : 'bg-slate-100 text-slate-500' }}">
                                {{ $empleado->activo ? 'Activo' : 'Inactivo' }}
                            </span>
                        </td>
                        <td class="text-right whitespace-nowrap">
                            <a href="{{ route('admin.personal.edit', $empleado) }}" class="btn btn-secundario btn-chico">Editar</a>
                            <x-eliminar :accion="route('admin.personal.destroy', $empleado)" confirmar="¿Eliminar a este empleado?" />
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-slate-400">No se encontraron empleados.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $empleados->links() }}</div>
@endsection
