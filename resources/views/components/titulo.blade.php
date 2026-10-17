@props(['titulo', 'subtitulo' => null, 'volver' => null, 'seccion' => null, 'tono' => null, 'ilustracion' => null])
{{--
    Banda de título de cada pantalla: bloque de color a todo lo ancho, título grande que sube desde una máscara
    y una ilustración animada. Cada sección tiene su tono e ilustración para que las pantallas no se sientan iguales:
      tono: noche (predeterminado) · cielo · pizarra · papel
      ilustracion: rutas (predeterminada) · personas · tarjetas · buses · pulso · reportes
--}}
@php
    [$seccionAuto, $tonoAuto, $ilustracionAuto] = match (true) {
        request()->routeIs('admin.personal.*') => ['Gestión · personal', 'cielo', 'personas'],
        request()->routeIs('admin.tarjetas.*') => ['Gestión · Tarjeta Ciudadana', 'pizarra', 'tarjetas'],
        request()->routeIs('admin.buses.*') => ['Gestión · flota', 'noche', 'buses'],
        request()->routeIs('admin.reportes.*') => ['Reportes', 'papel', 'reportes'],
        request()->routeIs('admin.bitacora.*') => ['Gestión · auditoría', 'pizarra', 'reportes'],
        request()->routeIs('admin.simulacion.*') => ['Operación en vivo', 'noche', 'pulso'],
        request()->routeIs('admin.lineas.*', 'admin.estaciones.*', 'admin.municipios.*', 'mapa') => ['Red de rutas', 'noche', 'rutas'],
        default => ['Transmetro', 'noche', 'rutas'],
    };
    $seccion ??= $seccionAuto;
    $tono ??= $tonoAuto;
    $ilustracion ??= $ilustracionAuto;
@endphp
<section class="banda banda-{{ $tono }} mb-10">
    @include('partials.ilustraciones.'.$ilustracion)

    <div class="relative z-10 py-12 md:py-16">
        <div class="max-w-2xl">
            @if ($volver)
                <a href="{{ $volver }}" class="volver-banda rotulo aparecer group mb-5 inline-flex items-center gap-2 text-niebla transition-colors hover:opacity-70">
                    <svg class="h-4 w-4 transition-transform duration-500 group-hover:-translate-x-1" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M16 10H4M9 5l-5 5 5 5"/></svg>
                    Volver
                </a>
            @endif
            <p class="rotulo rotulo-banda aparecer flex items-center gap-3 text-cielo">
                <span class="h-px w-8 bg-cielo"></span>{{ $seccion }}
            </p>
            <h1 class="mt-4 text-[40px] font-black leading-[0.98] tracking-[-0.04em] md:text-[60px]">
                <span class="mascara"><span style="--retraso: 90ms">{{ $titulo }}</span></span>
            </h1>
            @if ($subtitulo)
                <p class="texto-banda aparecer mt-5 max-w-xl text-[15px] leading-relaxed text-niebla" style="--retraso: 260ms">{{ $subtitulo }}</p>
            @endif
            @if (trim($slot))
                <div class="aparecer mt-8 flex flex-wrap gap-2.5" style="--retraso: 380ms">{{ $slot }}</div>
            @endif
        </div>
    </div>
</section>
