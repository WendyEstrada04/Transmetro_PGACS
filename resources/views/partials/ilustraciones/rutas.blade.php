{{--
    Ilustración de la marca: silueta de la ciudad, rutas que se dibujan, estaciones que aparecen
    y un bus que recorre la ruta celeste. Se usa en la banda de título y en el inicio de sesión.
--}}
<svg class="pointer-events-none absolute inset-y-0 right-0 hidden h-full w-[62%] max-w-[760px] md:block"
     viewBox="0 0 640 300" preserveAspectRatio="xMaxYMax slice" fill="none" aria-hidden="true"
     style="mask-image: linear-gradient(90deg, transparent 0%, #000 35%)">
    {{-- Ciudad --}}
    <g fill="#26324a">
        <rect x="250" y="190" width="34" height="110"/><rect x="290" y="150" width="26" height="150"/>
        <rect x="322" y="205" width="40" height="95"/><rect x="368" y="120" width="30" height="180"/>
        <rect x="404" y="170" width="44" height="130"/><rect x="454" y="95" width="24" height="205"/>
        <rect x="484" y="160" width="38" height="140"/><rect x="528" y="135" width="30" height="165"/>
        <rect x="564" y="185" width="46" height="115"/><rect x="614" y="150" width="30" height="150"/>
    </g>
    <g fill="#8eb9fc" opacity=".35">
        @foreach ([[296, 162], [304, 182], [374, 134], [384, 160], [460, 110], [460, 140], [492, 176], [534, 150], [544, 178], [620, 166], [410, 186], [574, 200]] as [$x, $y])
            <rect x="{{ $x }}" y="{{ $y }}" width="5" height="5" class="estallar" style="--retraso: {{ 900 + $loop->index * 90 }}ms"/>
        @endforeach
    </g>

    {{-- Rutas --}}
    <path d="M60 300 C 170 300, 200 205, 320 200 S 470 230, 640 120" stroke="#7e8896" stroke-width="5" stroke-linecap="round" class="dibujar" style="--largo: 760; --retraso: 300ms"/>
    <path d="M130 30 C 230 40, 250 130, 360 150 S 520 90, 640 70" stroke="#99acc9" stroke-width="5" stroke-linecap="round" class="dibujar" style="--largo: 620; --retraso: 150ms"/>
    <path id="ruta-ilustracion" d="M0 250 C 120 250, 170 120, 300 110 S 470 200, 640 210" stroke="#8eb9fc" stroke-width="7" stroke-linecap="round" class="dibujar" style="--largo: 760"/>

    {{-- Estaciones --}}
    @foreach ([[0, 250], [150, 175], [300, 110], [440, 175], [580, 207], [360, 150], [520, 99], [320, 200], [470, 192]] as [$x, $y])
        <circle cx="{{ $x }}" cy="{{ $y }}" r="7" fill="#162133" stroke="#fff" stroke-width="3.5" class="estallar" style="--retraso: {{ 700 + $loop->index * 120 }}ms"/>
    @endforeach

    {{-- Bus que recorre la ruta --}}
    <g>
        <g transform="translate(-17 -9)">
            <rect width="34" height="18" rx="5" fill="#fff"/>
            <rect x="4" y="4" width="7" height="6" rx="1.5" fill="#162133"/>
            <rect x="13" y="4" width="7" height="6" rx="1.5" fill="#162133"/>
            <rect x="22" y="4" width="8" height="6" rx="1.5" fill="#8eb9fc"/>
        </g>
        <animateMotion dur="16s" repeatCount="indefinite" rotate="auto" begin="1.2s" keyPoints="0;1" keyTimes="0;1" calcMode="linear">
            <mpath href="#ruta-ilustracion"/>
        </animateMotion>
    </g>
</svg>
