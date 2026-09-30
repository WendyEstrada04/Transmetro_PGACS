@extends('layouts.app')

@section('titulo', 'Municipios')

@section('contenido')
    <x-titulo titulo="Municipios" subtitulo="Municipalidades a las que pertenecen las líneas y estaciones.">
        <a href="{{ route('admin.municipios.create') }}" class="btn btn-primario">+ Nuevo municipio</a>
    </x-titulo>

    <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
        <table class="tabla">
            <thead>
                <tr><th>Código</th><th>Nombre</th><th class="text-right">Líneas</th><th class="text-right">Estaciones</th><th>Estado</th><th></th></tr>
            </thead>
            <tbody>
                @forelse ($municipios as $municipio)
                    <tr>
                        <td>{{ $municipio->codigo_iso }}</td>
                        <td class="font-medium">{{ $municipio->nombre }}</td>
                        <td class="text-right">{{ $municipio->lineas_count }}</td>
                        <td class="text-right">{{ $municipio->estaciones_count }}</td>
                        <td>
                            <span class="etiqueta {{ $municipio->activo ? 'bg-tm-100 text-tm-700' : 'bg-slate-100 text-slate-500' }}">
                                {{ $municipio->activo ? 'Activo' : 'Inactivo' }}
                            </span>
                        </td>
                        <td class="text-right whitespace-nowrap">
                            <a href="{{ route('admin.municipios.edit', $municipio) }}" class="btn btn-secundario btn-chico">Editar</a>
                            @if ($municipio->lineas_count + $municipio->estaciones_count === 0)
                                <x-eliminar :accion="route('admin.municipios.destroy', $municipio)" />
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-slate-400">No hay municipios registrados.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
