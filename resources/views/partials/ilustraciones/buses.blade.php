{{-- Ilustración de Buses: un bus articulado que llega a la parada sobre una calle en movimiento. --}}
<svg class="pointer-events-none absolute inset-y-0 right-0 hidden h-full w-[62%] max-w-[760px] md:block" viewBox="0 0 640 300" preserveAspectRatio="xMaxYMax slice" fill="none" aria-hidden="true" style="mask-image: linear-gradient(90deg, transparent 0%, #000 35%)">
    <g fill="#26324a">
        <rect x="250" y="120" width="34" height="180"/><rect x="292" y="90" width="26" height="210"/><rect x="430" y="70" width="30" height="230"/>
        <rect x="470" y="110" width="44" height="190"/><rect x="560" y="60" width="28" height="240"/><rect x="596" y="100" width="44" height="200"/>
    </g>
    <line x1="160" y1="262" x2="640" y2="262" stroke="#404d63" stroke-width="4"/>
    <line x1="160" y1="282" x2="640" y2="282" stroke="#7e8896" stroke-width="3" stroke-dasharray="30 30" class="rodar"/>
    {{-- Parada --}}
    <g class="estallar" style="--retraso: 300ms">
        <rect x="236" y="150" width="4" height="112" fill="#99acc9"/>
        <rect x="222" y="132" width="32" height="32" rx="8" fill="#8eb9fc"/>
        <path d="M230 150c3 0 3-8 7-8s3 5 7 5" stroke="#162133" stroke-width="2.5" stroke-linecap="round"/>
    </g>
    {{-- Bus --}}
    <g class="llegar">
        <rect x="290" y="150" width="190" height="96" rx="16" fill="#ffffff"/>
        <rect x="486" y="150" width="140" height="96" rx="16" fill="#ffffff"/>
        <rect x="478" y="160" width="14" height="76" fill="#99acc9"/>
        <rect x="290" y="206" width="336" height="10" fill="#8eb9fc"/>
        @foreach ([306, 350, 394, 438, 502, 546] as $x)
            <rect x="{{ $x }}" y="164" width="34" height="30" rx="5" fill="#162133"/>
        @endforeach
        <rect x="590" y="164" width="26" height="30" rx="5" fill="#8eb9fc"/>
        <rect x="300" y="222" width="40" height="18" rx="3" fill="#404d63"/>
        <circle cx="620" cy="228" r="5" fill="#fde68a"/>
        @foreach ([340, 440, 580] as $x)
            <circle cx="{{ $x }}" cy="248" r="17" fill="#162133"/>
            <g class="girar"><circle cx="{{ $x }}" cy="248" r="7" fill="#7e8896"/><rect x="{{ $x - 1.5 }}" y="236" width="3" height="24" fill="#99acc9"/></g>
        @endforeach
    </g>
</svg>
