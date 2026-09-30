@extends('layouts.app')

@section('titulo', 'Mapa de rutas')

@section('contenido')
    <x-titulo titulo="Mapa de rutas" subtitulo="Líneas del Transmetro con sus estaciones en orden. Haz clic en una estación para ver su dirección y líneas." />

    @include('partials.mapa', ['idMapa' => 'mapa-general', 'alto' => 'h-[70vh]'])

    <div class="mt-6 grid gap-4 md:grid-cols-3">
        @foreach ($lineas as $linea)
            <div class="caja">
                <div class="mb-2 flex items-center gap-2">
                    <span class="flex h-9 w-9 items-center justify-center rounded-md text-sm font-bold" style="background: {{ $linea->color }}; color: {{ $linea->colorTexto() }}">{{ $linea->codigo_linea }}</span>
                    <div>
                        <p class="font-semibold leading-tight text-tm-900">{{ $linea->nombre }}</p>
                        <p class="text-xs text-slate-500">
                            {{ $linea->estaciones->count() }} estaciones · {{ number_format($linea->distancia_total_km, 2) }} km · Q{{ number_format($linea->tarifa, 2) }}
                            {{ $linea->ruta_circular ? '· ruta circular' : '' }}
                        </p>
                    </div>
                </div>
                <p class="mb-2 text-xs">
                    <span class="etiqueta {{ $linea->enServicio() ? 'bg-tm-100 text-tm-700' : 'bg-slate-100 text-slate-600' }}">
                        {{ $linea->enServicio() ? 'En servicio ahora' : 'Fuera de horario' }}
                    </span>
                    @php $hoy = $linea->horarioDelDia(); @endphp
                    @if ($hoy)<span class="text-slate-500">Hoy: {{ $hoy->inicio() }} – {{ $hoy->fin() }}</span>@endif
                </p>
                <ol class="space-y-0.5 text-sm">
                    @foreach ($linea->estaciones as $estacion)
                        <li class="flex items-center gap-2">
                            <span class="h-2.5 w-2.5 shrink-0 rounded-full border-2 bg-white" style="border-color: {{ $linea->color }}"></span>
                            {{ $estacion->nombre }}
                        </li>
                    @endforeach
                </ol>
                @if (auth()->user()->esAdministrador())
                    <a href="{{ route('admin.lineas.show', $linea) }}" class="enlace mt-3 inline-block text-sm">Ver detalle de la línea →</a>
                @endif
            </div>
        @endforeach
    </div>
    <p class="mt-3 text-xs text-slate-500">Mapa: © colaboradores de OpenStreetMap. Las rutas punteadas todavía no tienen su trazo por las calles (el administrador lo dibuja en el detalle de la línea).</p>
@endsection
