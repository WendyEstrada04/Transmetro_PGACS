{{--
    Pantalla principal del administrador: mapa plano con las rutas por color.
    Al tocar una ruta (en el mapa o en la lista) se desliza a la derecha el panel de esa ruta con
    pasajeros, estaciones, personal (pilotos, operadores y seguridad), buses, alertas y horario.
--}}
@extends('layouts.app')

@section('titulo', 'Inicio')

@push('head')
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
          integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
            integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
@endpush

@section('pantalla_completa')
    @php
        // Para cada línea: color de letra legible sobre blanco (los colores claros se oscurecen).
        $legible = fn ($l) => $l->colorTexto() === '#ffffff' ? $l->color : "color-mix(in srgb, {$l->color} 55%, #162133)";
        $cinta = [
            [number_format($totales['personas']), 'personas esperando en las estaciones'],
            [$totales['busesEnRuta'].' de '.$totales['busesEnLinea'], 'buses en ruta'],
            [number_format($totales['aBordo']), 'pasajeros a bordo de los buses'],
            [number_format($totales['pagosHoy']), 'subieron hoy (pasajes cobrados al subir)'],
            [number_format($totales['bajadasHoy']), 'bajaron hoy (molinete de salida)'],
            [$totales['busesLlenos'], $totales['busesLlenos'] === 1 ? 'bus lleno ahora' : 'buses llenos ahora'],
            [$totales['estaciones'], 'estaciones en la red'],
            [$lineas->count(), 'rutas activas en Guatemala'],
        ];
        foreach ($alertas->take(6) as $a) {
            $cinta[] = ['⚠ '.$a->estacion_nombre, \App\Models\AlertaSistema::TIPOS[$a->tipo_alerta] ?? str_replace('_', ' ', $a->tipo_alerta), true];
        }
    @endphp
    <div id="tablero" class="tablero mapa-plano relative grid h-full overflow-hidden md:grid-cols-[22rem_minmax(0,1fr)]" style="--ancho-panel: 460px">

        {{-- ===== Barra lateral: la red de rutas ===== --}}
        <aside class="relative z-[1001] hidden min-h-0 flex-col border-r border-noche/[0.07] bg-white md:flex">
            <div class="px-6 pb-6 pt-7">
                <p class="rotulo aparecer flex items-center gap-3 text-acero"><span class="h-px w-6 bg-acero"></span>Red de rutas</p>
                <h1 class="mt-3 text-[54px] font-black leading-[0.9] tracking-[-0.05em]">
                    <span class="mascara"><span style="--retraso: 80ms"><span data-contar="{{ $lineas->count() }}" data-contar-ya>{{ $lineas->count() }}</span> rutas</span></span>
                </h1>
                <p class="aparecer mt-3 text-[13px] font-semibold text-acero" style="--retraso: 220ms">
                    {{ $totales['estaciones'] }} estaciones · {{ $totales['busesEnLinea'] }} buses asignados
                </p>
                <p class="rotulo aparecer mt-4 flex items-center gap-2 !text-[10px] text-noche" style="--retraso: 300ms">
                    <span class="latido h-1.5 w-1.5 rounded-full bg-emerald-500"></span><span data-reloj>{{ now()->translatedFormat('D j M · H:i') }}</span>
                </p>
            </div>

            <ul class="min-h-0 flex-1 overflow-y-auto border-t border-noche/[0.07]">
                @foreach ($lineas as $linea)
                    <li class="aparecer" style="--retraso: {{ 250 + $loop->index * 60 }}ms">
                        <button type="button" class="fila-ruta" data-linea="{{ $linea->codigo_linea }}" aria-pressed="false"
                                style="--c: {{ $linea->color }}; --c-legible: {{ $legible($linea) }}">
                            <span class="codigo">{{ $linea->codigo_linea }}</span>
                            <span class="min-w-0">
                                <span class="block truncate text-[14px] font-extrabold tracking-tight">{{ $linea->nombre }}</span>
                                <span class="mt-0.5 flex items-center gap-2 text-[11.5px] font-semibold text-acero">
                                    <span class="h-1.5 w-1.5 rounded-full {{ $linea->enServicio() ? 'bg-emerald-500' : 'bg-niebla' }}"></span>
                                    {{ $linea->estaciones->count() }} estaciones · {{ $linea->buses_count }} buses
                                </span>
                            </span>
                            <svg class="flecha h-5 w-5 text-noche" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M4 10h12M11 5l5 5-5 5"/></svg>
                        </button>
                    </li>
                @endforeach
            </ul>

            <div class="grid grid-cols-2 gap-2 border-t border-noche/[0.07] px-6 py-4">
                <a href="{{ route('admin.reportes.index') }}" class="btn btn-primario col-span-2">Reportes</a>
            </div>
        </aside>

        {{-- ===== Mapa ===== --}}
        <section class="relative min-h-0">
            <div id="mapa-inicio" class="absolute inset-x-0 bottom-12 top-0"></div>

            {{-- Rutas en el teléfono --}}
            <div class="absolute inset-x-3 top-3 z-[1001] flex gap-2 overflow-x-auto pb-1 md:hidden">
                @foreach ($lineas as $linea)
                    <button type="button" data-linea="{{ $linea->codigo_linea }}" aria-pressed="false"
                            class="shrink-0 rounded-full bg-white px-4 py-1.5 text-[13px] font-black shadow-sm"
                            style="color: {{ $legible($linea) }}">{{ $linea->codigo_linea }}</button>
                @endforeach
            </div>

            @if ($simulacionActiva)
                <a href="{{ route('admin.simulacion.index') }}"
                   class="aparecer absolute left-5 top-5 z-[1001] hidden items-center gap-2.5 rounded-full bg-noche px-4 py-2 text-[12px] font-extrabold text-white shadow-[0_16px_30px_-16px_rgba(22,33,51,0.8)] transition-transform hover:-translate-y-0.5 md:flex">
                    <span class="latido h-2 w-2 rounded-full bg-emerald-400"></span>
                    Simulación en vivo
                </a>
            @endif

            {{-- Cinta “en vivo” con los datos de la red --}}
            <div class="absolute inset-x-0 bottom-0 z-[1001] flex h-12 items-stretch bg-noche text-white">
                <span class="rotulo flex shrink-0 items-center gap-2 bg-cielo px-5 !text-[10px] text-noche">
                    <span class="latido h-1.5 w-1.5 rounded-full bg-red-500"></span>En vivo
                </span>
                <div class="cinta flex-1">
                    <div class="cinta-pista h-full items-center">
                        @foreach ([1, 2] as $copia)
                            @foreach ($cinta as $dato)
                                <span class="flex items-center gap-2 whitespace-nowrap px-7 text-[13px]" @if ($copia === 2) aria-hidden="true" @endif>
                                    <b class="font-black {{ ($dato[2] ?? false) ? 'text-red-300' : 'text-cielo' }}">{{ $dato[0] }}</b>
                                    <span class="text-niebla">{{ $dato[1] }}</span>
                                    <span class="ml-7 h-1 w-1 rounded-full bg-acero"></span>
                                </span>
                            @endforeach
                        @endforeach
                    </div>
                </div>
                <div class="relative shrink-0">
                    <button type="button" id="boton-alertas" class="flex h-full items-center gap-2.5 border-l border-white/10 px-5 text-[12px] font-extrabold transition-colors hover:bg-white/10" {{ $alertas->isEmpty() ? 'disabled' : '' }}>
                        <span class="flex h-6 min-w-6 items-center justify-center rounded-full px-1.5 text-[11px] {{ $totales['alertasAbiertas'] ? 'latido bg-red-500' : 'bg-white/15' }}">{{ $totales['alertasAbiertas'] }}</span>
                        Alertas
                    </button>
                    <div id="lista-alertas" hidden class="cascada absolute bottom-14 right-3 max-h-80 w-80 overflow-y-auto rounded-xl bg-white text-noche shadow-[0_30px_60px_-25px_rgba(22,33,51,0.6)]">
                        <p class="rotulo bg-papel px-5 py-3 text-acero">Alertas abiertas</p>
                        @foreach ($alertas as $alerta)
                            <button type="button" data-ir-estacion="{{ $alerta->id_estacion }}" class="menu-item w-full text-left" style="--i: {{ min($loop->index, 8) }}">
                                <span>
                                    <span class="block text-[15px] font-extrabold">{{ $alerta->estacion_nombre }}</span>
                                    <span class="block text-[11.5px] text-acero">{{ \App\Models\AlertaSistema::TIPOS[$alerta->tipo_alerta] ?? str_replace('_', ' ', $alerta->tipo_alerta) }} · {{ $alerta->fecha_creacion->format('d/m H:i') }}</span>
                                </span>
                                <svg class="flecha h-4 w-4 shrink-0" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M4 10h12M11 5l5 5-5 5"/></svg>
                            </button>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- Panel de la ruta elegida (cortina desde la derecha) --}}
            <aside id="panel-linea" aria-label="Información de la ruta"
                   class="panel-linea absolute bottom-12 right-0 top-0 z-[1002] w-[460px] max-w-full overflow-hidden bg-white shadow-[-40px_0_80px_-40px_rgba(22,33,51,0.55)]">
                <div id="panel-contenido" class="h-full"></div>
            </aside>
        </section>
    </div>
@endsection

@push('scripts')
    <script>
        (() => {
            const lineas = @json($lineasMapa);
            const simulacionActiva = @json($simulacionActiva);
            const tablero = document.getElementById('tablero');
            const panel = document.getElementById('panel-linea');
            const contenido = document.getElementById('panel-contenido');
            const esc = (t) => String(t ?? '').replace(/[&<>"']/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
            const espera = (ms) => new Promise((r) => setTimeout(r, ms));
            const escritorio = () => window.innerWidth >= 768;
            const margenIzq = () => 40;
            const margenDer = () => (tablero.classList.contains('panel-abierto') && escritorio() ? panel.offsetWidth + 24 : 20);

            // ---------- Mapa plano ----------
            // Se le da una vista desde el inicio para que las rutas se dibujen al agregarlas.
            const mapa = L.map('mapa-inicio', {zoomControl: false, zoomSnap: 0.25, zoomDelta: 0.5, wheelPxPerZoomLevel: 90})
                .setView([14.6349, -90.5069], 12);
            L.control.zoom({position: 'bottomright'}).addTo(mapa);
            // Capas propias: zonas para tocar las rutas (sobre las líneas) y estaciones (encima de todo).
            mapa.createPane('zonas').style.zIndex = 450;
            mapa.createPane('paradas').style.zIndex = 460;
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
            }).addTo(mapa);

            // Botón para dejar el fondo totalmente liso (solo las rutas) o con las calles tenues.
            const leer = (k) => { try { return localStorage.getItem(k); } catch { return null; } };
            const guardar = (k, v) => { try { localStorage.setItem(k, v); } catch {} };
            const BotonCalles = L.Control.extend({
                onAdd() {
                    const b = L.DomUtil.create('button', 'rounded-full bg-white px-4 py-2 text-[12px] font-extrabold text-noche shadow-[0_14px_28px_-16px_rgba(22,33,51,0.7)] transition-colors duration-300 hover:bg-noche hover:text-white');
                    b.type = 'button';
                    const poner = (sin) => { tablero.classList.toggle('sin-calles', sin); b.textContent = sin ? 'Mostrar calles' : 'Ocultar calles'; };
                    poner(leer('tm.sinCalles') === '1');
                    L.DomEvent.disableClickPropagation(b);
                    b.addEventListener('click', () => { const sin = !tablero.classList.contains('sin-calles'); poner(sin); guardar('tm.sinCalles', sin ? '1' : '0'); });
                    return b;
                },
            });
            new BotonCalles({position: 'topright'}).addTo(mapa);

            // Capa con las estaciones de la ruta elegida (se llena al elegir una ruta).
            const paradas = L.layerGroup().addTo(mapa);
            let marcas = {};

            // ---------- Rutas por color ----------
            const rutas = {};
            const todos = [];
            const grosor = () => { const z = mapa.getZoom() ?? 13; return z >= 16 ? 4 : (z >= 14 ? 5 : 6); };
            lineas.forEach((l) => {
                let puntos = l.trazo;
                const porCalles = puntos.length >= 2;
                if (!porCalles) {
                    puntos = l.estaciones.map((e) => [e.lat, e.lon]);
                    if (l.circular && puntos.length > 2) puntos.push(puntos[0]);
                }
                const estilo = {lineCap: 'round', lineJoin: 'round'};
                const borde = L.polyline(puntos, {...estilo, color: '#ffffff', weight: grosor() + 5, opacity: 1, interactive: false}).addTo(mapa);
                const trazo = L.polyline(puntos, {...estilo, color: l.color, weight: grosor(), opacity: 1, dashArray: porCalles ? null : '1 10', interactive: false}).addTo(mapa);
                // Zona invisible más ancha para que la ruta sea fácil de tocar.
                const zona = L.polyline(puntos, {color: '#000', weight: 20, opacity: 0, pane: 'zonas'}).addTo(mapa)
                    .bindTooltip(`<b>${esc(l.codigo)}</b> · ${esc(l.nombre)}`, {sticky: true, direction: 'top', offset: [0, -8]});
                // Al pasar el puntero: solo esa ruta resalta y solo se ve su etiqueta.
                zona.on('mouseover', () => {
                    Object.values(rutas).forEach((r) => r.zona !== zona && r.zona.closeTooltip());
                    encima = l.codigo; pintar(); precargar(l.codigo);
                });
                zona.on('mouseout', () => { zona.closeTooltip(); encima = null; pintar(); });
                zona.on('click', (e) => { L.DomEvent.stop(e); seleccionar(l.codigo); });
                rutas[l.codigo] = {l, borde, trazo, zona, puntos};
                todos.push(...puntos);
            });

            let actual = null;
            let encima = null;
            // Resalta la ruta elegida o la que está bajo el puntero; las demás se apagan.
            // (Las zonas para tocar y las estaciones van en capas propias, así que no hace falta
            // moverlas: moverlas mientras el puntero está encima dejaba etiquetas abiertas.)
            function pintar() {
                const resaltadas = [actual, encima].filter(Boolean);
                Object.entries(rutas).forEach(([codigo, r]) => {
                    const destacada = resaltadas.includes(codigo);
                    const apagada = resaltadas.length > 0 && !destacada;
                    const w = grosor() + (codigo === encima ? 3 : (destacada ? 2 : 0));
                    r.trazo.setStyle({weight: w, opacity: apagada ? 0.15 : 1});
                    r.borde.setStyle({weight: w + 5, opacity: apagada ? 0.25 : 1});
                });
                [actual, encima].forEach((c) => { if (c) { rutas[c].borde.bringToFront(); rutas[c].trazo.bringToFront(); } });
                document.querySelectorAll('[data-linea]').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.linea === actual)));
            }
            mapa.on('zoomend', pintar);

            // ---------- Estaciones de la ruta elegida ----------
            function dibujarParadas(codigo) {
                paradas.clearLayers();
                marcas = {};
                if (!codigo) return;
                const l = rutas[codigo].l;
                l.estaciones.forEach((e) => {
                    const m = L.circleMarker([e.lat, e.lon], {pane: 'paradas', radius: 6, color: '#162133', weight: 3, fillColor: '#ffffff', fillOpacity: 1})
                        .bindTooltip(esc(e.nombre), {direction: 'top', offset: [0, -6]})
                        .on('click', (ev) => { L.DomEvent.stop(ev); enfocarEstacion(e.id, false); })
                        .addTo(paradas);
                    marcas[e.id] = {m, e};
                });
            }
            function colorearParadas(estados) {
                estados.forEach((s) => {
                    const x = marcas[s.id];
                    if (!x) return;
                    const llena = s.alerta || s.pct >= 150;
                    x.m.setStyle({color: llena ? '#ef4444' : '#162133', fillColor: llena ? '#fecaca' : (s.pct >= 100 ? '#fde68a' : '#ffffff')});
                    x.m.setRadius(llena ? 8 : 6);
                });
            }
            function enfocarEstacion(id, desdePanel) {
                const x = marcas[id];
                if (!x) return;
                const z = Math.max(mapa.getZoom(), 16);
                const centro = mapa.project([x.e.lat, x.e.lon], z).add([(margenDer() - margenIzq()) / 2, 0]);
                mapa.flyTo(mapa.unproject(centro, z), z, {duration: 0.8});
                setTimeout(() => x.m.openTooltip(), 500);
                if (!desdePanel) {
                    mostrarSeccion('estaciones');
                    const fila = contenido.querySelector(`[data-seccion="estaciones"] [data-estacion="${CSS.escape(id)}"]`);
                    if (fila) {
                        fila.scrollIntoView({block: 'center', behavior: 'smooth'});
                        fila.classList.add('bg-cielo/25');
                        setTimeout(() => fila.classList.remove('bg-cielo/25'), 1600);
                    }
                }
            }

            // ---------- Panel deslizable ----------
            const cache = new Map();
            let pedido = 0;
            let seccion = 'resumen';

            async function seleccionar(codigo) {
                if (!rutas[codigo]) return;
                if (codigo === actual) return cerrar();
                const yaAbierto = tablero.classList.contains('panel-abierto');
                actual = codigo;
                encima = null;
                dibujarParadas(codigo);
                pintar();
                history.replaceState(null, '', '#' + codigo);
                tablero.classList.add('panel-abierto');
                mapa.flyToBounds(rutas[codigo].puntos, {paddingTopLeft: [margenIzq(), 50], paddingBottomRight: [margenDer(), 50], duration: 0.8});
                await cargarPanel(codigo, yaAbierto);
            }

            function cerrar() {
                actual = null;
                pedido++;
                tablero.classList.remove('panel-abierto');
                dibujarParadas(null);
                pintar();
                history.replaceState(null, '', location.pathname + location.search);
                mapa.flyToBounds(todos, {paddingTopLeft: [margenIzq(), 40], paddingBottomRight: [40, 40], duration: 0.8});
            }

            // Trae el panel de una ruta. Se guarda en memoria para que abrirla otra vez sea instantáneo.
            const pendientes = new Map();
            function pedirPanel(codigo) {
                if (pendientes.has(codigo)) return pendientes.get(codigo);
                const promesa = fetch(rutas[codigo].l.panel, {
                    headers: {'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html'},
                    credentials: 'same-origin',
                    redirect: 'manual',
                }).then(async (r) => {
                    if (r.type === 'opaqueredirect' || r.status === 401 || r.status === 419) throw new Error('sesion');
                    if (!r.ok) throw new Error('HTTP ' + r.status);
                    const html = await r.text();
                    if (!html.includes('data-panel=')) throw new Error('respuesta inesperada');
                    cache.set(codigo, {html, t: Date.now()});
                    return html;
                }).finally(() => pendientes.delete(codigo));
                pendientes.set(codigo, promesa);
                return promesa;
            }
            const fresco = (codigo, ms = 20000) => cache.has(codigo) && Date.now() - cache.get(codigo).t < ms;
            const precargar = (codigo) => (fresco(codigo, 60000) ? null : pedirPanel(codigo).catch(() => null));

            async function cargarPanel(codigo, cambio, silencioso = false) {
                const n = ++pedido;
                const l = rutas[codigo].l;
                const guardado = cache.get(codigo)?.html;
                if (!silencioso) {
                    if (cambio) contenido.firstElementChild?.classList.add('saliendo');
                    await espera(cambio ? 220 : 0);
                    if (n !== pedido) return;
                    guardado ? montar(guardado) : (contenido.innerHTML = esqueleto(l));
                    // Si lo que hay en memoria es reciente no hace falta volver a pedirlo.
                    if (guardado && fresco(codigo)) return;
                }
                try {
                    const html = await pedirPanel(codigo);
                    if (n !== pedido || actual !== codigo) return;
                    if (html !== guardado) montar(html, Boolean(guardado) || silencioso);
                } catch (e) {
                    console.error('Panel de la ruta', codigo, e);
                    if (n === pedido && !silencioso && !guardado) {
                        const sesion = e.message === 'sesion';
                        contenido.innerHTML = `<div class="flex h-full flex-col items-center justify-center gap-4 p-10 text-center text-[14px] text-acero">
                            <p class="text-[44px] font-black tracking-[-0.04em] text-noche">${esc(l.codigo)}</p>
                            <p>${sesion ? 'Tu sesión terminó. Vuelve a entrar para ver la información.' : 'No se pudo cargar la información de esta ruta (' + esc(e.message) + ').'}</p>
                            ${sesion ? `<a href="{{ route('login') }}" class="btn btn-primario btn-chico">Entrar de nuevo</a>` : '<button type="button" data-reintentar class="btn btn-secundario btn-chico">Reintentar</button>'}</div>`;
                    }
                }
            }

            // Pone el HTML del panel. “quieto” = actualización: sin animación y conservando sección y desplazamiento.
            function montar(html, quieto = false) {
                const antes = contenido.querySelector('[data-desplazable]')?.scrollTop ?? 0;
                contenido.innerHTML = html;
                const raiz = contenido.firstElementChild;
                if (!raiz) return;
                raiz.classList.toggle('quieto', quieto);
                mostrarSeccion(seccion, false);
                if (quieto) raiz.querySelector('[data-desplazable]').scrollTop = antes;
                colorearParadas(JSON.parse(raiz.dataset.estaciones || '[]'));
                if (!quieto) window.tmContar?.(raiz.querySelector('[data-seccion="resumen"]'));
            }

            function mostrarSeccion(nombre, animar = true) {
                const raiz = contenido.firstElementChild;
                if (!raiz?.dataset.panel) return;
                seccion = nombre;
                if (animar) raiz.classList.remove('quieto');
                raiz.querySelectorAll('[data-seccion]').forEach((s) => {
                    const ver = s.dataset.seccion === nombre;
                    if (ver && animar && s.hidden) {
                        // Reinicia la animación en cascada de la sección que entra.
                        [s, ...s.querySelectorAll('.cascada')].filter((c) => c.classList.contains('cascada')).forEach((c) => {
                            c.classList.remove('cascada'); void c.offsetWidth; c.classList.add('cascada');
                        });
                    }
                    s.hidden = !ver;
                });
                if (animar) raiz.querySelector('[data-desplazable]').scrollTop = 0;
                const botones = raiz.querySelectorAll('[data-ir]');
                const indicador = raiz.querySelector('.pestanas .indicador');
                botones.forEach((b) => {
                    const activo = b.dataset.ir === nombre;
                    b.setAttribute('aria-selected', String(activo));
                    if (activo && indicador) {
                        if (!animar) indicador.style.transition = 'none';
                        indicador.style.left = b.offsetLeft + 'px';
                        indicador.style.width = b.offsetWidth + 'px';
                        if (!animar) { void indicador.offsetWidth; indicador.style.transition = ''; }
                    }
                });
            }

            function esqueleto(l) {
                return `<div class="flex h-full flex-col bg-white">
                    <div class="px-7 pb-6 pt-5" style="background:${esc(l.color)};color:${esc(l.texto)}">
                        <p class="rotulo opacity-70">Línea</p>
                        <p class="mt-1 text-[104px] font-black leading-[0.82] tracking-[-0.065em]"><span class="mascara"><span>${esc(l.codigo)}</span></span></p>
                        <p class="mt-4 text-[21px] font-extrabold leading-tight">${esc(l.nombre)}</p>
                        <div class="mt-4 h-3 w-40 rounded-full bg-current opacity-20"></div>
                    </div>
                    <div class="space-y-4 px-7 py-6">
                        <div class="grid grid-cols-2 gap-4">${'<div class="esqueleto h-20 rounded-md"></div>'.repeat(4)}</div>
                        <div class="esqueleto h-32 rounded-md"></div><div class="esqueleto h-16 rounded-md"></div>
                    </div></div>`;
            }

            // Reloj de la barra lateral.
            const reloj = document.querySelector('[data-reloj]');
            if (reloj) {
                const formato = new Intl.DateTimeFormat('es-GT', {weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit'});
                const tic = () => (reloj.textContent = formato.format(new Date()).replace(',', ' ·'));
                tic();
                setInterval(tic, 20000);
            }

            contenido.addEventListener('click', (e) => {
                const ir = e.target.closest('[data-ir]');
                if (ir) return mostrarSeccion(ir.dataset.ir);
                if (e.target.closest('[data-cerrar]')) return cerrar();
                if (e.target.closest('[data-reintentar]') && actual) { cache.delete(actual); return cargarPanel(actual, false); }
                const est = e.target.closest('[data-estacion]');
                if (est) enfocarEstacion(est.dataset.estacion, true);
            });

            // Lista de rutas: clic para abrir, pasar el mouse para resaltar en el mapa.
            document.querySelectorAll('[data-linea]').forEach((b) => {
                b.addEventListener('click', () => seleccionar(b.dataset.linea));
                b.addEventListener('mouseenter', () => { encima = b.dataset.linea; pintar(); precargar(b.dataset.linea); });
                b.addEventListener('mouseleave', () => { encima = null; pintar(); });
            });

            // Alertas del resumen: abre la ruta de la estación y la señala.
            const listaAlertas = document.getElementById('lista-alertas');
            document.getElementById('boton-alertas')?.addEventListener('click', () => {
                listaAlertas.hidden = !listaAlertas.hidden;
                if (!listaAlertas.hidden) { listaAlertas.classList.remove('cascada'); void listaAlertas.offsetWidth; listaAlertas.classList.add('cascada'); }
            });
            listaAlertas?.addEventListener('click', async (e) => {
                const b = e.target.closest('[data-ir-estacion]');
                if (!b) return;
                const id = b.dataset.irEstacion;
                const l = lineas.find((x) => x.estaciones.some((s) => s.id === id));
                listaAlertas.hidden = true;
                if (!l) return;
                if (actual !== l.codigo) await seleccionar(l.codigo);
                setTimeout(() => enfocarEstacion(id, false), 300);
            });

            mapa.on('click', () => actual && cerrar());
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && actual && !document.querySelector('details.menu-gestion[open]')) cerrar();
            });

            // Vista inicial: todas las rutas (o la que venga en la dirección, p. ej. /admin#L12).
            mapa.fitBounds(todos.length ? todos : [[14.6349, -90.5069]], {paddingTopLeft: [margenIzq(), 40], paddingBottomRight: [40, 40]});
            pintar();
            const deLaDireccion = decodeURIComponent(location.hash.slice(1));
            if (rutas[deLaDireccion]) setTimeout(() => seleccionar(deLaDireccion), 250);
            // Si se escribe otra ruta en la dirección (o se usa Atrás/Adelante), se abre esa ruta.
            window.addEventListener('hashchange', () => {
                const codigo = decodeURIComponent(location.hash.slice(1));
                if (rutas[codigo]) { if (codigo !== actual) seleccionar(codigo); } else if (actual) cerrar();
            });

            // Precarga en segundo plano los paneles de todas las rutas, una por una, para que abrirlas sea inmediato.
            // Con la simulación en marcha el panel abierto se refresca solo cada 10 s.
            if (simulacionActiva) {
                setInterval(() => { if (actual && !document.hidden) cargarPanel(actual, false, true); }, 10000);
            }
        })();
    </script>
@endpush
