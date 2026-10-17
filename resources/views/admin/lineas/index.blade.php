@extends('layouts.app')

@section('titulo', 'Líneas')

@section('contenido')
    <x-titulo titulo="Líneas" subtitulo="Rutas de la red con su recorrido, flota asignada y estado de servicio.">
        <a href="{{ route('admin.estaciones.index') }}" class="btn btn-secundario">Estaciones</a>
        <a href="{{ route('admin.municipios.index') }}" class="btn btn-secundario">Municipios</a>
    </x-titulo>

    @if ($lineas->isEmpty())
        <p class="caja text-center text-slate-400">No hay líneas registradas.</p>
    @else
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($lineas as $linea)
                @php
                    $n = $linea->estaciones_count;
                    $ok = $n > 0 && $linea->buses_count >= $n && $linea->buses_count <= 2 * $n;
                    $hoy = $linea->horarioDelDia();
                @endphp
                <div class="caja flex flex-col" data-tarjeta="{{ $linea->codigo_linea }}">
                    <div class="mb-2 flex items-center gap-2">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md text-sm font-bold" style="background: {{ $linea->color }}; color: {{ $linea->colorTexto() }}">{{ $linea->codigo_linea }}</span>
                        <div class="min-w-0">
                            <a href="{{ route('admin.lineas.show', $linea) }}" class="enlace block truncate font-semibold leading-tight">{{ $linea->nombre }}</a>
                            <p class="text-xs text-slate-500">
                                {{ $n }} estaciones · {{ $linea->buses_count }} buses · {{ number_format($linea->distancia_total_km, 2) }} km
                                {{ $linea->ruta_circular ? '· ruta circular' : '' }}
                            </p>
                        </div>
                    </div>
                    <p class="mb-3 flex flex-wrap items-center gap-1.5 text-xs">
                        @include('admin.lineas._estado', ['estado' => $linea->estado])
                        <span class="etiqueta {{ $ok ? 'bg-tm-100 text-tm-700' : 'bg-red-100 text-red-700' }}" title="Flota permitida: entre {{ $n }} y {{ 2 * $n }} buses">Permitido {{ $n }}–{{ 2 * $n }} {{ $ok ? '✓' : '✗' }}</span>
                        <span class="text-slate-500">{{ $linea->municipio->nombre }} · Q{{ number_format($linea->tarifa, 2) }}</span>
                        @if ($hoy)<span class="text-slate-500">Hoy: {{ $hoy->inicio() }} – {{ $hoy->fin() }}</span>@endif
                    </p>
                    <ol class="text-sm">
                        @foreach ($linea->estaciones as $estacion)
                            <li class="relative pl-5">
                                <span class="absolute left-[4px] w-0.5 {{ $loop->first ? 'top-2.5' : 'top-0' }} {{ $loop->last && ! $linea->ruta_circular ? 'h-2.5' : 'bottom-0' }}" style="background: {{ $linea->color }}"></span>
                                <span class="absolute left-0 top-[5px] h-2.5 w-2.5 rounded-full border-2 bg-white" style="border-color: {{ $linea->color }}"></span>
                                <a href="{{ route('admin.estaciones.show', $estacion) }}" class="block py-0.5 hover:underline">{{ $estacion->nombre }}</a>
                            </li>
                        @endforeach
                    </ol>
                    <a href="{{ route('admin.lineas.show', $linea) }}" class="btn btn-secundario btn-chico mt-4 self-start">Ver detalle de la línea</a>
                </div>
            @endforeach
        </div>
    @endif
@endsection
