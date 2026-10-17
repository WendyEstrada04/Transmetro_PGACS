{{-- Ilustración de Tarjetas: tres Tarjetas Ciudadanas flotando, con la señal sin contacto. --}}
<svg class="pointer-events-none absolute inset-y-0 right-0 hidden h-full w-[62%] max-w-[760px] md:block" viewBox="0 0 640 300" preserveAspectRatio="xMaxYMax slice" fill="none" aria-hidden="true" style="mask-image: linear-gradient(90deg, transparent 0%, #000 35%)">
    <defs>
        <linearGradient id="tarjeta-a" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#8eb9fc"/><stop offset="1" stop-color="#e6edf9"/></linearGradient>
        <linearGradient id="tarjeta-b" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#162133"/><stop offset="1" stop-color="#404d63"/></linearGradient>
        <linearGradient id="tarjeta-c" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#99acc9"/><stop offset="1" stop-color="#8eb9fc"/></linearGradient>
    </defs>
    @foreach ([[300, 120, -10, 'tarjeta-c', '#162133'], [395, 60, 6, 'tarjeta-b', '#ffffff'], [470, 150, -4, 'tarjeta-a', '#162133']] as [$x, $y, $giro, $relleno, $tinta])
        <g transform="translate({{ $x }} {{ $y }}) rotate({{ $giro }})">
            <g class="estallar" style="--retraso: {{ 200 + $loop->index * 180 }}ms">
                <g class="vaiven" style="--retraso: {{ $loop->index * 700 }}ms; --giro: {{ $loop->index % 2 ? 2 : -2 }}deg">
                    <rect width="210" height="132" rx="16" fill="url(#{{ $relleno }})"/>
                    <rect x="20" y="40" width="34" height="26" rx="5" fill="{{ $tinta }}" fill-opacity=".25"/>
                    <path d="M180 30a14 14 0 0 1 0 20M186 24a22 22 0 0 1 0 32" stroke="{{ $tinta }}" stroke-opacity=".6" stroke-width="2.5" stroke-linecap="round"/>
                    <rect x="20" y="88" width="120" height="7" rx="3.5" fill="{{ $tinta }}" fill-opacity=".45"/>
                    <rect x="20" y="104" width="70" height="7" rx="3.5" fill="{{ $tinta }}" fill-opacity=".3"/>
                    <text x="20" y="28" font-size="11" font-weight="900" letter-spacing="2.5" fill="{{ $tinta }}" fill-opacity=".8" font-family="inherit">TRANSMETRO</text>
                </g>
            </g>
        </g>
    @endforeach
    @foreach ([0, 1000, 2000] as $retraso)
        <circle cx="610" cy="70" r="26" stroke="#8eb9fc" stroke-width="2" class="onda" style="--retraso: {{ $retraso }}ms"/>
    @endforeach
</svg>
