@extends('layouts.app')

@section('titulo', 'Líneas')

@section('contenido')
    <x-titulo titulo="Líneas" subtitulo="Cada línea tiene entre n y 2n buses, donde n es su número de estaciones (punto 5).">
        <a href="{{ route('admin.lineas.create') }}" class="btn btn-primario">+ Nueva línea</a>
    </x-titulo>

    <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
        <table class="tabla">
            <thead>
                <tr><th>Línea</th><th>Municipio</th><th>Estado</th><th class="text-right">Tarifa</th><th class="text-right">Estaciones</th><th class="text-right">Distancia</th><th class="text-right">Buses</th><th>Permitido</th></tr>
            </thead>
            <tbody>
                @forelse ($lineas as $linea)
                    @php
                        $n = $linea->estaciones_count;
                        $ok = $n > 0 && $linea->buses_count >= $n && $linea->buses_count <= 2 * $n;
                    @endphp
                    <tr>
                        <td>
                            <a href="{{ route('admin.lineas.show', $linea) }}" class="enlace">{{ $linea->codigo_linea }}</a>
                            @if ($linea->nombre)<span class="block text-xs text-slate-500">{{ $linea->nombre }}</span>@endif
                        </td>
                        <td>{{ $linea->municipio->nombre }}</td>
                        <td>@include('admin.lineas._estado', ['estado' => $linea->estado])</td>
                        <td class="text-right">Q{{ number_format($linea->tarifa, 2) }}</td>
                        <td class="text-right">{{ $n }}</td>
                        <td class="text-right">{{ number_format($linea->distancia_total_km, 2) }} km</td>
                        <td class="text-right">{{ $linea->buses_count }}</td>
                        <td><span class="etiqueta {{ $ok ? 'bg-tm-100 text-tm-700' : 'bg-red-100 text-red-700' }}">{{ $n }}–{{ 2 * $n }} {{ $ok ? '✓' : '✗' }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center text-slate-400">No hay líneas registradas.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
