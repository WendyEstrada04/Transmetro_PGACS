<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('titulo') · Transmetro</title>
    <script>
        // Activa las animaciones de entrada antes de pintar la página (sin parpadeo).
        // Si la página solo se recargó (p. ej. la del operador cada pocos segundos) no se repiten.
        if (performance.getEntriesByType?.('navigation')[0]?.type === 'reload') document.documentElement.classList.add('quieto');
        else document.documentElement.classList.add('js');
        // Si el JavaScript del sistema no llegara a cargar, se muestra todo igual.
        setTimeout(() => window.tmAnimar || document.documentElement.classList.remove('js'), 2500);
    </script>
    {{-- Precarga la página de un enlace al empezar el clic (Chrome/Edge). Solo al hacer clic, no al pasar el mouse:
         así no se llenan de pedidos el servidor y la base de datos mientras se recorre el menú. --}}
    <script type="speculationrules">
        {"prefetch": [{"source": "document", "eagerness": "conservative",
          "where": {"and": [{"href_matches": "/*"}, {"not": {"href_matches": "*formato=csv*"}}, {"not": {"selector_matches": "[data-sin-precarga]"}}]}}]}
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')
</head>
@php
    // Pantallas a todo lo ancho (la principal con el mapa): sin márgenes y con la altura exacta de la ventana.
    $completa = View::hasSection('pantalla_completa');
    $esAdmin = auth()->user()->esAdministrador();
    // Menús desplegables: “Red” (líneas, estaciones y municipios oficiales: solo consulta), “Gestión” (lo que se administra)
    // y “Reportes”. Cada opción: [enlace, ¿está abierta?, texto, ayuda].
    $opcion = fn (string $ruta, string $patron, string $texto, string $ayuda) => [route($ruta), request()->routeIs($patron), $texto, $ayuda];
    $menus = ! $esAdmin ? [] : [
        'Red' => [
            $opcion('admin.lineas.index', 'admin.lineas.*', 'Líneas', 'Rutas, recorrido y flota'),
            $opcion('admin.estaciones.index', 'admin.estaciones.*', 'Estaciones', 'Aforo, accesos y parqueos'),
            $opcion('admin.municipios.index', 'admin.municipios.*', 'Municipios', 'Zonas que cubre la red'),
        ],
        'Gestión' => [
            $opcion('admin.personal.index', 'admin.personal.*', 'Personal', 'Pilotos, operadores y seguridad'),
            $opcion('admin.tarjetas.index', 'admin.tarjetas.*', 'Tarjetas', 'Consulta, bloqueo y baja'),
            $opcion('admin.buses.index', 'admin.buses.*', 'Buses', 'Flota y parqueo de cada bus'),
            $opcion('admin.bitacora.index', 'admin.bitacora.*', 'Bitácora', 'Quién cambió qué y cuándo'),
        ],
        'Reportes' => [
            [route('admin.reportes.index'), request()->routeIs('admin.reportes.index'), 'Todos los reportes', 'Imprimir o guardar en PDF'],
            ...collect(\App\Services\Reportes::definiciones())->map(fn ($r, $clave) => [
                route('admin.reportes.show', $clave), request()->routeIs('admin.reportes.show') && request()->route('reporte') === $clave,
                $r['titulo'], $r['seccion'] === 'Operación' ? 'Operación del día' : 'Estaciones, líneas y buses',
            ])->values()->all(),
        ],
    ];
    $principal = $esAdmin
        ? [['admin.inicio', 'admin.inicio', 'Mapa'], ['admin.simulacion.index', 'admin.simulacion.*', 'Simulación']]
        : [['operador.inicio', 'operador.*', 'Mi estación'], ['mapa', 'mapa', 'Monitoreo en vivo']];
@endphp
<body class="{{ $completa ? 'h-dvh overflow-hidden' : 'flex min-h-screen flex-col' }} bg-papel font-sans text-noche antialiased">
    <header class="barra sticky top-0 z-[1000] h-16">
        <div class="{{ $completa ? 'px-6' : 'mx-auto max-w-6xl px-4' }} flex h-full items-center justify-between gap-6">
            <a href="{{ route('panel') }}" class="group flex shrink-0 items-center gap-3" aria-label="Transmetro, inicio">
                {{-- Marca: una ruta con dos estaciones --}}
                <svg class="h-9 w-9" viewBox="0 0 40 40" fill="none" aria-hidden="true">
                    <rect width="40" height="40" rx="11" fill="#162133"/>
                    <path d="M10 28c6 0 6-16 12-16s6 10 8 10" stroke="#8eb9fc" stroke-width="3.2" stroke-linecap="round"/>
                    <circle cx="10" cy="28" r="3.4" fill="#fff" class="transition-transform duration-500 [transform-box:fill-box] [transform-origin:center] group-hover:scale-125"/>
                    <circle cx="30" cy="22" r="3.4" fill="#fff" class="transition-transform delay-100 duration-500 [transform-box:fill-box] [transform-origin:center] group-hover:scale-125"/>
                </svg>
                <span class="hidden leading-none sm:block">
                    <span class="block text-[15px] font-black tracking-[0.2em]">TRANSMETRO</span>
                    <span class="rotulo mt-1 block !text-[9.5px] !tracking-[0.3em] text-acero">Sistema de control</span>
                </span>
            </a>

            <nav class="flex min-w-0 items-center gap-6 text-[14px]">
                @foreach ($principal as [$ruta, $patron, $texto])
                    <a href="{{ route($ruta) }}" class="nav-enlace {{ request()->routeIs($patron) ? 'activo' : '' }}">{{ $texto }}</a>
                @endforeach

                @if ($esAdmin)
                    @foreach ($menus as $nombreMenu => $items)
                        @php $enMenu = collect($items)->contains(fn ($g) => $g[1]); @endphp
                        <details class="menu-gestion relative">
                            <summary class="nav-enlace {{ $enMenu ? 'activo' : '' }} flex cursor-pointer items-center gap-1.5">
                                {{ $nombreMenu }}
                                <svg class="h-3 w-3 transition-transform duration-500 [details[open]_&]:rotate-180" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 4.5 6 7.5 9 4.5"/></svg>
                            </summary>
                            <div class="menu-cortina absolute right-0 mt-4 w-80 overflow-hidden overflow-y-auto rounded-xl bg-white shadow-[0_30px_60px_-25px_rgba(22,33,51,0.55),0_0_0_1px_rgba(22,33,51,0.06)] {{ count($items) > 4 ? 'max-h-[calc(100dvh-6rem)] menu-compacto' : '' }}">
                                <p class="rotulo bg-papel px-5 py-3 text-acero">{{ $nombreMenu }}</p>
                                @foreach ($items as [$enlace, $abierta, $texto, $ayuda])
                                    <a href="{{ $enlace }}" class="menu-item {{ $abierta ? 'bg-tm-100/60' : '' }}">
                                        <span>
                                            <span class="block {{ count($items) > 4 ? 'text-[16px]' : 'text-[19px]' }} font-extrabold tracking-tight text-noche">{{ $texto }}</span>
                                            <span class="block text-[12px] text-acero">{{ $ayuda }}</span>
                                        </span>
                                        <svg class="flecha h-5 w-5 shrink-0 text-noche" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M4 10h12M11 5l5 5-5 5"/></svg>
                                    </a>
                                @endforeach
                            </div>
                        </details>
                    @endforeach
                @endif
            </nav>

            <div class="flex shrink-0 items-center gap-5 text-sm">
                <div class="hidden text-right md:block">
                    <p class="text-[13px] font-extrabold leading-tight">{{ auth()->user()->name }}</p>
                    <p class="rotulo !text-[9.5px] text-acero">{{ auth()->user()->rol }}</p>
                </div>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="btn btn-secundario btn-chico">Salir</button>
                </form>
            </div>
        </div>
    </header>

    {{-- Avisos: aparecen deslizándose en la esquina y se van solos --}}
    @if (session('estado') || $errors->any())
        <div class="pointer-events-none fixed right-5 top-20 z-[1100] flex w-[22rem] max-w-[calc(100%-2.5rem)] flex-col gap-2">
            @if (session('estado'))
                <div data-aviso class="aviso pointer-events-auto flex items-start gap-3 rounded-xl bg-noche px-4 py-3.5 text-[13px] text-white shadow-[0_24px_48px_-20px_rgba(22,33,51,0.8)]">
                    <span class="mt-1 h-2 w-2 shrink-0 rounded-full bg-cielo"></span>
                    <span class="flex-1">{{ session('estado') }}</span>
                </div>
            @endif
            @if ($errors->any())
                <div class="aviso pointer-events-auto rounded-xl bg-white px-4 py-3.5 text-[13px] text-red-700 shadow-[0_24px_48px_-20px_rgba(22,33,51,0.5)] ring-1 ring-red-200">
                    <p class="rotulo mb-1 text-red-600">Revisa el formulario</p>
                    @foreach ($errors->all() as $error)
                        <p>{{ $error }}</p>
                    @endforeach
                </div>
            @endif
        </div>
    @endif

    @if ($completa)
        <main class="relative isolate h-[calc(100dvh-4rem)]">
            @yield('pantalla_completa')
        </main>
    @else
        <main class="contenido mx-auto w-full max-w-6xl flex-1 px-4 pb-16 pt-6">
            @yield('contenido')
        </main>

        <footer class="border-t border-noche/[0.07] bg-white">
            <div class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-6 text-[12px] text-acero">
                <span class="font-black tracking-[0.2em] text-noche">TRANSMETRO</span>
                <span>Municipalidad de Guatemala · Sistema de Control</span>
            </div>
        </footer>
    @endif

    <style>
        .aviso { animation: entrar-aviso .7s var(--ease-suave) both; }
        .aviso.saliendo { animation: salir-aviso .5s var(--ease-suave) both; }
        @keyframes entrar-aviso { from { opacity: 0; transform: translateX(40px); } }
        @keyframes salir-aviso { to { opacity: 0; transform: translateX(40px); } }
    </style>
    <script>
        // Cierra el menú “Gestión” al hacer clic fuera o con Esc; los avisos se van solos.
        document.addEventListener('click', (e) => document.querySelectorAll('details.menu-gestion[open]').forEach((d) => d.contains(e.target) || d.removeAttribute('open')));
        document.addEventListener('keydown', (e) => e.key === 'Escape' && document.querySelectorAll('details.menu-gestion[open]').forEach((d) => d.removeAttribute('open')));
        document.querySelectorAll('[data-aviso]').forEach((a) => setTimeout(() => { a.classList.add('saliendo'); setTimeout(() => a.remove(), 600); }, 5000));
    </script>
    @stack('scripts')
</body>
</html>
