@props(['titulo', 'subtitulo' => null, 'volver' => null])

<div class="mb-6">
    @if ($volver)
        <a href="{{ $volver }}" class="enlace text-sm">← Volver</a>
    @endif
    <div class="mt-1 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold text-tm-900">{{ $titulo }}</h1>
            @if ($subtitulo)
                <p class="text-sm text-slate-500">{{ $subtitulo }}</p>
            @endif
        </div>
        <div class="flex flex-wrap gap-2">{{ $slot }}</div>
    </div>
</div>
