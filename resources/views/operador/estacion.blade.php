@extends('layouts.app')

@section('titulo', $estacion->nombre)

@section('contenido')
    @php $pct = $estacion->porcentajeAforo(); @endphp

    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="text-sm text-slate-500">Estación · {{ $estacion->municipio->nombre }}</p>
            <h1 class="text-2xl font-semibold text-tm-900">{{ $estacion->nombre }}</h1>
            <p class="text-sm text-slate-500">Líneas: {{ $estacion->lineas->pluck('codigo_linea')->join(', ') }}</p>
        </div>
        <p class="text-xs text-slate-400">Se actualiza cada 30 segundos · {{ now()->format('H:i:s') }}</p>
    </div>

    {{-- Alertas --}}
    @foreach ($alertas as $alerta)
        <div class="mb-3 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-red-300 bg-red-50 p-4">
            <div class="text-sm">
                <p class="font-semibold text-red-800">⚠ Alerta: {{ str_replace('_', ' ', $alerta->tipo_alerta) }}</p>
                <p class="text-red-700">{{ $alerta->descripcion }}</p>
                <p class="text-xs text-red-600">{{ $alerta->fecha_creacion->format('d/m/Y H:i') }}</p>
            </div>
            <form method="POST" action="{{ route('operador.alertas.atender', $alerta) }}">
                @csrf
                <button class="rounded-md bg-red-600 px-3 py-2 text-sm font-medium text-white hover:bg-red-700">Marcar como atendida</button>
            </form>
        </div>
    @endforeach

    <div class="mb-8 grid gap-4 md:grid-cols-3">
        {{-- Aforo --}}
        <div class="rounded-lg bg-white p-5 shadow-sm md:col-span-2">
            <h2 class="mb-3 font-semibold text-tm-900">Aforo de la estación</h2>
            <p class="text-4xl font-semibold {{ $estacion->requiereRefuerzo() ? 'text-red-600' : 'text-tm-900' }}">
                {{ $estacion->aforo_actual }} <span class="text-lg text-slate-400">/ {{ $estacion->aforo_maximo }} personas</span>
            </p>
            <div class="my-3 h-3 rounded-full bg-slate-100">
                <div class="h-3 rounded-full {{ $pct >= 150 ? 'bg-red-500' : ($pct >= 100 ? 'bg-amber-400' : 'bg-tm-500') }}"
                     style="width: {{ min($pct / 2, 100) }}%"></div>
            </div>
            <p class="mb-4 text-sm text-slate-500">{{ $pct }} % del aforo máximo. Al llegar al 150 % se pide un bus de refuerzo.</p>

            <form method="POST" action="{{ route('operador.aforo') }}" class="flex flex-wrap items-end gap-2">
                @csrf
                <div>
                    <label for="aforo_actual" class="mb-1 block text-sm font-medium">Personas en la estación</label>
                    <input id="aforo_actual" name="aforo_actual" type="number" min="0" max="100000" required
                           value="{{ old('aforo_actual', $estacion->aforo_actual) }}"
                           class="w-40 rounded-md border border-slate-300 px-3 py-2 focus:border-tm-500 focus:ring-2 focus:ring-tm-300 focus:outline-none">
                </div>
                <button class="rounded-md bg-tm-700 px-4 py-2 text-sm font-medium text-white hover:bg-tm-900">Actualizar aforo</button>
            </form>
        </div>

        {{-- Parqueos --}}
        <div class="rounded-lg bg-white p-5 shadow-sm">
            <h2 class="mb-3 font-semibold text-tm-900">Parqueos</h2>
            @forelse ($estacion->parqueos->sortBy('codigo_bahia') as $parqueo)
                <p class="flex justify-between border-b border-slate-100 py-1 text-sm">
                    <span>{{ $parqueo->codigo_bahia }}</span>
                    <span class="{{ $parqueo->bus ? 'text-slate-700' : 'text-slate-400' }}">{{ $parqueo->bus?->placa ?? 'Libre' }}</span>
                </p>
            @empty
                <p class="text-sm text-slate-400">Esta estación no tiene parqueos.</p>
            @endforelse
        </div>
    </div>

    <div class="mb-8 grid gap-4 lg:grid-cols-3">
        {{-- Registrar pase en molinete (punto 18) --}}
        <form method="POST" action="{{ route('operador.pases') }}" class="caja space-y-3 lg:col-span-2">
            @csrf
            <h2 class="font-semibold text-tm-900">Registrar pase en molinete</h2>
            <p class="text-xs text-slate-500">Pago solo con Tarjeta Ciudadana o tarjeta bancaria sin contacto. Una tarjeta nueva usa primero sus viajes gratis.</p>
            <div class="grid gap-3 md:grid-cols-2">
                <x-seleccion name="id_acceso" label="Acceso / molinete" required
                             :opciones="$estacion->accesos->filter(fn ($a) => $a->molinete)->mapWithKeys(fn ($a) => [$a->id_acceso => $a->ubicacion_fisica.' · '.$a->molinete->estado_operativo])->all()" />
                <x-seleccion name="id_linea" label="Línea" required
                             :opciones="$estacion->lineas->mapWithKeys(fn ($l) => [$l->id_linea => $l->codigo_linea.' — Q'.number_format($l->tarifa, 2)])->all()" />
                <x-seleccion name="metodo_pago" label="Forma de pago" :opciones="$metodosPago" required />
                <x-campo name="numero_tarjeta" label="Número de Tarjeta Ciudadana" maxlength="20" placeholder="TC4000007919" />
            </div>
            @error('pase')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
            <button class="btn btn-primario">Registrar pase</button>
        </form>

        {{-- Horario de servicio --}}
        <div class="caja">
            <h2 class="mb-3 font-semibold text-tm-900">Horario de servicio</h2>
            @foreach ($estacion->lineas as $linea)
                <div class="mb-3 border-b border-slate-100 pb-3 text-sm last:mb-0 last:border-0 last:pb-0">
                    <p class="flex items-center justify-between font-medium">
                        {{ $linea->codigo_linea }} {{ $linea->nombre }}
                        <span class="etiqueta {{ $linea->enServicio() ? 'bg-tm-100 text-tm-700' : 'bg-slate-100 text-slate-600' }}">
                            {{ $linea->enServicio() ? 'En servicio' : 'Fuera de horario' }}
                        </span>
                    </p>
                    @foreach (\App\Models\HorarioLinea::TIPOS_DIA as $tipo => $etiquetaDia)
                        @php $horario = $linea->horarios->firstWhere('tipo_dia', $tipo); @endphp
                        <p class="flex justify-between text-slate-600">
                            <span>{{ $etiquetaDia }}</span>
                            <span>{{ $horario ? $horario->inicio().' – '.$horario->fin() : '—' }}</span>
                        </p>
                    @endforeach
                </div>
            @endforeach
        </div>
    </div>

    {{-- Últimos pases --}}
    <section class="mb-8">
        <h2 class="mb-3 text-lg font-semibold text-tm-900">Últimos pases en esta estación</h2>
        <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
            <table class="tabla">
                <thead><tr><th>Hora</th><th>Acceso</th><th>Línea</th><th>Forma de pago</th><th class="text-right">Cobro</th></tr></thead>
                <tbody>
                    @forelse ($pases as $pase)
                        <tr>
                            <td>{{ $pase->fecha_hora?->format('d/m H:i') }}</td>
                            <td>{{ $pase->molinete->acceso->ubicacion_fisica }}</td>
                            <td>{{ $pase->linea?->codigo_linea ?? '—' }}</td>
                            <td>{{ $metodosPago[$pase->metodo_pago] ?? $pase->metodo_pago }}{{ $pase->tarjeta ? ' · '.$pase->tarjeta->numero_tarjeta : '' }}</td>
                            <td class="text-right">{{ $pase->viaje_gratis ? 'Gratis' : 'Q'.number_format($pase->monto, 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-slate-400">Sin pases registrados.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    {{-- Buses de las líneas de la estación --}}
    <section class="mb-8">
        <h2 class="mb-3 text-lg font-semibold text-tm-900">Buses de las líneas que pasan por la estación</h2>
        <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
            <table class="w-full text-left text-sm">
                <thead class="bg-tm-900 text-white">
                    <tr>
                        <th class="px-4 py-2">Placa</th>
                        <th class="px-4 py-2">Línea</th>
                        <th class="px-4 py-2">Estado</th>
                        <th class="px-4 py-2 text-right">Capacidad</th>
                        <th class="px-4 py-2 text-right">Ocupación</th>
                        <th class="px-4 py-2">Espera en estación</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($buses as $bus)
                        <tr class="border-t border-slate-100">
                            <td class="px-4 py-2 font-medium">{{ $bus->placa }}</td>
                            <td class="px-4 py-2">{{ $bus->linea->codigo_linea }}</td>
                            <td class="px-4 py-2">{{ str_replace('_', ' ', $bus->estado_logico) }}</td>
                            <td class="px-4 py-2 text-right">{{ $bus->capacidad_maxima }}</td>
                            <td class="px-4 py-2 text-right">{{ number_format($bus->ocupacion_actual_pct, 0) }} %</td>
                            <td class="px-4 py-2">
                                @if ($bus->minutosEsperaExtra() > 0)
                                    <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-800">+{{ $bus->minutosEsperaExtra() }} min (menos del 25 %)</span>
                                @else
                                    <span class="text-xs text-slate-400">Normal</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    {{-- Accesos y seguridad --}}
    <section>
        <h2 class="mb-3 text-lg font-semibold text-tm-900">Accesos y personal de seguridad</h2>
        <div class="grid gap-3 md:grid-cols-3">
            @foreach ($estacion->accesos as $acceso)
                <div class="rounded-lg bg-white p-4 text-sm shadow-sm">
                    <p class="font-medium">{{ $acceso->ubicacion_fisica }}</p>
                    <p class="text-xs text-slate-500">Molinete {{ $acceso->molinete?->ip_address ?? '—' }} · {{ $acceso->molinete?->estado_operativo ?? 'sin molinete' }}</p>
                    <p class="mt-2 text-slate-600">
                        Guardia: {{ $acceso->guardias->map(fn ($g) => $g->empleado->nombre_completo)->join(', ') ?: 'Sin guardia asignado' }}
                    </p>
                </div>
            @endforeach
        </div>
    </section>
@endsection

@push('scripts')
    <script>
        // Consulta periódica: recarga la página cada 30 s para ver alertas nuevas,
        // salvo que el operador esté escribiendo en un campo.
        setInterval(() => {
            const activo = document.activeElement;
            if (!activo || !['INPUT', 'TEXTAREA', 'SELECT'].includes(activo.tagName)) {
                window.location.reload();
            }
        }, 30000);
    </script>
@endpush
