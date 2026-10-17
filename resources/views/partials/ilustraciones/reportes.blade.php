{{-- Ilustración de Reportes: barras que crecen, una curva que se dibuja y hojas de reporte. --}}
<svg class="pointer-events-none absolute inset-y-0 right-0 hidden h-full w-[62%] max-w-[760px] md:block" viewBox="0 0 640 300" preserveAspectRatio="xMaxYMax slice" fill="none" aria-hidden="true" style="mask-image: linear-gradient(90deg, transparent 0%, #000 35%)">
    <g transform="translate(470 40) rotate(6)">
        <rect width="150" height="200" rx="10" fill="#ffffff" stroke="#162133" stroke-opacity=".12"/>
        @foreach ([30, 50, 70, 90, 110] as $y)
            <rect x="20" y="{{ $y }}" width="{{ 110 - $loop->index * 12 }}" height="7" rx="3.5" fill="#99acc9" fill-opacity=".5"/>
        @endforeach
    </g>
    @foreach ([110, 160, 90, 200, 150, 230] as $alto)
        <rect x="{{ 250 + $loop->index * 38 }}" y="{{ 270 - $alto }}" width="26" height="{{ $alto }}" rx="4" fill="{{ $loop->last ? '#8eb9fc' : '#162133' }}"
              class="crecer" style="--i: {{ $loop->index }}; transform-box: fill-box"/>
    @endforeach
    <path d="M240 200 C 290 180, 320 120, 370 140 S 450 60, 500 80 S 580 40, 630 30" stroke="#8eb9fc" stroke-width="5" stroke-linecap="round" class="dibujar" style="--largo: 520; --retraso: 600ms"/>
    <line x1="230" y1="271" x2="640" y2="271" stroke="#162133" stroke-opacity=".2" stroke-width="2"/>
</svg>
