{{-- Consola en vivo de la simulación: se reemplaza completa en cada ciclo --}}
<section class="overflow-hidden rounded-xl bg-noche text-white shadow-md">
    {{-- Cifras --}}
    <div class="grid grid-cols-2 divide-white/10 border-b border-white/10 sm:grid-cols-3 lg:grid-cols-6 lg:divide-x">
        @foreach ([
            ['Ciclo', $ciclo, 'text-white'],
            ['Alertas abiertas', $alertasAbiertas, $alertasAbiertas ? 'text-red-300' : 'text-white'],
            ['Refuerzos hoy', $alertasAtendidasHoy, 'text-cielo'],
            ['Buses en ruta', $busesEnRuta, 'text-white'],
            ['En reserva', $busesReserva, 'text-white'],
            ['Viajes en curso', $viajesEnCurso, 'text-white'],
        ] as [$texto, $valor, $color])
            <div class="px-6 py-5">
                <p class="text-[34px] font-black leading-none tracking-[-0.05em] tabular-nums {{ $color }}">{{ $valor }}</p>
                <p class="rotulo mt-2 !text-[9.5px] text-niebla">{{ $texto }}</p>
            </div>
        @endforeach
    </div>

    <div class="grid lg:grid-cols-5">
        {{-- Estaciones más llenas, como ranking --}}
        <div class="border-white/10 p-6 lg:col-span-2 lg:border-r">
            <p class="rotulo !text-[10px] text-cielo">Estaciones más llenas</p>
            <ol class="mt-4 space-y-3.5">
                @foreach ($masLlenas as $estacion)
                    @php $pct = $estacion->porcentajeAforo(); @endphp
                    <li class="flex items-center gap-4">
                        <span class="w-6 text-[15px] font-black text-acero">{{ $loop->iteration }}</span>
                        <div class="min-w-0 flex-1">
                            <p class="flex justify-between gap-2 text-[13px]">
                                <span class="truncate font-bold">{{ $estacion->nombre }}</span>
                                <span class="shrink-0 tabular-nums {{ $pct >= 150 ? 'font-black text-red-300' : 'text-niebla' }}">{{ $pct }} %</span>
                            </p>
                            <div class="mt-1.5 h-1 rounded-full bg-white/10">
                                <div class="h-1 rounded-full {{ $pct >= 150 ? 'bg-red-400' : ($pct >= 100 ? 'bg-amber-300' : 'bg-cielo') }}" style="width: {{ min($pct / 2, 100) }}%"></div>
                            </div>
                        </div>
                    </li>
                @endforeach
            </ol>
            <p class="mt-5 text-[11px] text-acero">Alerta de saturación al 150 % del aforo.</p>
        </div>

        {{-- Registro de eventos --}}
        <div class="p-6 lg:col-span-3">
            <p class="rotulo flex items-center gap-2 !text-[10px] text-cielo"><span class="latido h-1.5 w-1.5 rounded-full bg-emerald-400"></span>Lo que está pasando</p>
            <ul class="mt-4 max-h-[26rem] space-y-1 overflow-y-auto pr-2 text-[13px]">
                @forelse ($eventos as $evento)
                    @php
                        $punto = ['alerta' => 'bg-red-400', 'lleno' => 'bg-red-300', 'refuerzo' => 'bg-cielo', 'pico' => 'bg-amber-300', 'espera' => 'bg-amber-200'][$evento['tipo']] ?? 'bg-acero';
                    @endphp
                    <li class="flex gap-3 rounded-md px-2 py-1.5 transition-colors hover:bg-white/5">
                        <span class="shrink-0 font-mono text-[11px] text-acero">{{ $evento['hora'] }}</span>
                        <span class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full {{ $punto }}"></span>
                        <span class="{{ $evento['tipo'] === 'alerta' ? 'font-bold text-red-200' : 'text-white/85' }}">{{ $evento['texto'] }}</span>
                    </li>
                @empty
                    <li class="py-6 text-center text-niebla">Todavía no hay eventos. Inicia la simulación o ejecuta un ciclo.</li>
                @endforelse
            </ul>
        </div>
    </div>
</section>
