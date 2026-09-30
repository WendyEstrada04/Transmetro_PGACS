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
        <a href="{{ route('admin.lineas.edit', $linea) }}" class="btn btn-secundario">Editar / cambiar estado</a>
        <x-eliminar :accion="route('admin.lineas.destroy', $linea)" texto="Eliminar línea" class="btn"
                    confirmar="¿Eliminar la línea? Sus buses quedarán sin línea asignada." />
    </x-titulo>

    <div class="mb-6 grid gap-4 md:grid-cols-4">
        <div class="caja"><p class="text-xs text-slate-500">Estado</p><p class="mt-1">@include('admin.lineas._estado', ['estado' => $linea->estado])</p></div>
        <div class="caja"><p class="text-xs text-slate-500">Estaciones</p><p class="text-2xl font-semibold text-tm-900">{{ $n }}</p></div>
        <div class="caja"><p class="text-xs text-slate-500">Distancia total (punto 13)</p><p class="text-2xl font-semibold text-tm-900">{{ number_format($linea->distancia_total_km, 2) }} km</p></div>
        <div class="caja {{ $ok ? '' : 'ring-2 ring-red-300' }}">
            <p class="text-xs text-slate-500">Buses (permitido {{ $n }}–{{ 2 * $n }})</p>
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
            <a href="{{ route('admin.lineas.trazo.edit', $linea) }}" class="btn btn-primario btn-chico ml-auto">
                {{ $linea->tieneTrazo() ? 'Editar trazo de la ruta' : 'Trazar ruta por las calles' }}
            </a>
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
        <form method="POST" action="{{ route('admin.lineas.horarios', $linea) }}" class="caja">
            @csrf
            @method('PUT')
            <div class="grid gap-4 md:grid-cols-3">
                @foreach (\App\Models\HorarioLinea::TIPOS_DIA as $tipo => $etiquetaDia)
                    @php $horario = $linea->horarios->firstWhere('tipo_dia', $tipo); @endphp
                    <div>
                        <p class="mb-1 text-sm font-medium">{{ $etiquetaDia }}</p>
                        <div class="flex items-center gap-2">
                            <input type="time" name="horarios[{{ $tipo }}][inicio]" required
                                   value="{{ old("horarios.$tipo.inicio", $horario?->inicio() ?? \App\Models\HorarioLinea::HORARIO_OFICIAL[$tipo][0]) }}"
                                   class="rounded-md border border-slate-300 px-2 py-1.5 text-sm">
                            <span class="text-slate-400">a</span>
                            <input type="time" name="horarios[{{ $tipo }}][fin]" required
                                   value="{{ old("horarios.$tipo.fin", $horario?->fin() ?? \App\Models\HorarioLinea::HORARIO_OFICIAL[$tipo][1]) }}"
                                   class="rounded-md border border-slate-300 px-2 py-1.5 text-sm">
                        </div>
                        @error("horarios.$tipo.fin")<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                @endforeach
            </div>
            <div class="mt-4 flex flex-wrap items-center justify-between gap-2">
                <p class="text-xs text-slate-500">Inicio y fin del servicio en las estaciones extremo de la línea.</p>
                <button class="btn btn-secundario btn-chico">Guardar horario</button>
            </div>
        </form>
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
                        <x-eliminar :accion="route('admin.lineas.estaciones.destroy', [$linea, $estacion])" texto="Quitar"
                                    confirmar="¿Quitar esta estación del recorrido?" />
                    </div>
                @empty
                    <p class="text-sm text-slate-400">La línea todavía no tiene estaciones.</p>
                @endforelse

                @if ($estacionesDisponibles)
                    <form method="POST" action="{{ route('admin.lineas.estaciones.store', $linea) }}" class="mt-4 grid items-end gap-3 border-t border-slate-100 pt-4 sm:grid-cols-3">
                        @csrf
                        <x-seleccion name="id_estacion" label="Agregar al final" :opciones="$estacionesDisponibles" required vacio="Selecciona…" />
                        <x-campo name="distancia_previa_km" label="Km desde la anterior" type="number" step="0.01" min="0" :value="$n === 0 ? 0 : null" required />
                        <button class="btn btn-primario">+ Agregar</button>
                    </form>
                @endif
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
                            <tr><td colspan="4" class="text-center text-slate-400">Sin buses. Asígnalos desde el módulo Buses.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Punto 8 --}}
            <h2 class="mt-6 mb-3 text-lg font-semibold text-tm-900">Accesos de la línea (punto 8)</h2>
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
