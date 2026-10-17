{{-- Ilustración de Simulación: ondas en vivo y el pulso de la operación. --}}
<svg class="pointer-events-none absolute inset-y-0 right-0 hidden h-full w-[62%] max-w-[760px] md:block" viewBox="0 0 640 300" preserveAspectRatio="xMaxYMax slice" fill="none" aria-hidden="true" style="mask-image: linear-gradient(90deg, transparent 0%, #000 35%)">
    @foreach ([0, 750, 1500, 2250] as $retraso)
        <circle cx="500" cy="150" r="60" stroke="#8eb9fc" stroke-width="2" class="onda" style="--retraso: {{ $retraso }}ms"/>
    @endforeach
    <circle cx="500" cy="150" r="12" fill="#8eb9fc"/>
    <circle cx="500" cy="150" r="5" fill="#162133"/>
    <path d="M150 190 H330 L350 150 L372 240 L398 90 L424 210 L444 170 H640" stroke="#8eb9fc" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" class="latir-trazo"/>
    @foreach ([[260, 80], [600, 70], [620, 240], [300, 260], [420, 40]] as [$x, $y])
        <circle cx="{{ $x }}" cy="{{ $y }}" r="5" fill="#99acc9" class="estallar" style="--retraso: {{ 400 + $loop->index * 150 }}ms"/>
        <line x1="{{ $x }}" y1="{{ $y }}" x2="500" y2="150" stroke="#99acc9" stroke-opacity=".25" stroke-width="1.5" stroke-dasharray="4 6"/>
    @endforeach
</svg>
