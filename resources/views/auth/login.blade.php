<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Iniciar sesión · Transmetro</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-white font-sans text-noche antialiased">
    <div class="grid min-h-screen lg:grid-cols-[1.15fr_1fr]">

        {{-- ===== Lado de la imagen: la ciudad y sus rutas ===== --}}
        <section class="relative hidden overflow-hidden bg-noche text-white lg:block">
            <div class="absolute inset-0" style="background: radial-gradient(900px 500px at 10% 0%, rgb(142 185 252 / .28), transparent 65%), radial-gradient(700px 500px at 100% 100%, rgb(64 77 99 / .9), transparent 70%)"></div>

            <svg class="absolute inset-0 h-full w-full" viewBox="0 0 800 900" preserveAspectRatio="xMidYMid slice" fill="none" aria-hidden="true">
                {{-- Ciudad al fondo --}}
                <g fill="#26324a">
                    @foreach ([[40, 640, 60], [110, 560, 46], [165, 700, 70], [245, 520, 40], [295, 610, 64], [370, 470, 44], [425, 590, 58], [495, 540, 36], [540, 650, 72], [620, 500, 48], [680, 600, 60], [745, 560, 55]] as [$x, $y, $w])
                        <rect x="{{ $x }}" y="{{ $y }}" width="{{ $w }}" height="{{ 900 - $y }}" class="aparecer" style="--retraso: {{ $loop->index * 50 }}ms"/>
                    @endforeach
                </g>
                <g fill="#8eb9fc" opacity=".35">
                    @foreach (range(0, 34) as $i)
                        <rect x="{{ 50 + ($i * 97) % 700 }}" y="{{ 540 + ($i * 53) % 300 }}" width="6" height="6" class="estallar" style="--retraso: {{ 800 + $i * 40 }}ms"/>
                    @endforeach
                </g>

                {{-- Rutas que se dibujan --}}
                <path d="M-20 760 C 160 760, 220 520, 400 500 S 640 600, 820 380" stroke="#7e8896" stroke-width="9" stroke-linecap="round" class="dibujar" style="--largo: 1100; --retraso: 400ms"/>
                <path d="M120 -20 C 160 200, 360 260, 420 420 S 600 700, 820 720" stroke="#99acc9" stroke-width="9" stroke-linecap="round" class="dibujar" style="--largo: 1200; --retraso: 200ms"/>
                <path id="ruta-portada" d="M-20 420 C 180 420, 260 300, 420 300 S 640 460, 820 250" stroke="#8eb9fc" stroke-width="12" stroke-linecap="round" class="dibujar" style="--largo: 1100"/>

                @foreach ([[80, 420], [250, 345], [420, 300], [600, 400], [760, 290], [300, 515], [540, 548], [420, 420], [250, 160]] as [$x, $y])
                    <circle cx="{{ $x }}" cy="{{ $y }}" r="11" fill="#162133" stroke="#fff" stroke-width="5" class="estallar" style="--retraso: {{ 900 + $loop->index * 110 }}ms"/>
                @endforeach

                {{-- Bus recorriendo la ruta celeste --}}
                <g>
                    <g transform="translate(-30 -15)">
                        <rect width="60" height="30" rx="9" fill="#fff"/>
                        <rect x="7" y="7" width="12" height="10" rx="2.5" fill="#162133"/>
                        <rect x="23" y="7" width="12" height="10" rx="2.5" fill="#162133"/>
                        <rect x="39" y="7" width="14" height="10" rx="2.5" fill="#8eb9fc"/>
                    </g>
                    <animateMotion dur="18s" repeatCount="indefinite" rotate="auto" begin="1.4s">
                        <mpath href="#ruta-portada"/>
                    </animateMotion>
                </g>
            </svg>

            {{-- Velo para que el texto se lea sobre la ilustración --}}
            <div class="absolute inset-0" style="background: linear-gradient(100deg, rgb(22 33 51 / .94) 0%, rgb(22 33 51 / .78) 38%, rgb(22 33 51 / .15) 72%, transparent 100%)"></div>

            <div class="relative flex h-full flex-col justify-between p-12">
                <div class="flex items-center gap-3">
                    <svg class="h-10 w-10" viewBox="0 0 40 40" fill="none" aria-hidden="true">
                        <rect width="40" height="40" rx="11" fill="#fff"/>
                        <path d="M10 28c6 0 6-16 12-16s6 10 8 10" stroke="#162133" stroke-width="3.2" stroke-linecap="round"/>
                        <circle cx="10" cy="28" r="3.4" fill="#8eb9fc"/><circle cx="30" cy="22" r="3.4" fill="#8eb9fc"/>
                    </svg>
                    <span class="text-[16px] font-black tracking-[0.22em]">TRANSMETRO</span>
                </div>

                <div class="max-w-lg">
                    <p class="rotulo aparecer flex items-center gap-3 text-cielo" style="--retraso: 200ms"><span class="h-px w-8 bg-cielo"></span>Municipalidad de Guatemala</p>
                    <h1 class="mt-5 text-[64px] font-black leading-[0.95] tracking-[-0.045em] xl:text-[76px]">
                        <span class="mascara"><span style="--retraso: 300ms">Moviendo</span></span>
                        <span class="mascara"><span style="--retraso: 420ms">la ciudad,</span></span>
                        <span class="mascara"><span style="--retraso: 540ms" class="text-cielo">estación por estación.</span></span>
                    </h1>
                </div>

                <p class="aparecer max-w-sm text-[13px] leading-relaxed text-niebla" style="--retraso: 900ms">Sistema de control de líneas, estaciones, buses y personal del Transmetro.</p>
            </div>
        </section>

        {{-- ===== Formulario ===== --}}
        <section class="flex items-center justify-center px-6 py-16 sm:px-16">
            <div class="w-full max-w-sm">
                <p class="rotulo aparecer text-acero">Sistema de control</p>
                <h2 class="mt-3 text-[44px] font-black leading-none tracking-[-0.045em]">
                    <span class="mascara"><span style="--retraso: 120ms">Hola de</span></span>
                    <span class="mascara"><span style="--retraso: 220ms">nuevo.</span></span>
                </h2>
                <p class="aparecer mt-4 text-[15px] text-acero" style="--retraso: 320ms">Ingresa con tu cuenta para gestionar las rutas del Transmetro.</p>

                <form method="POST" action="{{ route('login.store') }}" class="aparecer mt-10 space-y-7" style="--retraso: 420ms">
                    @csrf

                    @if ($errors->any())
                        <div class="border-l-[3px] border-red-500 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700">
                            {{ $errors->first() }}
                        </div>
                    @endif

                    @foreach ([['email', 'Correo electrónico', 'email', 'username'], ['password', 'Contraseña', 'password', 'current-password']] as [$campo, $etiqueta, $tipo, $auto])
                        <label class="group relative block">
                            <span class="rotulo !text-[10.5px] text-pizarra">{{ $etiqueta }}</span>
                            <input id="{{ $campo }}" name="{{ $campo }}" type="{{ $tipo }}" @if ($campo === 'email') value="{{ old('email') }}" autofocus @endif required autocomplete="{{ $auto }}"
                                   class="peer mt-1 w-full border-0 border-b-2 border-niebla/50 bg-transparent px-0 py-2.5 text-[17px] font-semibold text-noche transition-colors focus:border-noche focus:ring-0 focus:outline-none">
                            <span class="pointer-events-none absolute bottom-0 left-0 h-0.5 w-full origin-left scale-x-0 bg-cielo transition-transform duration-700 [transition-timing-function:var(--ease-suave)] peer-focus:scale-x-100"></span>
                        </label>
                    @endforeach

                    <label class="flex items-center gap-2.5 text-[14px] text-pizarra">
                        <input type="checkbox" name="recordar" value="1" class="h-4 w-4 rounded border-niebla accent-noche">
                        Recordarme en este equipo
                    </label>

                    <button type="submit" class="btn btn-primario w-full py-3.5 text-[15px]">
                        Entrar
                        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 10h12M11 5l5 5-5 5"/></svg>
                    </button>
                </form>
            </div>
        </section>
    </div>
</body>
</html>
