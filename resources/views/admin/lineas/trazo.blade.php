@extends('layouts.app')

@section('titulo', 'Trazar ruta · Línea '.$linea->codigo_linea)

@push('head')
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
          integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
            integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <style>
        .tm-parada { display: flex; align-items: center; justify-content: center; width: 26px; height: 26px; border-radius: 9999px;
            background: #fff; border: 3px solid var(--color-linea); font: 700 12px/1 sans-serif; color: #111827; box-shadow: 0 1px 3px rgb(0 0 0 / .35); }
        .tm-mover .tm-parada { cursor: move; outline: 3px dashed #d97706; outline-offset: 2px; }
    </style>
@endpush

@section('contenido')
    <x-titulo :titulo="'Trazar ruta · Línea '.$linea->codigo_linea"
              :subtitulo="$linea->nombre"
              :volver="route('admin.lineas.show', $linea)" />

    {{-- Opción recomendada: copiar el recorrido del mapa oficial (Google My Maps de muniguate.com) --}}
    <div class="caja mb-4 border-l-4 border-tm-600 text-sm leading-relaxed">
        <p class="mb-1 font-semibold text-tm-900">Recomendado: copiar la ruta del mapa oficial (queda idéntica)</p>
        <ol class="mb-3 list-decimal space-y-1 pl-5 text-slate-600">
            <li>Abre la página de la línea en <b>muniguate.com/movilidadurbana</b> y, en el mapa, haz clic en el botón de
                <b>pantalla completa</b> (arriba a la derecha) para abrirlo en Google My Maps.</li>
            <li>En Google My Maps abre el menú <b>⋮</b> (junto al título del mapa) → <b>Descargar KML</b> →
                deja "Todo el mapa" → <b>Descargar</b>. Se guarda un archivo <b>.kmz</b> en tu carpeta Descargas.</li>
            <li>Elige ese archivo aquí abajo y presiona <b>Importar mapa oficial</b>: se copian el recorrido completo y la
                ubicación exacta de cada estación. Luego revisa y, si algo falta, corrígelo con las herramientas de abajo.</li>
        </ol>
        <form method="POST" action="{{ route('admin.lineas.trazo.importar', $linea) }}" enctype="multipart/form-data"
              class="flex flex-wrap items-center gap-3">
            @csrf
            <input type="file" name="kml" accept=".kml,.kmz" required
                   class="text-sm file:mr-3 file:rounded-md file:border-0 file:bg-tm-100 file:px-3 file:py-1.5 file:text-tm-800">
            <button type="submit" class="btn btn-primario btn-chico">Importar mapa oficial</button>
            @if ($linea->tieneTrazo())
                <span class="text-xs text-amber-700">Reemplaza el trazo que ya está guardado.</span>
            @endif
        </form>
    </div>

    <div class="mb-4 grid gap-4 lg:grid-cols-3">
        <div class="caja text-sm leading-relaxed lg:col-span-2">
            <p class="mb-1 font-semibold text-tm-900">O dibujarla a mano</p>
            <ol class="list-decimal space-y-1 pl-5 text-slate-600">
                <li>Abre en otra pestaña el mapa oficial de la línea (muniguate.com/movilidadurbana) para seguir el mismo recorrido.</li>
                <li>Con <b>Dibujar ruta</b> activo, haz clic en la estación de inicio (número 1) y luego ve haciendo clic
                    sobre las calles por donde pasa el bus, en orden. Cada tramo se ajusta solo a las calles.</li>
                <li>Haz un clic en <b>cada esquina donde el bus da vuelta</b> (no clics lejanos): así el tramo no se va por otras calles.
                    Si un tramo toma otra calle, usa <b>Deshacer tramo</b> y haz clic más cerca, o cambia a <b>Línea recta</b>.
                    (Si abres un trazo ya guardado, <b>Deshacer tramo</b> lo quita completo.)</li>
                <li>Si una estación está mal ubicada, cambia a <b>Mover estaciones</b> y arrástrala a su esquina.</li>
                <li>Al terminar usa <b>Cerrar recorrido</b> (rutas circulares) y <b>Guardar</b>.</li>
            </ol>
        </div>
        <div class="caja text-sm">
            <p class="mb-2 font-semibold text-tm-900">Cómo se ajusta cada tramo</p>
            <label class="mb-1 flex items-start gap-2"><input type="radio" name="ajuste" value="carro" class="mt-1">
                <span><b>Por las calles, respetando el sentido de la vía</b></span></label>
            <label class="mb-1 flex items-start gap-2"><input type="radio" name="ajuste" value="pie" checked class="mt-1">
                <span><b>Por las calles, sin importar el sentido</b> <span class="text-slate-500">(recomendado: el Transmetro usa carriles exclusivos)</span></span></label>
            <label class="flex items-start gap-2"><input type="radio" name="ajuste" value="recto" class="mt-1">
                <span><b>Línea recta</b> <span class="text-slate-500">(tramos cortos que el mapa no conoce)</span></span></label>
            <p class="mt-3 border-t border-slate-100 pt-2 text-slate-600">
                Puntos: <b id="info-puntos">0</b> · Largo del trazo: <b id="info-km">0.00</b> km
            </p>
            <p id="info-estado" class="mt-1 text-xs text-slate-500" aria-live="polite"></p>
        </div>
    </div>

    <div class="mb-3 flex flex-wrap items-center gap-2">
        <div class="inline-flex overflow-hidden rounded-md border border-slate-300" role="group">
            <button type="button" id="modo-dibujar" class="btn-chico bg-tm-700 px-3 py-1.5 text-sm text-white">Dibujar ruta</button>
            <button type="button" id="modo-mover" class="btn-chico bg-white px-3 py-1.5 text-sm text-slate-700">Mover estaciones</button>
        </div>
        <button type="button" id="btn-deshacer" class="btn btn-secundario btn-chico">Deshacer tramo</button>
        <button type="button" id="btn-cerrar" class="btn btn-secundario btn-chico">Cerrar recorrido</button>
        <button type="button" id="btn-borrar" class="btn btn-peligro btn-chico">Borrar trazo</button>

        <form method="POST" action="{{ route('admin.lineas.trazo.update', $linea) }}" id="form-trazo" class="ml-auto">
            @csrf
            @method('PUT')
            <input type="hidden" name="trazo" id="campo-trazo">
            <input type="hidden" name="estaciones" id="campo-estaciones">
            <button type="submit" class="btn btn-primario">Guardar trazo</button>
        </form>
    </div>

    @if ($errors->any())
        <div class="mb-3 rounded-md bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</div>
    @endif

    <div class="overflow-hidden rounded-lg bg-white shadow-sm">
        <div id="mapa-trazo" class="h-[70vh] w-full"></div>
    </div>
    <p class="mt-2 text-xs text-slate-500">
        Mapa © colaboradores de OpenStreetMap. Ajuste a calles: servicio de rutas OSRM (router.project-osrm.org y routing.openstreetmap.de), requiere internet.
    </p>
@endsection

@push('scripts')
    <script>
        (() => {
            const color = @json($linea->color);
            const circular = @json((bool) $linea->ruta_circular);
            const estaciones = @json($estaciones);
            const trazoGuardado = @json($linea->tieneTrazo() ? $linea->trazo : []);

            const SERVICIOS = {
                carro: (a, b) => `https://router.project-osrm.org/route/v1/driving/${a[1]},${a[0]};${b[1]},${b[0]}?overview=full&geometries=geojson`,
                pie: (a, b) => `https://routing.openstreetmap.de/routed-foot/route/v1/foot/${a[1]},${a[0]};${b[1]},${b[0]}?overview=full&geometries=geojson`,
            };

            const $ = (id) => document.getElementById(id);
            const esc = (t) => String(t ?? '').replace(/[&<>"']/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
            const redondear = (p) => [Math.round(p[0] * 1e6) / 1e6, Math.round(p[1] * 1e6) / 1e6];

            // ---------- Mapa ----------
            const mapa = L.map('mapa-trazo');
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
            }).addTo(mapa);
            L.control.scale({imperial: false}).addTo(mapa);

            // ---------- Trazo: lista de tramos, cada tramo es una lista de puntos [lat, lon] ----------
            let tramos = trazoGuardado.length ? [trazoGuardado] : [];
            let cambios = false;
            let ocupado = false;
            const linea = L.polyline([], {color, weight: 6, opacity: 0.9}).addTo(mapa);
            const inicio = L.circleMarker([0, 0], {radius: 5, color: '#111827', fillColor: color, fillOpacity: 1});

            const puntos = () => tramos.flat();
            const ultimo = () => { const p = puntos(); return p.length ? p[p.length - 1] : null; };

            const km = (p) => {
                let total = 0;
                for (let i = 1; i < p.length; i++) {
                    const [la1, lo1] = p[i - 1].map((v) => v * Math.PI / 180);
                    const [la2, lo2] = p[i].map((v) => v * Math.PI / 180);
                    const h = Math.sin((la2 - la1) / 2) ** 2 + Math.cos(la1) * Math.cos(la2) * Math.sin((lo2 - lo1) / 2) ** 2;
                    total += 2 * 6371 * Math.asin(Math.sqrt(h));
                }
                return total;
            };

            const dibujar = () => {
                const p = puntos();
                linea.setLatLngs(p);
                p.length ? inicio.setLatLng(p[0]).addTo(mapa) : inicio.remove();
                $('info-puntos').textContent = p.length;
                $('info-km').textContent = km(p).toFixed(2);
            };

            const estado = (texto) => { $('info-estado').textContent = texto; };

            // Calcula un tramo de "a" a "b" siguiendo las calles (o en línea recta).
            const calcularTramo = async (a, b) => {
                const ajuste = document.querySelector('input[name="ajuste"]:checked').value;
                if (ajuste === 'recto') return [a, b];
                try {
                    const respuesta = await fetch(SERVICIOS[ajuste](a, b));
                    const datos = await respuesta.json();
                    if (datos.code !== 'Ok' || !datos.routes?.length) throw new Error(datos.code);
                    const camino = datos.routes[0].geometry.coordinates.map(([lon, lat]) => redondear([lat, lon]));
                    return [a, ...camino, b];
                } catch (e) {
                    estado('No se pudo ajustar a las calles (sin internet o servicio ocupado). Se usó línea recta en este tramo.');
                    return [a, b];
                }
            };

            const agregarPunto = async (destino) => {
                if (ocupado) return;
                destino = redondear(destino);
                const desde = ultimo();
                if (!desde) {
                    tramos.push([destino]);
                } else {
                    ocupado = true;
                    estado('Calculando el tramo por las calles…');
                    const tramo = await calcularTramo(desde, destino);
                    tramos.push(tramo.slice(1)); // el primer punto ya está al final del trazo
                    ocupado = false;
                    if ($('info-estado').textContent.startsWith('Calculando')) estado('');
                }
                cambios = true;
                dibujar();
            };

            // ---------- Estaciones (marcas numeradas) ----------
            let modo = 'dibujar';
            const ubicaciones = {}; // estaciones movidas: id => [lat, lon]
            const marcas = estaciones.map((e) => {
                const marca = L.marker([e.lat, e.lon], {
                    draggable: false,
                    icon: L.divIcon({
                        className: '',
                        html: `<div class="tm-parada" style="--color-linea:${esc(color)}">${e.orden}</div>`,
                        iconSize: [26, 26], iconAnchor: [13, 13],
                    }),
                    title: `${e.orden}. ${e.nombre}`,
                }).bindTooltip(`${e.orden}. ${esc(e.nombre)}` + (e.direccion ? `<br><small>${esc(e.direccion)}</small>` : ''))
                    .addTo(mapa);

                // En modo dibujar, un clic en la estación agrega un punto justo en ella.
                marca.on('click', () => { if (modo === 'dibujar') agregarPunto([marca.getLatLng().lat, marca.getLatLng().lng]); });
                marca.on('dragend', () => {
                    const {lat, lng} = marca.getLatLng();
                    ubicaciones[e.id] = redondear([lat, lng]);
                    cambios = true;
                    estado(`Estación "${e.nombre}" movida. Recuerda guardar.`);
                });

                return marca;
            });

            mapa.on('click', (ev) => { if (modo === 'dibujar') agregarPunto([ev.latlng.lat, ev.latlng.lng]); });

            const cambiarModo = (nuevo) => {
                modo = nuevo;
                marcas.forEach((m) => nuevo === 'mover' ? m.dragging.enable() : m.dragging.disable());
                document.getElementById('mapa-trazo').classList.toggle('tm-mover', nuevo === 'mover');
                const activo = 'btn-chico bg-tm-700 px-3 py-1.5 text-sm text-white';
                const inactivo = 'btn-chico bg-white px-3 py-1.5 text-sm text-slate-700';
                $('modo-dibujar').className = nuevo === 'dibujar' ? activo : inactivo;
                $('modo-mover').className = nuevo === 'mover' ? activo : inactivo;
                estado(nuevo === 'mover' ? 'Arrastra las estaciones a su ubicación correcta.' : 'Haz clic sobre las calles en el orden del recorrido.');
            };
            $('modo-dibujar').addEventListener('click', () => cambiarModo('dibujar'));
            $('modo-mover').addEventListener('click', () => cambiarModo('mover'));

            // ---------- Botones ----------
            $('btn-deshacer').addEventListener('click', () => { if (!ocupado && tramos.length) { tramos.pop(); cambios = true; dibujar(); } });
            $('btn-borrar').addEventListener('click', () => {
                if (tramos.length && confirm('¿Borrar todo el trazo dibujado?')) { tramos = []; cambios = true; dibujar(); }
            });
            $('btn-cerrar').addEventListener('click', () => {
                const p = puntos();
                if (p.length < 2) return estado('Primero dibuja el recorrido.');
                agregarPunto(p[0]);
            });

            $('form-trazo').addEventListener('submit', (ev) => {
                const p = puntos();
                if (p.length === 1) { ev.preventDefault(); return estado('Dibuja al menos un tramo antes de guardar.'); }
                $('campo-trazo').value = JSON.stringify(p);
                $('campo-estaciones').value = JSON.stringify(ubicaciones);
                cambios = false;
            });
            window.addEventListener('beforeunload', (ev) => { if (cambios) { ev.preventDefault(); ev.returnValue = ''; } });

            // ---------- Vista inicial ----------
            dibujar();
            const limites = [...puntos(), ...estaciones.map((e) => [e.lat, e.lon])];
            limites.length ? mapa.fitBounds(limites, {padding: [40, 40]}) : mapa.setView([14.6349, -90.5069], 14);
            if (!trazoGuardado.length && circular) estado('Empieza haciendo clic en la estación 1.');
        })();
    </script>
@endpush
