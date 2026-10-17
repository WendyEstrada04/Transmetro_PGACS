@props(['tarjeta', 'cara' => 'frente'])
@php
    // Colores de cada tipo de Tarjeta Ciudadana: Vecino (morado), Adulto mayor (amarillo), Persona con discapacidad (celeste).
    $diseno = [
        'vecino' => ['linear-gradient(115deg, #6b5fd3 0%, #3a2f9e 38%, #1f1a6b 100%)', '#ffffff'],
        'adulto_mayor' => ['linear-gradient(115deg, #fff27a 0%, #ffd23f 40%, #ffb627 100%)', '#2a2366'],
        'discapacidad' => ['linear-gradient(115deg, #8fd0ff 0%, #4a97e0 40%, #2a68b0 100%)', '#ffffff'],
    ][$tarjeta->tipo_tarjeta] ?? ['linear-gradient(115deg, #404d63, #162133)', '#ffffff'];
    $titulo = ['vecino' => 'VECINO', 'adulto_mayor' => 'ADULTO MAYOR', 'discapacidad' => 'PERSONA CON DISCAPACIDAD'][$tarjeta->tipo_tarjeta] ?? '';
    $inactiva = $tarjeta->estado !== 'activa';
@endphp
<div {{ $attributes->merge(['class' => 'relative aspect-[1.586] w-full max-w-sm overflow-hidden rounded-2xl p-5 shadow-[0_20px_40px_-18px_rgba(22,33,51,0.55)]'.($inactiva ? ' grayscale' : '')]) }}
     style="background: {{ $diseno[0] }}; color: {{ $diseno[1] }}" data-tarjeta-cara="{{ $cara }}">
    <div class="pointer-events-none absolute -right-16 top-0 h-full w-1/2 skew-x-[-18deg] bg-white/10"></div>
    @if ($cara === 'frente')
        <div class="relative flex h-full flex-col items-center text-center">
            <p class="mt-1 text-[15px] font-black italic tracking-wide">{{ $titulo }}</p>
            {{-- Chip y símbolo sin contacto (genéricos) --}}
            <div class="my-auto flex items-center gap-4">
                <span class="block h-9 w-12 rounded-md bg-gradient-to-br from-amber-200 to-amber-400 shadow-inner"></span>
                <svg class="h-9 w-9 opacity-80" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M8 8a6 6 0 0 1 0 8M12 5a10 10 0 0 1 0 14M16 2.5a14 14 0 0 1 0 19"/></svg>
            </div>
            <p class="text-[13px] font-black leading-tight tracking-wide">TARJETA CIUDADANA</p>
            <p class="text-[12px] font-semibold italic leading-tight tracking-wider opacity-90">CIUDAD INTEGRADA</p>
        </div>
    @else
        <div class="relative flex h-full flex-col">
            <div class="flex justify-end">
                <span class="rounded bg-white/90 px-2 py-0.5 font-mono text-[13px] font-bold tracking-wider text-slate-700" data-numero-impreso>Nº {{ $tarjeta->numeroFormateado() }}</span>
            </div>
            <div class="mt-auto flex items-end justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-[13px] font-black leading-tight tracking-wide">TARJETA CIUDADANA</p>
                    <p class="text-[12px] font-semibold italic leading-tight tracking-wider opacity-90">CIUDAD INTEGRADA</p>
                    <p class="mt-1.5 truncate text-[11px] font-semibold opacity-90">{{ $tarjeta->nombre_titular ?: 'Al portador' }}</p>
                </div>
                {{-- Código QR con el número de la tarjeta (lo lee el validador del molinete) --}}
                <div class="shrink-0 rounded-md bg-white p-1" data-qr="{{ $tarjeta->numero_tarjeta }}" title="Código QR: {{ $tarjeta->numero_tarjeta }}">
                    <span class="block h-[72px] w-[72px]"></span>
                </div>
            </div>
        </div>
    @endif
    @if ($inactiva)
        <span class="absolute right-4 top-1/2 -translate-y-1/2 -rotate-12 rounded-md border-2 border-red-500 bg-white/90 px-2 py-0.5 text-[12px] font-black uppercase tracking-[0.2em] text-red-600">{{ $tarjeta->estado }}</span>
    @endif
</div>

@once
    @push('scripts')
        <script src="{{ asset('js/qrcode.js') }}"></script>
        <script>
            // Dibuja el código QR de cada tarjeta (librería qrcode-generator, MIT, incluida en public/js).
            (() => {
                if (typeof qrcode !== 'function') return;
                document.querySelectorAll('[data-qr]').forEach((caja) => {
                    const qr = qrcode(0, 'M');
                    qr.addData(caja.dataset.qr);
                    qr.make();
                    caja.innerHTML = qr.createSvgTag({cellSize: 3, margin: 0, scalable: true});
                    const svg = caja.querySelector('svg');
                    if (svg) { svg.style.width = '72px'; svg.style.height = '72px'; }
                });
            })();
        </script>
    @endpush
@endonce
