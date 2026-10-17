@extends('layouts.app')

@section('titulo', $bus->exists ? 'Editar bus' : 'Nuevo bus')

@section('contenido')
    <x-titulo :titulo="$bus->exists ? 'Bus '.$bus->placa : 'Nuevo bus'" :volver="route('admin.buses.index')">
        @if ($bus->exists)
            <x-eliminar :accion="route('admin.buses.destroy', $bus)" texto="Eliminar bus" class="btn"
                        confirmar="¿Eliminar este bus? Su parqueo quedará libre." />
        @endif
    </x-titulo>

    <div class="grid gap-6 lg:grid-cols-3">
        <form method="POST" class="caja space-y-4 lg:col-span-2"
              action="{{ $bus->exists ? route('admin.buses.update', $bus) : route('admin.buses.store') }}">
            @csrf
            @if ($bus->exists) @method('PUT') @endif

            <div class="grid gap-4 md:grid-cols-2">
                <x-campo name="placa" label="Placa" :value="$bus->placa" required maxlength="15" placeholder="C387JGL"
                         ayuda="Formato C000AAA." />
                <x-seleccion name="tipo_unidad" label="Tipo de unidad" :value="$bus->tipo_unidad" required
                             :opciones="collect(\App\Models\Bus::TIPOS_UNIDAD)->map(fn ($t) => $t[0].' · '.$t[1].' pasajeros')->all()" />
                <x-campo name="capacidad_maxima" label="Capacidad máxima (pasajeros)" type="number" min="1" max="500" :value="$bus->capacidad_maxima" required
                         ayuda="Se completa según el tipo de unidad; se puede ajustar." />
                <x-seleccion name="id_linea_asignada" label="Línea asignada" :opciones="$lineas" :value="$bus->id_linea_asignada"
                             vacio="Sin línea" ayuda="Entre paréntesis: unidades asignadas y cupo de la línea." />
                <x-seleccion name="estado_logico" label="Estado" :opciones="$estados" :value="$bus->estado_logico" required
                             :ayuda="$bus->exists && $bus->piloto ? 'Piloto: '.$bus->piloto->empleado->nombre_completo : 'Sin piloto asignado: no puede ponerse en ruta.'" />
                @unless ($bus->exists)
                    <x-seleccion name="id_parqueo" label="Parqueo" :opciones="$parqueosLibres" required vacio="Selecciona un parqueo libre…"
                                 ayuda="Bahía donde se resguarda la unidad." />
                @endunless
            </div>

            @if ($bus->exists)
                <p class="rounded-lg bg-papel px-4 py-3 text-sm" data-a-bordo>
                    <b>{{ $bus->pasajeros_a_bordo }} de {{ $bus->capacidad_maxima }} pasajeros a bordo</b> ({{ number_format($bus->ocupacion_actual_pct, 0) }} %).
                    <span class="text-slate-500">Dato en tiempo real de los molinetes de la unidad.</span>
                </p>
            @else
                <p class="text-xs text-slate-500">La unidad se registra con su molinete de entrada (cobro) y de salida (conteo).</p>
            @endif

            <button class="btn btn-primario">Guardar</button>
        </form>

        @if ($bus->exists)
            <div class="space-y-6">
            <div class="caja h-fit space-y-3">
                <h2 class="font-semibold text-tm-900">Molinetes del bus</h2>
                @foreach ($bus->molinetes->sortBy('tipo') as $m)
                    <form method="POST" action="{{ route('admin.buses.molinetes.estado', [$bus, $m]) }}" class="space-y-1">
                        @csrf
                        @method('PATCH')
                        <label class="block text-sm font-semibold" for="molinete-{{ $m->tipo }}">{{ $m->tipo === 'entrada_bus' ? 'Entrada · cobro' : 'Salida · conteo' }}</label>
                        <p class="text-xs text-slate-500">IP {{ $m->ip_address }}</p>
                        <div class="flex gap-2">
                            <select id="molinete-{{ $m->tipo }}" name="estado_operativo" class="flex-1 rounded-lg border border-niebla/50 px-3 py-2 text-sm">
                                @foreach (\App\Models\Molinete::ESTADOS as $valor => $texto)
                                    <option value="{{ $valor }}" @selected($m->estado_operativo === $valor)>{{ $texto }}</option>
                                @endforeach
                            </select>
                            <button class="btn btn-secundario btn-chico">Cambiar</button>
                        </div>
                    </form>
                @endforeach
            </div>
            <div class="caja h-fit space-y-3">
                <h2 class="font-semibold text-tm-900">Parqueo</h2>
                <p class="text-sm">Actual: <b>{{ $bus->parqueo ? $bus->parqueo->estacion->nombre.' · '.$bus->parqueo->codigo_bahia : 'Sin parqueo' }}</b></p>
                @if ($parqueosLibres)
                    <form method="POST" action="{{ route('admin.buses.parqueo', $bus) }}" class="space-y-3">
                        @csrf
                        @method('PUT')
                        <x-seleccion name="id_parqueo_nuevo" label="Cambiar a" :opciones="$parqueosLibres" required vacio="Selecciona…" />
                        <button class="btn btn-secundario w-full">Cambiar parqueo</button>
                    </form>
                @else
                    <p class="text-sm text-slate-400">No hay bahías disponibles.</p>
                @endif
            </div>
            </div>
        @endif
    </div>

    @if ($bus->exists)
        @php
            $estadoMant = ['reportado' => 'bg-amber-50 text-amber-800 ring-amber-600/20', 'en_proceso' => 'bg-tm-50 text-pizarra ring-pizarra/20', 'resuelto' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20'];
            $abiertos = $bus->mantenimientos->filter->abierto();
        @endphp
        {{-- Mantenimiento (taller): las fallas las reportan los operadores de estación o se registran aquí --}}
        <div id="mantenimiento" class="caja mt-6 scroll-mt-24">
            <div class="mb-4 flex flex-wrap items-end justify-between gap-2">
                <div>
                    <h2 class="font-semibold text-tm-900">Mantenimiento</h2>
                    <p class="text-sm text-slate-500">Una falla de prioridad alta saca el bus de servicio hasta que se resuelva.</p>
                </div>
                <span class="etiqueta {{ $abiertos->isNotEmpty() ? 'bg-amber-100 text-amber-800' : 'bg-tm-100 text-tm-700' }}">{{ $abiertos->count() }} {{ $abiertos->count() === 1 ? 'reporte abierto' : 'reportes abiertos' }}</span>
            </div>
            <div class="grid gap-6 lg:grid-cols-[minmax(0,20rem)_minmax(0,1fr)]">
                <form method="POST" action="{{ route('admin.buses.mantenimientos.store', $bus) }}" class="space-y-3 rounded-lg bg-papel p-4">
                    @csrf
                    <x-seleccion name="tipo" label="Tipo" :opciones="\App\Models\MantenimientoBus::TIPOS" required />
                    <x-seleccion name="categoria" label="Parte del bus" :opciones="\App\Models\MantenimientoBus::CATEGORIAS" required vacio="Elige…" />
                    <x-seleccion name="prioridad" label="Prioridad" :opciones="\App\Models\MantenimientoBus::PRIORIDADES" value="media" required />
                    <div>
                        <label for="descripcion" class="rotulo mb-2 block !text-[10.5px] text-pizarra">Descripción <span class="text-red-600">*</span></label>
                        <textarea id="descripcion" name="descripcion" rows="3" required maxlength="500"
                                  class="w-full rounded-lg border border-niebla/50 bg-white px-3.5 py-2.5 text-[15px] text-noche focus:border-noche focus:ring-4 focus:ring-cielo/35 focus:outline-none">{{ old('descripcion') }}</textarea>
                        @error('descripcion')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <x-campo name="kilometraje" label="Kilometraje del odómetro" type="number" min="0" />
                    <button class="btn btn-primario w-full">Guardar</button>
                </form>
                <ul>
                    @forelse ($bus->mantenimientos as $m)
                        <li class="border-b border-noche/[0.06] py-3">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <p class="text-[14px] font-extrabold">{{ \App\Models\MantenimientoBus::TIPOS[$m->tipo] }} · {{ \App\Models\MantenimientoBus::CATEGORIAS[$m->categoria] ?? $m->categoria }}</p>
                                <span class="etiqueta ring-1 {{ $estadoMant[$m->estado] }}">{{ \App\Models\MantenimientoBus::ESTADOS[$m->estado] }}</span>
                            </div>
                            <p class="mt-1 text-[13px] text-pizarra">{{ $m->descripcion }}</p>
                            <p class="mt-1 text-[11.5px] text-acero">
                                {{ $m->fecha_reporte->format('d/m/Y H:i') }}
                                @if ($m->kilometraje) · {{ number_format($m->kilometraje) }} km @endif
                                · prioridad {{ $m->prioridad }}
                                @if ($m->reportadoPor) · reportó {{ $m->reportadoPor->nombre_completo }} @endif
                                @if ($m->fecha_cierre) · cerrado {{ $m->fecha_cierre->format('d/m/Y') }} @endif
                            </p>
                            @if ($m->abierto())
                                <div class="mt-2 flex gap-2">
                                    @if ($m->estado === 'reportado')
                                        <form method="POST" action="{{ route('admin.mantenimientos.update', $m) }}">
                                            @csrf @method('PATCH')
                                            <input type="hidden" name="estado" value="en_proceso">
                                            <button class="btn btn-secundario btn-chico">Enviar a taller</button>
                                        </form>
                                    @endif
                                    <form method="POST" action="{{ route('admin.mantenimientos.update', $m) }}">
                                        @csrf @method('PATCH')
                                        <input type="hidden" name="estado" value="resuelto">
                                        <button class="btn btn-primario btn-chico">Marcar resuelto</button>
                                    </form>
                                </div>
                            @endif
                        </li>
                    @empty
                        <li class="py-6 text-center text-[13px] text-acero">Sin registros de mantenimiento.</li>
                    @endforelse
                </ul>
            </div>
        </div>
    @endif
@endsection

@push('scripts')
    <script>
        // Al elegir el tipo de unidad se propone su capacidad (biarticulado 270, articulado 160, convencional 119, eléctrico 80).
        (() => {
            const capacidades = @json(collect(\App\Models\Bus::TIPOS_UNIDAD)->map(fn ($t) => $t[1]));
            const tipo = document.getElementById('tipo_unidad');
            const capacidad = document.getElementById('capacidad_maxima');
            tipo?.addEventListener('change', () => { if (capacidades[tipo.value]) capacidad.value = capacidades[tipo.value]; });
        })();
    </script>
@endpush
