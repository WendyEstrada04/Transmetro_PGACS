<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('titulo') · Transmetro</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')
</head>
<body class="min-h-screen bg-tm-50 font-sans text-slate-800 antialiased">
    <header class="bg-tm-900 text-white">
        <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-4 px-4 py-3">
            <div class="flex items-center gap-3">
                <span class="flex h-9 w-9 items-center justify-center rounded-full bg-amber-400 text-lg font-bold text-tm-900">T</span>
                <div>
                    <p class="font-semibold leading-tight">Transmetro</p>
                    <p class="text-xs text-tm-100">Sistema de Control</p>
                </div>
            </div>

            <nav class="flex flex-wrap items-center gap-1 text-sm">
                @if (auth()->user()->esAdministrador())
                    @foreach ([
                        ['admin.inicio', 'admin.inicio', 'Inicio'],
                        ['mapa', 'mapa', 'Mapa'],
                        ['admin.lineas.index', 'admin.lineas.*', 'Líneas'],
                        ['admin.estaciones.index', 'admin.estaciones.*', 'Estaciones'],
                        ['admin.buses.index', 'admin.buses.*', 'Buses'],
                        ['admin.personal.index', 'admin.personal.*', 'Personal'],
                        ['admin.tarjetas.index', 'admin.tarjetas.*', 'Tarjetas'],
                        ['admin.municipios.index', 'admin.municipios.*', 'Municipios'],
                    ] as [$ruta, $patron, $texto])
                        <a href="{{ route($ruta) }}"
                           class="rounded-md px-3 py-2 {{ request()->routeIs($patron) ? 'bg-tm-700' : 'hover:bg-tm-700' }}">{{ $texto }}</a>
                    @endforeach
                @else
                    <a href="{{ route('operador.estacion') }}"
                       class="rounded-md px-3 py-2 {{ request()->routeIs('operador.*') ? 'bg-tm-700' : 'hover:bg-tm-700' }}">Mi estación</a>
                    <a href="{{ route('mapa') }}"
                       class="rounded-md px-3 py-2 {{ request()->routeIs('mapa') ? 'bg-tm-700' : 'hover:bg-tm-700' }}">Mapa</a>
                @endif
            </nav>

            <div class="flex items-center gap-3 text-sm">
                <div class="text-right">
                    <p class="font-medium">{{ auth()->user()->name }}</p>
                    <p class="text-xs capitalize text-amber-300">{{ auth()->user()->rol }}</p>
                </div>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="rounded-md border border-tm-300 px-3 py-1.5 hover:bg-tm-700">Salir</button>
                </form>
            </div>
        </div>
    </header>

    <main class="mx-auto max-w-6xl px-4 py-6">
        @if (session('estado'))
            <div class="mb-4 rounded-md border border-tm-300 bg-white px-4 py-3 text-sm text-tm-700">
                {{ session('estado') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="mb-4 rounded-md border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-700">
                @foreach ($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif

        @yield('contenido')
    </main>

    @stack('scripts')
</body>
</html>
