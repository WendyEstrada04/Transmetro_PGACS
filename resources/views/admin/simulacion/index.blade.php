@extends('layouts.app')

@section('titulo', 'Simulación')

@section('contenido')
    <x-titulo titulo="Simulación en vivo"
              subtitulo="Genera demanda de pasajeros y movimiento de la flota en tiempo real para validar la operación.">
        @if ($activa)
            <form method="POST" action="{{ route('admin.simulacion.pausar') }}">@csrf<button class="btn btn-primario">❚❚ Pausar</button></form>
        @else
            <form method="POST" action="{{ route('admin.simulacion.iniciar') }}">@csrf<button class="btn btn-primario">▶ Iniciar simulación</button></form>
        @endif
        <form method="POST" action="{{ route('admin.simulacion.ciclo') }}">@csrf<button class="btn btn-secundario">Ejecutar un ciclo</button></form>
    </x-titulo>

    {{-- Barra de control: estado, ritmo y reinicio --}}
    <section class="flex flex-wrap items-center gap-x-8 gap-y-4 rounded-xl bg-white px-6 py-4 shadow-sm">
        <p class="flex items-center gap-3">
            <span class="h-3 w-3 rounded-full {{ $activa ? 'latido bg-emerald-500' : 'bg-niebla' }}"></span>
            <span class="text-[18px] font-black tracking-tight" id="estado-simulacion">{{ $activa ? 'En curso' : 'En pausa' }}</span>
        </p>
        <label class="flex items-center gap-3">
            <span class="rotulo !text-[10px] text-acero">Un ciclo cada</span>
            <select id="intervalo" class="rounded-full border-0 bg-papel py-1.5 pl-4 pr-8 text-[13px] font-extrabold text-noche focus:ring-2 focus:ring-cielo">
                <option value="5000">5 segundos</option>
                <option value="10000" selected>10 segundos</option>
                <option value="20000">20 segundos</option>
            </select>
        </label>
        @php $pico = \App\Services\SimuladorOperacion::esHoraPico(now()); @endphp
        <p class="flex items-center gap-2 text-[13px]" data-trafico>
            <span class="rotulo !text-[10px] text-acero">Tráfico</span>
            @if (! config('transmetro.trafico', true))
                <b>Sin tráfico</b>
            @elseif ($pico)
                <b class="text-amber-700">Hora pico · tramos +40 %</b>
            @else
                <b>Normal</b><span class="text-acero">· cada tramo varía de −10 % a +30 %</span>
            @endif
        </p>
        <p class="flex-1 text-[12px] text-acero">Se ejecuta en segundo plano mientras el sistema esté encendido.</p>
        <form method="POST" action="{{ route('admin.simulacion.reiniciar') }}">@csrf<button class="enlace text-[13px]">Reiniciar aforos</button></form>
    </section>

    {{-- Consola en vivo (se actualiza en cada ciclo) --}}
    <div id="panel-simulacion" class="mt-6">
        @include('admin.simulacion._estado')
    </div>

    {{-- Hora pico y cómo funciona --}}
    <section class="mt-12 grid gap-10 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)]">
        <form method="POST" action="{{ route('admin.simulacion.hora-pico') }}" class="relative overflow-hidden rounded-xl bg-white p-7 shadow-sm">
            @csrf
            <span class="absolute inset-y-0 left-0 w-1.5 bg-red-500"></span>
            <p class="rotulo !text-[10px] text-red-600">Prueba de saturación</p>
            <h2 class="mt-2 text-[28px] font-black leading-tight tracking-[-0.03em]">Provocar hora pico</h2>
            <p class="mt-2 text-[13.5px] leading-relaxed text-acero">Lleva una estación por encima del 150 % de su aforo para comprobar la alerta y el envío de una unidad de refuerzo.</p>
            <div class="mt-5"><x-seleccion name="id_estacion" label="Estación" :opciones="$estacionesPico" required /></div>
            <button class="btn btn-peligro mt-5">Provocar hora pico</button>
        </form>

        <div>
            <p class="rotulo !text-[10px] text-acero">Qué pasa en cada ciclo</p>
            <ol class="mt-4">
                @foreach ([
                    ['Pasajeros', 'Llegan personas a las estaciones y las registra el molinete de ingreso.'],
                    ['Hora pico', 'Algunas estaciones reciben picos de demanda.'],
                    ['Buses', 'Las unidades avanzan por su ruta; en cada parada se registran descensos y se cobra cada abordaje.'],
                    ['Bus lleno', 'Si una unidad llega a su capacidad con pasajeros en espera, se solicita otra unidad.'],
                    ['Espera', 'Con ocupación menor al 25 %, la unidad prolonga su parada 5 minutos.'],
                    ['Refuerzo', 'Las alertas de saturación o de bus lleno se atienden con unidades de reserva de la ruta.'],
                ] as [$titulo, $texto])
                    <li class="revelar flex gap-5 border-t border-noche/[0.08] py-4" style="--retraso: {{ $loop->index * 80 }}ms">
                        <span class="w-10 shrink-0 text-[26px] font-black leading-none tracking-[-0.04em] text-cielo">{{ sprintf('%02d', $loop->iteration) }}</span>
                        <span>
                            <span class="block text-[16px] font-extrabold">{{ $titulo }}</span>
                            <span class="block text-[13px] leading-relaxed text-acero">{{ $texto }}</span>
                        </span>
                    </li>
                @endforeach
            </ol>
        </div>
    </section>
@endsection

@push('scripts')
    <script>
        (() => {
            const activa = @json($activa);
            const panel = document.getElementById('panel-simulacion');
            const selector = document.getElementById('intervalo');
            const token = document.querySelector('input[name=_token]').value;
            let temporizador = null;
            let ocupado = false;

            async function ciclo() {
                if (ocupado) return;
                ocupado = true;
                try {
                    const respuesta = await fetch(@json(route('admin.simulacion.ciclo')), {
                        method: 'POST',
                        headers: { 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html' },
                    });
                    if (respuesta.ok) panel.innerHTML = await respuesta.text();
                } finally {
                    ocupado = false;
                }
            }

            function programar() {
                clearInterval(temporizador);
                if (activa) temporizador = setInterval(ciclo, Number(selector.value));
            }

            try { selector.value = localStorage.getItem('simulacion.intervalo') || selector.value; } catch (e) {}
            selector.addEventListener('change', () => {
                try { localStorage.setItem('simulacion.intervalo', selector.value); } catch (e) {}
                programar();
            });
            programar();
        })();
    </script>
@endpush
