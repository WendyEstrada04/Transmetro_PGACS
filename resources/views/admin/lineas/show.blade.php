@extends('layouts.app')

@section('titulo', 'Línea '.$linea->codigo_linea)

@section('contenido')
    @php
        $n = $linea->estaciones->count();
        $numBuses = $linea->buses->count();
        $ok = $n > 0 && $numBuses >= $n && $numBuses <= 2 * $n;
    @endphp

    <x-titulo :titulo="'Línea '.$linea->codigo_linea.($linea->nombre ? ' · '.$linea->nombre : '')"
              :subtitulo="$linea->municipio->nombre.' · '.(\App\Models\Linea::TIPOS_SERVICIO[$linea->tipo_servicio] ?? $linea->tipo_servicio).' · Tarifa Q'.number_format($linea->tarifa, 2)"
              :volver="route('admin.lineas.index')">
    </x-titulo>

    {{-- Lo único que cambia la operación diaria: el estado de la línea --}}
    <form method="POST" action="{{ route('admin.lineas.estado', $linea) }}" id="estado" class="caja mb-6 scroll-mt-24 flex flex-wrap items-end gap-4">
        @csrf
        @method('PATCH')
        <label class="min-w-56">
            <span class="mb-1 block text-sm font-medium">Estado de la línea</span>
            <select name="estado" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                @foreach ($estados as $valor => $texto)
                    <option value="{{ $valor }}" @selected($linea->estado === $valor)>{{ $texto }}</option>
                @endforeach
            </select>
        </label>
        <button class="btn btn-primario">Cambiar estado</button>
        <p class="flex-1 text-xs text-slate-500">Para operar, la línea requiere entre {{ $n }} y {{ 2 * $n }} unidades asignadas.</p>
        @error('estado')<p class="w-full text-sm text-red-600">{{ $message }}</p>@enderror
    </form>

    <div class="mb-6 grid gap-4 md:grid-cols-4">
        <div class="caja"><p class="text-xs text-slate-500">Estado</p><p class="mt-1">@include('admin.lineas._estado', ['estado' => $linea->estado])</p></div>
        <div class="caja"><p class="text-xs text-slate-500">Estaciones</p><p class="text-2xl font-semibold text-tm-900">{{ $n }}</p></div>
        <div class="caja"><p class="text-xs text-slate-500">Distancia total</p><p class="text-2xl font-semibold text-tm-900">{{ number_format($linea->distancia_total_km, 2) }} km</p></div>
        <div class="caja {{ $ok ? '' : 'ring-2 ring-red-300' }}">
            <p class="text-xs text-slate-500">Flota (autorizada {{ $n }}–{{ 2 * $n }})</p>
            <p class="text-2xl font-semibold {{ $ok ? 'text-tm-900' : 'text-red-600' }}">{{ $numBuses }}</p>
        </div>
    </div>

    {{-- Mapa de la ruta --}}
    <section class="mb-6">
        <div class="mb-3 flex flex-wrap items-center gap-3">
            <h2 class="text-lg font-semibold text-tm-900">Ruta en el mapa</h2>
            @if ($linea->tieneTrazo())
                <span class="etiqueta bg-tm-100 text-tm-700">Trazada por las calles</span>
            @else
                <span class="etiqueta bg-amber-100 text-amber-800">Sin trazar (se ve punteada)</span>
            @endif
        </div>
        @include('partials.mapa', ['lineasMapa' => \App\Http\Controllers\MapaController::datos(collect([$linea])), 'idMapa' => 'mapa-linea', 'alto' => 'h-96'])
    </section>

    {{-- Horario de servicio --}}
    <section class="mb-6">
        <div class="mb-3 flex flex-wrap items-center gap-3">
            <h2 class="text-lg font-semibold text-tm-900">Horario de servicio</h2>
            @if ($linea->enServicio())
                <span class="etiqueta bg-tm-100 text-tm-700">En servicio ahora</span>
            @else
                <span class="etiqueta bg-slate-100 text-slate-600">Fuera de servicio ahora</span>
            @endif
        </div>
        <div class="caja">
            <dl class="grid gap-4 md:grid-cols-3">
                @foreach (\App\Models\HorarioLinea::TIPOS_DIA as $tipo => $etiquetaDia)
                    @php $horario = $linea->horarios->firstWhere('tipo_dia', $tipo); @endphp
                    <div>
                        <dt class="text-sm font-medium">{{ $etiquetaDia }}</dt>
                        <dd class="mt-1 text-xl font-semibold tabular-nums text-tm-900">
                            {{ $horario?->inicio() ?? \App\Models\HorarioLinea::HORARIO_OFICIAL[$tipo][0] }} – {{ $horario?->fin() ?? \App\Models\HorarioLinea::HORARIO_OFICIAL[$tipo][1] }}
                        </dd>
                    </div>
                @endforeach
            </dl>
            <p class="mt-4 text-xs text-slate-500">Horario de servicio por tipo de día.</p>
        </div>
    </section>

    <div class="grid gap-6 lg:grid-cols-2">
        {{-- Recorrido --}}
        <section>
            <h2 class="mb-3 text-lg font-semibold text-tm-900">Recorrido (estaciones en orden)</h2>
            <div class="caja">
                @forelse ($linea->estaciones as $estacion)
                    <div class="flex items-center gap-3 border-b border-slate-100 py-2 last:border-0">
                        <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-semibold" style="background: {{ $linea->color }}; color: {{ $linea->colorTexto() }}">{{ $estacion->pivot->orden_parada }}</span>
                        <div class="flex-1 text-sm">
                            <a href="{{ route('admin.estaciones.show', $estacion) }}" class="enlace">{{ $estacion->nombre }}</a>
                            <span class="text-slate-400">· {{ $estacion->municipio->nombre }}</span>
                            <p class="text-xs text-slate-500">
                                {{ $estacion->pivot->orden_parada == 1 ? 'Inicio del recorrido' : '+'.number_format($estacion->pivot->distancia_previa_km, 2).' km desde la anterior' }}
                            </p>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-slate-400">La línea todavía no tiene estaciones.</p>
                @endforelse

            </div>
        </section>

        {{-- Buses --}}
        <section>
            <h2 class="mb-3 text-lg font-semibold text-tm-900">Buses asignados</h2>
            <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
                <table class="tabla">
                    <thead><tr><th>Placa</th><th>Piloto</th><th>Parqueo</th><th class="text-right">Ocupación</th></tr></thead>
                    <tbody>
                        @forelse ($linea->buses->sortBy('placa') as $bus)
                            <tr>
                                <td><a href="{{ route('admin.buses.edit', $bus) }}" class="enlace">{{ $bus->placa }}</a></td>
                                <td>{{ $bus->piloto?->empleado->nombre_completo ?? '—' }}</td>
                                <td>{{ $bus->parqueo ? $bus->parqueo->estacion->nombre.' · '.$bus->parqueo->codigo_bahia : '—' }}</td>
                                <td class="text-right">{{ number_format($bus->ocupacion_actual_pct, 0) }} %</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-center text-slate-400">Sin unidades asignadas.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <h2 class="mt-6 mb-3 text-lg font-semibold text-tm-900">Accesos de la línea</h2>
            <div class="caja text-sm">
                @forelse ($accesos->groupBy(fn ($a) => $a->estacion->nombre) as $nombreEstacion => $accesosEstacion)
                    <p class="py-1"><b>{{ $nombreEstacion }}:</b> {{ $accesosEstacion->pluck('ubicacion_fisica')->join(', ') }}</p>
                @empty
                    <p class="text-slate-400">Sin accesos.</p>
                @endforelse
            </div>
        </section>
    </div>
@endsection
