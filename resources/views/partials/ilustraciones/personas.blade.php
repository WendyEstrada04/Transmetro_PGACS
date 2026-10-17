{{-- Ilustración de Personal: una red de personas conectadas (pilotos, operadores y seguridad). --}}
<svg class="pointer-events-none absolute inset-y-0 right-0 hidden h-full w-[62%] max-w-[760px] md:block" viewBox="0 0 640 300" preserveAspectRatio="xMaxYMax slice" fill="none" aria-hidden="true" style="mask-image: linear-gradient(90deg, transparent 0%, #000 35%)">
    @php
        $nodos = [[330, 95, 30], [430, 165, 40], [530, 80, 28], [610, 175, 34], [480, 262, 24], [355, 215, 24], [575, 268, 20], [255, 150, 18], [640, 60, 22]];
        $enlaces = [[0, 1], [1, 2], [1, 3], [1, 4], [0, 5], [5, 4], [3, 6], [2, 8], [0, 7], [3, 8], [4, 6]];
    @endphp
    @foreach ($enlaces as [$a, $b])
        <line x1="{{ $nodos[$a][0] }}" y1="{{ $nodos[$a][1] }}" x2="{{ $nodos[$b][0] }}" y2="{{ $nodos[$b][1] }}" stroke="#404d63" stroke-opacity=".35" stroke-width="2.5"
              class="dibujar" style="--largo: 160; --retraso: {{ 200 + $loop->index * 80 }}ms"/>
    @endforeach
    @foreach ($nodos as [$x, $y, $r])
        <g class="estallar" style="--retraso: {{ 500 + $loop->index * 110 }}ms">
            <g class="vaiven" style="--retraso: {{ $loop->index * 400 }}ms">
                <circle cx="{{ $x }}" cy="{{ $y }}" r="{{ $r }}" fill="{{ $loop->index % 3 === 1 ? '#162133' : '#ffffff' }}"/>
                <circle cx="{{ $x }}" cy="{{ $y - $r * 0.2 }}" r="{{ $r * 0.3 }}" fill="{{ $loop->index % 3 === 1 ? '#8eb9fc' : '#404d63' }}"/>
                <path d="M{{ $x - $r * 0.55 }} {{ $y + $r * 0.62 }} a{{ $r * 0.55 }} {{ $r * 0.48 }} 0 0 1 {{ $r * 1.1 }} 0" fill="{{ $loop->index % 3 === 1 ? '#8eb9fc' : '#404d63' }}"/>
            </g>
        </g>
    @endforeach
</svg>
