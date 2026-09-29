@extends('layouts.app')

@section('titulo', 'Inicio')

@section('contenido')
    <h1 class="mb-1 text-2xl font-semibold text-tm-900">Resumen del sistema</h1>
    <p class="mb-6 text-sm text-slate-500">Estado general de líneas, estaciones y buses.</p>

    {{-- Totales --}}
    <div class="mb-8 grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-6">
        @foreach ([
            ['Líneas', $totales['lineas']],
            ['Estaciones', $totales['estaciones']],
            ['Buses', $totales['buses']],
            ['Buses en línea', $totales['busesEnLinea']],
            ['Pagos hoy', $totales['pagosHoy']],
            ['Alertas abiertas', $totales['alertasAbiertas']],
        ] as [$etiqueta, $valor])
            <div class="rounded-lg bg-white p-4 shadow-sm {{ $etiqueta === 'Alertas abiertas' && $valor > 0 ? 'ring-2 ring-amber-400' : '' }}">
                <p class="text-xs text-slate-500">{{ $etiqueta }}</p>
                <p class="text-2xl font-semibold text-tm-900">{{ $valor }}</p>
            </div>
        @endforeach
    </div>

    {{-- Alertas abiertas --}}
    @if ($alertas->isNotEmpty())
        <section class="mb-8">
            <h2 class="mb-3 text-lg font-semibold text-tm-900">Alertas abiertas</h2>
            <div class="space-y-2">
                @foreach ($alertas as $alerta)
                    <div class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm">
                        <p class="font-medium text-amber-900">{{ $alerta->estacion->nombre }} · {{ str_replace('_', ' ', $alerta->tipo_alerta) }}</p>
                        <p class="text-amber-800">{{ $alerta->descripcion }}</p>
                        <p class="mt-1 text-xs text-amber-700">{{ $alerta->fecha_creacion->format('d/m/Y H:i') }}</p>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    {{-- Líneas --}}
    <section class="mb-8">
        <h2 class="mb-3 text-lg font-semibold text-tm-900">Líneas y buses asignados</h2>
        <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
            <table class="w-full text-left text-sm">
                <thead class="bg-tm-900 text-white">
                    <tr>
                        <th class="px-4 py-2">Línea</th>
                        <th class="px-4 py-2">Municipio</th>
                        <th class="px-4 py-2 text-right">Estaciones</th>
                        <th class="px-4 py-2 text-right">Distancia</th>
                        <th class="px-4 py-2 text-right">Buses</th>
                        <th class="px-4 py-2">Permitido (mín.–máx.)</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($lineas as $linea)
                        @php
                            $minimo = $linea->estaciones_count;
                            $maximo = 2 * $linea->estaciones_count;
                            $ok = $linea->buses_count >= $minimo && $linea->buses_count <= $maximo;
                        @endphp
                        <tr class="border-t border-slate-100">
                            <td class="px-4 py-2 font-medium">{{ $linea->codigo_linea }}</td>
                            <td class="px-4 py-2">{{ $linea->municipio->nombre }}</td>
                            <td class="px-4 py-2 text-right">{{ $linea->estaciones_count }}</td>
                            <td class="px-4 py-2 text-right">{{ number_format($linea->distancia_total_km, 2) }} km</td>
                            <td class="px-4 py-2 text-right">{{ $linea->buses_count }}</td>
                            <td class="px-4 py-2">
                                <span class="rounded-full px-2 py-0.5 text-xs {{ $ok ? 'bg-tm-100 text-tm-700' : 'bg-red-100 text-red-700' }}">
                                    {{ $minimo }}–{{ $maximo }} {{ $ok ? '✓' : '✗' }}
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    {{-- Estaciones --}}
    <section>
        <h2 class="mb-3 text-lg font-semibold text-tm-900">Aforo de estaciones</h2>
        <div class="grid gap-3 md:grid-cols-2">
            @foreach ($estaciones as $estacion)
                @php $pct = $estacion->porcentajeAforo(); @endphp
                <div class="rounded-lg bg-white p-4 shadow-sm">
                    <div class="mb-2 flex justify-between text-sm">
                        <span class="font-medium">{{ $estacion->nombre }} <span class="text-slate-400">· {{ $estacion->municipio->nombre }}</span></span>
                        <span class="{{ $estacion->requiereRefuerzo() ? 'font-semibold text-red-600' : 'text-slate-600' }}">
                            {{ $estacion->aforo_actual }}/{{ $estacion->aforo_maximo }} ({{ $pct }} %)
                        </span>
                    </div>
                    <div class="h-2 rounded-full bg-slate-100">
                        <div class="h-2 rounded-full {{ $pct >= 150 ? 'bg-red-500' : ($pct >= 100 ? 'bg-amber-400' : 'bg-tm-500') }}"
                             style="width: {{ min($pct / 2, 100) }}%"></div>
                    </div>
                </div>
            @endforeach
        </div>
        <p class="mt-2 text-xs text-slate-500">La barra llega al final en el 200 % del aforo. Al 150 % se genera una alerta de refuerzo (punto 14).</p>
    </section>
@endsection
