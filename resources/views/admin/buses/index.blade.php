@extends('layouts.app')

@section('titulo', 'Buses')

@section('contenido')
    <x-titulo titulo="Buses" subtitulo="Cada bus está en una sola línea (o en ninguna) y siempre tiene un parqueo.">
        <a href="{{ route('admin.buses.create') }}" class="btn btn-primario">+ Nuevo bus</a>
    </x-titulo>

    <form method="GET" class="mb-4 flex flex-wrap items-end gap-2">
        <div class="w-56">
            <x-seleccion name="linea" label="Filtrar por línea" :value="request('linea')" vacio="Todas"
                         :opciones="$lineas + ['ninguna' => 'Sin línea asignada']" onchange="this.form.submit()" />
        </div>
    </form>

    <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
        <table class="tabla">
            <thead>
                <tr><th>Placa</th><th>Línea</th><th>Estado</th><th class="text-right">Capacidad</th><th class="text-right">Ocupación</th><th>Parqueo</th><th>Piloto</th><th></th></tr>
            </thead>
            <tbody>
                @forelse ($buses as $bus)
                    <tr>
                        <td class="font-medium">{{ $bus->placa }}</td>
                        <td>{{ $bus->linea?->codigo_linea ?? '—' }}</td>
                        <td>{{ $estados[$bus->estado_logico] ?? $bus->estado_logico }}</td>
                        <td class="text-right">{{ $bus->capacidad_maxima }}</td>
                        <td class="text-right">
                            {{ number_format($bus->ocupacion_actual_pct, 0) }} %
                            @if ($bus->minutosEsperaExtra() > 0 && $bus->linea)
                                <span class="etiqueta bg-amber-100 text-amber-800">+5 min</span>
                            @endif
                        </td>
                        <td>{{ $bus->parqueo ? $bus->parqueo->estacion->nombre.' · '.$bus->parqueo->codigo_bahia : '—' }}</td>
                        <td>{{ $bus->piloto?->empleado->nombre_completo ?? '—' }}</td>
                        <td class="text-right"><a href="{{ route('admin.buses.edit', $bus) }}" class="btn btn-secundario btn-chico">Editar</a></td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center text-slate-400">No hay buses.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $buses->links() }}</div>
@endsection
