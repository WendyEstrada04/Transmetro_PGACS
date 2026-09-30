{{--
    Mapa de rutas (Leaflet + OpenStreetMap).
    Variables: $lineasMapa (MapaController::datos), $idMapa, $alto (clase de altura), $resaltar (id de estación, opcional).
--}}
@once
    @push('head')
        <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
              integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
        <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
                integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    @endpush
@endonce

<div class="overflow-hidden rounded-lg bg-white shadow-sm">
    <div id="{{ $idMapa }}" class="{{ $alto ?? 'h-[70vh]' }} w-full"></div>
    <div class="flex flex-wrap items-center gap-3 border-t border-slate-100 px-4 py-2 text-xs text-slate-600" data-leyenda="{{ $idMapa }}">
        @foreach ($lineasMapa as $l)
            <label class="flex cursor-pointer items-center gap-1.5">
                <input type="checkbox" checked value="{{ $l['codigo'] }}" class="rounded border-slate-300">
                <span class="inline-block h-2 w-5 rounded" style="background: {{ $l['color'] }}"></span>
                <b>{{ $l['codigo'] }}</b> {{ $l['nombre'] }}
            </label>
        @endforeach
        <span class="ml-auto flex items-center gap-1"><span class="inline-block h-3 w-3 rounded-full border-2 border-slate-900 bg-white"></span> Transbordo</span>
    </div>
</div>

@push('scripts')
    <script>
        (() => {
            const lineas = @json($lineasMapa);
            const resaltar = @json($resaltar ?? null);
            const esc = (t) => String(t ?? '').replace(/[&<>"']/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));

            // Fondos de mapa gratuitos (sin llave): calles (Esri), satélite con nombres (Esri) y OpenStreetMap.
            const osm = '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>';
            const fondos = {
                'Calles': L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Street_Map/MapServer/tile/{z}/{y}/{x}', {
                    maxZoom: 19, attribution: 'Mapa &copy; Esri, HERE, Garmin, &copy; OpenStreetMap',
                }),
                'Satélite': L.layerGroup([
                    L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
                        maxZoom: 19, attribution: 'Imágenes &copy; Esri, Maxar, Earthstar Geographics',
                    }),
                    L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/Reference/World_Boundaries_and_Places/MapServer/tile/{z}/{y}/{x}', {
                        maxZoom: 19,
                    }),
                ]),
                'OpenStreetMap': L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {maxZoom: 19, attribution: osm}),
            };
            const mapa = L.map(@json($idMapa), {layers: [fondos['Calles']]});
            L.control.layers(fondos, null, {position: 'topright'}).addTo(mapa);
            L.control.scale({imperial: false}).addTo(mapa);

            // Rutas: una línea de color por cada línea del Transmetro, uniendo sus estaciones en orden.
            const rutas = {};
            const limites = [];
            lineas.forEach((l) => {
                const puntos = l.estaciones.map((e) => [e.lat, e.lon]);
                if (l.circular && puntos.length > 2) puntos.push(puntos[0]);
                rutas[l.codigo] = L.polyline(puntos, {color: l.color, weight: 6, opacity: 0.85})
                    .bindTooltip(`${esc(l.codigo)} · ${esc(l.nombre)}`, {sticky: true})
                    .addTo(mapa);
                limites.push(...puntos);
            });

            // Estaciones: una sola marca por estación, con todas las líneas que pasan por ella.
            const estaciones = {};
            lineas.forEach((l) => l.estaciones.forEach((e) => {
                estaciones[e.id] ??= {...e, lineas: []};
                estaciones[e.id].lineas.push({codigo: l.codigo, color: l.color, orden: e.orden});
            }));

            const marcas = Object.values(estaciones).map((e) => {
                const transbordo = e.lineas.length > 1;
                const propia = e.id === resaltar;
                const marca = L.circleMarker([e.lat, e.lon], {
                    radius: propia ? 11 : (transbordo ? 8 : 6),
                    color: propia ? '#d97706' : (transbordo ? '#111827' : e.lineas[0].color),
                    weight: 3,
                    fillColor: propia ? '#fbbf24' : '#ffffff',
                    fillOpacity: 1,
                }).bindTooltip(esc(e.nombre)).bindPopup(
                    `<b>${esc(e.nombre)}</b>` +
                    (e.direccion ? `<br>${esc(e.direccion)}` : '<br><small>Ubicación aproximada</small>') +
                    '<br>' + e.lineas.map((l) =>
                        `<span style="background:${esc(l.color)};color:#fff;border-radius:4px;padding:1px 6px;margin:4px 4px 0 0;display:inline-block;font-size:11px">${esc(l.codigo)} · parada ${l.orden}</span>`
                    ).join('') +
                    (transbordo ? '<br><i>Estación de transbordo</i>' : '') +
                    (propia ? '<br><b>Tu estación</b>' : '')
                ).addTo(mapa);
                if (propia) setTimeout(() => marca.openPopup(), 300);

                return {marca, codigos: e.lineas.map((l) => l.codigo)};
            });

            if (limites.length) mapa.fitBounds(limites, {padding: [30, 30]});
            else mapa.setView([14.6349, -90.5069], 13);

            // Leyenda: mostrar u ocultar líneas.
            document.querySelectorAll(`[data-leyenda="${@json($idMapa)}"] input`).forEach((check) => {
                check.addEventListener('change', () => {
                    const visibles = new Set([...document.querySelectorAll(`[data-leyenda="${@json($idMapa)}"] input:checked`)].map((c) => c.value));
                    Object.entries(rutas).forEach(([codigo, ruta]) => visibles.has(codigo) ? ruta.addTo(mapa) : ruta.remove());
                    marcas.forEach(({marca, codigos}) => codigos.some((c) => visibles.has(c)) ? marca.addTo(mapa) : marca.remove());
                });
            });
        })();
    </script>
@endpush
