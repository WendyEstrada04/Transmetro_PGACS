@extends('layouts.app')

@section('titulo', 'Estaciones')

@section('contenido')
    <x-titulo titulo="Estaciones" subtitulo="Cada estación pertenece a un municipio y tiene al menos 2 accesos.">
        <a href="{{ route('admin.estaciones.create') }}" class="btn btn-primario">+ Nueva estación</a>
    </x-titulo>

    <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
        <table class="tabla">
            <thead>
                <tr><th>Estación</th><th>Municipio</th><th class="text-right">Líneas</th><th class="text-right">Accesos</th><th class="text-right">Parqueos</th><th class="text-right">Aforo</th><th></th></tr>
            </thead>
            <tbody>
                @foreach ($estaciones as $estacion)
                    <tr>
                        <td><a href="{{ route('admin.estaciones.show', $estacion) }}" class="enlace">{{ $estacion->nombre }}</a></td>
                        <td>{{ $estacion->municipio->nombre }}</td>
                        <td class="text-right {{ $estacion->lineas_count === 0 ? 'text-amber-700' : '' }}">{{ $estacion->lineas_count }}</td>
                        <td class="text-right">{{ $estacion->accesos_count }}</td>
                        <td class="text-right">{{ $estacion->parqueos_count }}</td>
                        <td class="text-right {{ $estacion->requiereRefuerzo() ? 'font-semibold text-red-600' : '' }}">
                            {{ $estacion->aforo_actual }}/{{ $estacion->aforo_maximo }}
                        </td>
                        <td class="text-right"><a href="{{ route('admin.estaciones.edit', $estacion) }}" class="btn btn-secundario btn-chico">Editar</a></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <p class="mt-2 text-xs text-slate-500">Las estaciones con 0 líneas aparecen en ámbar: según el enunciado toda estación debe pertenecer al menos a una línea.</p>
@endsection
