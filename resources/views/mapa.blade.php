@extends('layouts.app')

@section('titulo', 'Monitoreo en vivo')

@push('head')
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
          integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
            integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <style>
        .bus-pin { display: inline-flex; align-items: center; gap: 4px; padding: 2px 7px 2px 5px; border-radius: 999px; background: #fff;
                   border: 2px solid var(--c); color: #162133; font: 900 10.5px/1.3 'Nunito Sans', sans-serif; white-space: nowrap;
                   box-shadow: 0 3px 10px rgb(22 33 51 / .25); transform: translate(-50%, -50%); }
        .bus-pin::before { content: ''; width: 7px; height: 7px; border-radius: 999px; background: var(--c); }
        .bus-pin.propio { background: #162133; color: #fff; font-size: 12px; padding: 3px 9px 3px 6px; box-shadow: 0 0 0 4px rgb(142 185 252 / .7), 0 4px 14px rgb(22 33 51 / .4); }
        .bus-pin.detenido::after { content: '■'; font-size: 7px; opacity: .55; }
        .chip-bus { display: inline-flex; align-items: center; gap: 4px; border-radius: 999px; padding: 1px 8px; font-size: 10.5px; font-weight: 900;
                    background: color-mix(in srgb, var(--c) 16%, white); color: #162133; cursor: pointer; transition: transform .3s; }
        .chip-bus:hover { transform: translateY(-1px); }
        .chip-bus.propio { background: #162133; color: #fff; }
        .linea-apagada { opacity: .35; }
        /* De lejos (toda la red) cada bus es un punto de color; al acercarse aparece su placa. */
        .lejos .bus-pin:not(.propio) { font-size: 0; gap: 0; padding: 3px; }
        .lejos .bus-pin:not(.propio)::after { display: none; }
    </style>
@endpush

@section('contenido')
    <x-titulo titulo="Monitoreo en vivo" seccion="Red de rutas · en tiempo real"
              subtitulo="Ubicación en tiempo real de cada unidad: estación actual y próxima parada." />

    {{-- Estado del monitoreo y filtro por línea --}}
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-3">
            {{-- Simulación: hace llegar pasajeros y avanzar los buses en tiempo real. Solo el administrador la inicia o pausa (afecta a toda la red). --}}
            @if (auth()->user()->esAdministrador())
                <button type="button" id="boton-simulacion" data-activa="{{ $simulacionActiva ? '1' : '0' }}"
                        class="btn btn-chico {{ $simulacionActiva ? 'btn-secundario' : 'btn-primario' }}">
                    {{ $simulacionActiva ? '⏸ Pausar simulación' : '▶ Iniciar simulación' }}
                </button>
            @endif
            <p class="flex items-center gap-2 text-[13px] text-pizarra">
                <span id="punto-vivo" class="h-2 w-2 rounded-full {{ $simulacionActiva ? 'latido bg-emerald-500' : 'bg-niebla' }}"></span>
                <b id="cuenta-buses">{{ count($buses) }}</b> buses en ruta · actualizado <span id="hora-actualizacion">{{ now()->format('H:i:s') }}</span>
                <span id="nota-simulacion" class="text-acero">{{ $simulacionActiva ? '· simulación en curso' : '· simulación en pausa: los buses se quedan en su próxima estación' }}</span>
                @if (config('transmetro.trafico', true) && \App\Services\SimuladorOperacion::esHoraPico(now()))
                    <span class="font-bold text-amber-700" data-trafico>· tráfico de hora pico: los buses van más lentos</span>
                @endif
            </p>
        </div>
        <div class="flex flex-wrap gap-1.5" id="filtro-lineas">
            @if ($estacionPropia)
                <span class="self-center text-[12px] text-acero">Tu estación: <b class="text-noche">{{ $estacionPropia['nombre'] }}</b> · toca una línea para verla u ocultarla</span>
            @endif
            @foreach ($lineas as $linea)
                <button type="button" data-filtro="{{ $linea->codigo_linea }}" aria-pressed="true"
                        class="rounded-full px-3 py-1 text-[12px] font-black transition-opacity" style="background: {{ $linea->color }}; color: {{ $linea->colorTexto() }}">{{ $linea->codigo_linea }}</button>
            @endforeach
        </div>
    </div>

    <div class="overflow-hidden rounded-xl bg-white shadow-sm">
        <div id="mapa-vivo" class="h-[68vh] min-h-96 w-full bg-tm-50"></div>
        <p class="flex flex-wrap gap-x-5 gap-y-1 border-t border-noche/[0.06] px-4 py-2 text-[11.5px] text-acero">
            <span class="flex items-center gap-1.5"><span class="bus-pin" style="--c:#6b46c1; transform:none">TM</span> Bus en camino</span>
            <span class="flex items-center gap-1.5"><span class="bus-pin detenido" style="--c:#6b46c1; transform:none">TM</span> Detenido en la estación</span>
            <span class="ml-auto">Selecciona una unidad para ver su destino y ocupación.</span>
        </p>
    </div>

    {{-- Cada línea con sus estaciones y los buses que van en ella --}}
    <div class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @foreach ($lineas as $linea)
            <div class="caja" data-tarjeta="{{ $linea->codigo_linea }}">
                <div class="mb-2 flex items-center gap-2">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md text-sm font-bold" style="background: {{ $linea->color }}; color: {{ $linea->colorTexto() }}">{{ $linea->codigo_linea }}</span>
                    <div class="min-w-0">
                        <p class="truncate font-semibold leading-tight text-tm-900">{{ $linea->nombre }}</p>
                        <p class="text-xs text-slate-500">
                            {{ $linea->estaciones->count() }} estaciones · <b class="text-noche" data-cuenta-linea="{{ $linea->codigo_linea }}">0</b> buses en ruta
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
                <ol class="text-sm">
                    @foreach ($linea->estaciones as $estacion)
                        <li class="relative pl-5">
                            <span class="absolute left-[4px] top-0 w-0.5 {{ $loop->first ? 'top-2.5' : '' }} {{ $loop->last && ! $linea->ruta_circular ? 'h-2.5' : 'bottom-0' }}" style="background: {{ $linea->color }}"></span>
                            <span class="absolute left-0 top-[5px] h-2.5 w-2.5 rounded-full border-2 bg-white" style="border-color: {{ $linea->color }}"></span>
                            <div class="flex flex-wrap items-center gap-1.5 py-0.5">
                                <span>{{ $estacion->nombre }}</span>
                                <span class="flex flex-wrap gap-1" data-chips="{{ $linea->codigo_linea }}|{{ $estacion->id_estacion }}"></span>
                            </div>
                            {{-- Buses que van en camino desde esta estación hacia la siguiente --}}
                            <div class="hidden flex-wrap items-center gap-1 pb-1 text-[10.5px] text-acero" data-tramo="{{ $linea->codigo_linea }}|{{ $estacion->id_estacion }}"></div>
                        </li>
                    @endforeach
                </ol>
            </div>
        @endforeach
    </div>
    <p class="mt-3 text-xs text-slate-500">Mapa: © colaboradores de OpenStreetMap. Posición estimada entre estaciones según la hora de salida y el tiempo promedio del tramo.</p>
@endsection

@push('scripts')
    <script>
        (() => {
            const lineas = @json($lineasMapa);
            let buses = @json($buses);
            let desfase = @json($ahora) - Date.now();       // diferencia entre el reloj del servidor y el de esta computadora
            const URL_BUSES = @json(route('mapa.buses'));
            const URL_CICLO = @json(route('mapa.ciclo'));
            const URL_SIMULACION = @json(route('mapa.simulacion'));
            const TOKEN = @json(csrf_token());
            const propias = @json($lineasPropias);     // líneas de la estación del operador (vacío: se ven todas)
            const estacionPropia = @json($estacionPropia);
            let activa = @json($simulacionActiva);
            const esc = (t) => String(t ?? '').replace(/[&<>"']/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
            const caja = document.getElementById('mapa-vivo');
            if (!window.L || !caja) return;

            // ---------- Mapa y rutas ----------
            const mapa = L.map(caja, {scrollWheelZoom: false});
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {maxZoom: 19, attribution: '&copy; OpenStreetMap'}).addTo(mapa);
            const capas = {};      // codigo -> L.layerGroup de la ruta y sus estaciones
            const geo = {};        // codigo -> geometría para ubicar buses sobre el trazo
            const estaciones = {}; // id -> nombre
            const limites = [];
            const metros = (a, b) => {
                const x = (b[1] - a[1]) * Math.cos((a[0] + b[0]) * Math.PI / 360), y = b[0] - a[0];
                return Math.sqrt(x * x + y * y) * 111320;
            };

            lineas.forEach((l) => {
                const porCalles = l.trazo.length >= 2;
                let puntos = porCalles ? l.trazo.slice() : l.estaciones.map((e) => [e.lat, e.lon]);
                if (!porCalles && l.circular && puntos.length > 2) puntos.push(puntos[0]);
                const grupo = L.layerGroup().addTo(mapa);
                L.polyline(puntos, {color: '#ffffff', weight: 8, opacity: 0.9, interactive: false}).addTo(grupo);
                L.polyline(puntos, {color: l.color, weight: 4, dashArray: porCalles ? null : '8 8', interactive: false}).addTo(grupo);
                l.estaciones.forEach((e) => {
                    estaciones[e.id] = e.nombre;
                    L.circleMarker([e.lat, e.lon], {radius: 4, color: l.color, weight: 2, fillColor: '#fff', fillOpacity: 1})
                        .bindTooltip(`${esc(l.codigo)} · ${e.orden}. ${esc(e.nombre)}`, {direction: 'top'}).addTo(grupo);
                });
                capas[l.codigo] = grupo;
                if (!propias.length || propias.includes(l.codigo)) limites.push(...puntos);

                // Distancia acumulada sobre el trazo y en qué punto del trazo queda cada estación (buscando hacia adelante).
                const acumulado = [0];
                for (let i = 1; i < puntos.length; i++) acumulado.push(acumulado[i - 1] + metros(puntos[i - 1], puntos[i]));
                const indice = {};
                let desde = 0;
                l.estaciones.forEach((e) => {
                    let mejor = desde, menor = Infinity;
                    for (let k = 0; k < puntos.length; k++) {
                        const j = (desde + k) % puntos.length;
                        const d = metros([e.lat, e.lon], puntos[j]);
                        if (d < menor) { menor = d; mejor = j; }
                        if (menor < 80 && d > menor + 300) break;
                    }
                    indice[e.id] = mejor;
                    desde = mejor;
                });
                geo[l.codigo] = {puntos, acumulado, total: acumulado[acumulado.length - 1], indice, circular: l.circular, porCalles,
                    coords: Object.fromEntries(l.estaciones.map((e) => [e.id, [e.lat, e.lon]]))};
            });
            const encuadrar = () => limites.length ? mapa.fitBounds(limites, {padding: [30, 30]}) : mapa.setView([14.6349, -90.5069], 13);
            encuadrar();
            // La estación del operador, resaltada (después de encuadrar: Leaflet necesita la vista para dibujarla).
            if (estacionPropia) {
                L.circleMarker([estacionPropia.lat, estacionPropia.lon], {radius: 11, color: '#162133', weight: 4, fillColor: '#8eb9fc', fillOpacity: 1})
                    .bindTooltip(`Tu estación: ${esc(estacionPropia.nombre)}`, {direction: 'top', offset: [0, -8], permanent: true}).addTo(mapa);
            }
            // La caja del mapa entra con una animación: al terminar se vuelve a medir y encuadrar.
            setTimeout(() => { mapa.invalidateSize(); encuadrar(); }, 900);
            const distancia = () => caja.classList.toggle('lejos', mapa.getZoom() < 14);
            mapa.on('zoomend', distancia);
            distancia();

            const puntoA = (g, d) => {
                d = ((d % g.total) + g.total) % g.total;
                let lo = 0, hi = g.acumulado.length - 1;
                while (lo < hi) { const m = (lo + hi) >> 1; if (g.acumulado[m] < d) lo = m + 1; else hi = m; }
                const i = Math.max(1, lo), a = g.puntos[i - 1], b = g.puntos[i];
                const t = (d - g.acumulado[i - 1]) / Math.max(1e-6, g.acumulado[i] - g.acumulado[i - 1]);
                return [a[0] + (b[0] - a[0]) * t, a[1] + (b[1] - a[1]) * t];
            };

            // Posición del bus: detenido en la estación hasta su hora de salida; después avanza por el trazo hacia la próxima.
            const estado = (b, ahora) => {
                if (!b.hacia || ahora < b.salida) return {p: 0, detenido: true};
                return {p: Math.min(0.96, (ahora - b.salida) / Math.max(1000, b.duracion)), detenido: false};
            };
            const ubicar = (b, p) => {
                const g = geo[b.linea];
                if (!g || !g.coords[b.desde]) return null;
                if (!b.hacia || p === 0) return g.coords[b.desde];
                const lerp = () => { const a = g.coords[b.desde], c = g.coords[b.hacia]; return [a[0] + (c[0] - a[0]) * p, a[1] + (c[1] - a[1]) * p]; };
                if (!g.porCalles) return lerp();
                const da = g.acumulado[g.indice[b.desde]]; let db = g.acumulado[g.indice[b.hacia]];
                if (db < da) { if (!g.circular) return lerp(); db += g.total; }
                return puntoA(g, da + (db - da) * p);
            };
            const texto = (b, e) => e.detenido
                ? `Detenido en ${estaciones[b.desde] ?? ''}` + (b.hacia ? ` · sigue a ${estaciones[b.hacia] ?? ''}` : ' · fin del recorrido')
                : (e.p >= 0.96 ? `Llegando a ${estaciones[b.hacia] ?? ''}` : `En camino a ${estaciones[b.hacia] ?? ''} (${Math.round(e.p * 100)} %)`);

            // ---------- Marcadores de los buses ----------
            const marcas = {};
            const ocultas = new Set();
            const icono = (b, detenido) => L.divIcon({className: '', iconSize: [0, 0],
                html: `<div class="bus-pin${b.propio ? ' propio' : ''}${detenido ? ' detenido' : ''}" style="--c:${esc(b.color)}">${esc(b.placa)}</div>`});

            function sincronizar() {
                const ids = new Set(buses.map((b) => b.id));
                Object.keys(marcas).forEach((id) => { if (!ids.has(id)) { marcas[id].marca.remove(); delete marcas[id]; } });
                buses.forEach((b) => {
                    if (!marcas[b.id]) {
                        const marca = L.marker([0, 0], {zIndexOffset: b.propio ? 1000 : 0}).bindTooltip('', {direction: 'top', offset: [0, -10]});
                        marcas[b.id] = {marca, detenido: null};
                    }
                    marcas[b.id].bus = b;
                });
                document.getElementById('cuenta-buses').textContent = buses.length;
                document.getElementById('hora-actualizacion').textContent = new Date(Date.now() + desfase).toLocaleTimeString('es-GT');
            }

            // Cada cuadro: mueve los buses suavemente sobre su ruta.
            function animar() {
                const ahora = Date.now() + desfase;
                Object.values(marcas).forEach((m) => {
                    const b = m.bus, e = estado(b, ahora), pos = ubicar(b, e.p);
                    if (!pos || ocultas.has(b.linea)) { m.marca.remove(); return; }
                    if (!mapa.hasLayer(m.marca)) m.marca.addTo(mapa);
                    // Se acerca suavemente a su posición (si el bus saltó a otra estación, se desliza en lugar de aparecer).
                    m.visto = m.visto ? [m.visto[0] + (pos[0] - m.visto[0]) * 0.12, m.visto[1] + (pos[1] - m.visto[1]) * 0.12] : pos;
                    m.marca.setLatLng(m.visto);
                    if (m.detenido !== e.detenido) { m.marca.setIcon(icono(b, e.detenido)); m.detenido = e.detenido; }
                });
                requestAnimationFrame(animar);
            }

            // Cada segundo: textos de los marcadores y buses en las listas de cada línea.
            function pintarListas() {
                const ahora = Date.now() + desfase;
                document.querySelectorAll('[data-chips]').forEach((c) => (c.innerHTML = ''));
                document.querySelectorAll('[data-tramo]').forEach((c) => { c.innerHTML = ''; c.classList.add('hidden'); c.classList.remove('flex'); });
                const cuentas = {};
                Object.values(marcas).forEach((m) => {
                    const b = m.bus, e = estado(b, ahora);
                    cuentas[b.linea] = (cuentas[b.linea] ?? 0) + 1;
                    m.marca.setTooltipContent(`<b>${esc(b.placa)}</b> · ${esc(b.linea)}${b.propio ? ' · tu bus' : ''}<br>${esc(texto(b, e))}<br>`
                        + `A bordo ${b.pasajeros}/${b.capacidad} (${b.ocupacion} %)${b.lleno ? ' · <b>LLENO</b>' : ''}<br>`
                        + `En su última parada: ↑ ${b.subieron} subieron · ↓ ${b.bajaron} bajaron · llegó a las ${esc(b.llegada)}`);
                    const chip = `<span class="chip-bus${b.propio ? ' propio' : ''}" style="--c:${esc(b.color)}${b.lleno ? ';outline:2px solid #ef4444;outline-offset:1px' : ''}" data-ir-bus="${esc(b.id)}" data-lleno="${b.lleno ? 1 : 0}" title="${esc(texto(b, e))} · ${b.pasajeros}/${b.capacidad} a bordo${b.lleno ? ' (lleno)' : ''}">${esc(b.placa)}</span>`;
                    if (e.detenido) {
                        document.querySelector(`[data-chips="${b.linea}|${b.desde}"]`)?.insertAdjacentHTML('beforeend', chip);
                    } else {
                        const tramo = document.querySelector(`[data-tramo="${b.linea}|${b.desde}"]`);
                        if (tramo) {
                            if (!tramo.childElementCount) tramo.insertAdjacentHTML('beforeend', '<span>↓ en camino:</span>');
                            tramo.insertAdjacentHTML('beforeend', chip);
                            tramo.classList.remove('hidden'); tramo.classList.add('flex');
                        }
                    }
                });
                document.querySelectorAll('[data-cuenta-linea]').forEach((c) => (c.textContent = cuentas[c.dataset.cuentaLinea] ?? 0));
            }

            // Tocar un bus en la lista: lo muestra en el mapa.
            document.addEventListener('click', (ev) => {
                const chip = ev.target.closest('[data-ir-bus]');
                if (!chip || !marcas[chip.dataset.irBus]) return;
                const m = marcas[chip.dataset.irBus].marca;
                ocultas.delete(marcas[chip.dataset.irBus].bus.linea);
                caja.scrollIntoView({behavior: 'smooth', block: 'center'});
                mapa.flyTo(m.getLatLng(), 16, {duration: 0.8});
                setTimeout(() => m.openTooltip(), 900);
            });

            // Filtro por línea.
            function mostrarLinea(codigo, ver) {
                const boton = document.querySelector(`[data-filtro="${codigo}"]`);
                ver ? ocultas.delete(codigo) : ocultas.add(codigo);
                boton?.setAttribute('aria-pressed', String(ver));
                boton?.classList.toggle('linea-apagada', !ver);
                ver ? capas[codigo]?.addTo(mapa) : capas[codigo]?.remove();
                document.querySelector(`[data-tarjeta="${codigo}"]`)?.classList.toggle('linea-apagada', !ver);
            }
            document.querySelectorAll('[data-filtro]').forEach((boton) => boton.addEventListener('click', () => {
                mostrarLinea(boton.dataset.filtro, ocultas.has(boton.dataset.filtro));
            }));
            // El operador abre el mapa con las líneas de su estación; las demás quedan apagadas (se pueden encender).
            if (propias.length) lineas.forEach((l) => { if (!propias.includes(l.codigo)) mostrarLinea(l.codigo, false); });

            // Botón de la simulación.
            const boton = document.getElementById('boton-simulacion');
            function mostrarSimulacion() {
                if (boton) {
                    boton.textContent = activa ? '⏸ Pausar simulación' : '▶ Iniciar simulación';
                    boton.classList.toggle('btn-primario', !activa);
                    boton.classList.toggle('btn-secundario', activa);
                }
                document.getElementById('punto-vivo').className = 'h-2 w-2 rounded-full ' + (activa ? 'latido bg-emerald-500' : 'bg-niebla');
                document.getElementById('nota-simulacion').textContent = activa ? '· simulación en curso' : '· simulación en pausa: los buses se quedan en su próxima estación';
            }
            boton?.addEventListener('click', async () => {
                boton.disabled = true;
                try {
                    const r = await fetch(URL_SIMULACION, {method: 'POST', body: new URLSearchParams({accion: activa ? 'pausar' : 'iniciar'}),
                        headers: {'X-CSRF-TOKEN': TOKEN, Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest'}});
                    if (r.ok) { activa = (await r.json()).activa; mostrarSimulacion(); clearTimeout(siguiente); actualizar(); }
                } finally { boton.disabled = false; }
            });

            // Cada pocos segundos: con la simulación activa ejecuta un ciclo (los buses que ya llegaron avanzan
            // a su siguiente estación); si no, solo pide las posiciones. Sin recargar la página.
            let siguiente = null;
            async function actualizar() {
                if (!document.hidden) {
                    try {
                        const r = activa
                            ? await fetch(URL_CICLO, {method: 'POST', headers: {'X-CSRF-TOKEN': TOKEN, Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest'}})
                            : await fetch(URL_BUSES, {headers: {Accept: 'application/json'}});
                        if (r.ok) {
                            const d = await r.json();
                            desfase = d.ahora - Date.now(); buses = d.buses;
                            if (typeof d.activa === 'boolean' && d.activa !== activa) { activa = d.activa; mostrarSimulacion(); }
                            sincronizar(); pintarListas();
                        }
                    } catch (e) { /* sin conexión: se mantiene lo último */ }
                }
                siguiente = setTimeout(actualizar, activa ? 4000 : 15000);
            }

            sincronizar();
            pintarListas();
            requestAnimationFrame(animar);
            setInterval(pintarListas, 1000);
            siguiente = setTimeout(actualizar, activa ? 1000 : 15000);
        })();
    </script>
@endpush
