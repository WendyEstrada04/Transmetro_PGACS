@extends('layouts.app')

@section('titulo', 'Personal')

@section('contenido')
    @php
        // Cada rol con su color de la paleta (avatar y filtro).
        $estilos = [
            'piloto' => ['#8eb9fc', '#162133', 'Conducen los buses'],
            'operador' => ['#162133', '#ffffff', 'Uno por estación, en su PC de trabajo'],
            'seguridad' => ['#99acc9', '#162133', 'Guardias en los accesos'],
        ];
        $iniciales = fn ($nombre) => collect(preg_split('/\s+/', trim($nombre)))->filter()->take(2)->map(fn ($p) => mb_substr($p, 0, 1))->implode('');
        $rolActual = request('rol');
    @endphp

    <x-titulo titulo="Personal" subtitulo="Pilotos, operadores y agentes de seguridad de la red.">
        <a href="{{ route('admin.personal.create', ['rol' => $rolActual]) }}" class="btn btn-primario">+ Nuevo empleado</a>
    </x-titulo>

    {{-- Filtro por rol: cuatro bloques grandes con su total --}}
    <nav class="grid grid-cols-2 gap-3 md:grid-cols-4">
        @foreach (['' => ['Todo el equipo', array_sum($conteos), '#404d63', 'Ver a todos']] + collect(['piloto' => 'Pilotos', 'operador' => 'Operadores', 'seguridad' => 'Seguridad'])->mapWithKeys(fn ($t, $r) => [$r => [$t, $conteos[$r] ?? 0, $estilos[$r][0], $estilos[$r][2]]])->all() as $rol => [$texto, $total, $color, $ayuda])
            @php $activo = (string) $rolActual === (string) $rol; @endphp
            <a href="{{ route('admin.personal.index', array_filter(['rol' => $rol, 'buscar' => request('buscar')])) }}"
               class="group relative overflow-hidden rounded-xl p-5 transition-all duration-500 hover:-translate-y-1 {{ $activo ? 'bg-noche text-white shadow-md' : 'bg-white shadow-sm hover:shadow-md' }}">
                <span class="absolute inset-x-0 top-0 h-1 origin-left transition-transform duration-500 {{ $activo ? '' : 'scale-x-0 group-hover:scale-x-100' }}" style="background: {{ $color }}"></span>
                <p class="text-[44px] font-black leading-none tracking-[-0.05em]" data-contar="{{ $total }}">{{ $total }}</p>
                <p class="mt-3 text-[15px] font-extrabold">{{ $texto }}</p>
                <p class="text-[12px] {{ $activo ? 'text-niebla' : 'text-acero' }}">{{ $ayuda }}</p>
            </a>
        @endforeach
    </nav>

    {{-- Búsqueda --}}
    <form method="GET" class="mt-10 flex items-end gap-4 border-b-2 border-noche pb-2">
        @if ($rolActual)<input type="hidden" name="rol" value="{{ $rolActual }}">@endif
        <svg class="mb-2.5 h-6 w-6 shrink-0 text-acero" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
        <label class="flex-1">
            <span class="rotulo !text-[10px] text-acero">Buscar por nombre o DPI</span>
            <input name="buscar" value="{{ request('buscar') }}" placeholder="Escribe un nombre…"
                   class="mt-1 w-full border-0 bg-transparent p-0 text-[22px] font-extrabold tracking-tight text-noche placeholder:text-niebla focus:ring-0 focus:outline-none">
        </label>
        <button class="btn btn-primario btn-chico mb-1.5">Buscar</button>
    </form>
    <p class="mt-3 text-[12px] text-acero">{{ $empleados->total() }} {{ $empleados->total() === 1 ? 'persona' : 'personas' }}{{ request('buscar') ? ' para “'.request('buscar').'”' : '' }}</p>

    {{-- Directorio --}}
    <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @forelse ($empleados as $empleado)
            @php [$fondo, $tinta] = $estilos[$empleado->rol] ?? ['#e6edf9', '#162133']; @endphp
            <article class="revelar group flex flex-col rounded-xl bg-white p-5 shadow-sm transition-all duration-500 hover:-translate-y-1 hover:shadow-md"
                     style="--retraso: {{ ($loop->index % 3) * 70 }}ms">
                <div class="flex items-start justify-between">
                    <span class="flex h-14 w-14 items-center justify-center rounded-full text-[17px] font-black transition-transform duration-500 group-hover:scale-110"
                          style="background: {{ $fondo }}; color: {{ $tinta }}">{{ $iniciales($empleado->nombre_completo) }}</span>
                    <span class="flex items-center gap-1.5 text-[11px] font-bold {{ $empleado->activo ? 'text-emerald-700' : 'text-acero' }}">
                        <span class="h-1.5 w-1.5 rounded-full {{ $empleado->activo ? 'bg-emerald-500' : 'bg-niebla' }}"></span>
                        {{ $empleado->activo ? 'Activo' : 'Inactivo' }}
                    </span>
                </div>
                <p class="rotulo mt-4 !text-[10px] text-acero">{{ $roles[$empleado->rol] ?? $empleado->rol }}</p>
                <h3 class="mt-1 text-[18px] font-extrabold leading-tight tracking-tight">{{ $empleado->nombre_completo }}</h3>
                <p class="mt-1 text-[12px] tabular-nums text-acero">DPI {{ $empleado->dpi }}</p>
                <p class="mt-4 flex items-center gap-2 border-t border-noche/[0.07] pt-3 text-[13px] font-semibold text-pizarra">
                    <svg class="h-4 w-4 shrink-0 text-acero" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M10 18s6-5.2 6-10a6 6 0 1 0-12 0c0 4.8 6 10 6 10Z"/><circle cx="10" cy="8" r="2"/></svg>
                    {{ $empleado->asignacion }}
                </p>
                @if ($empleado->usuario_email)
                    <p class="mt-1 truncate pl-6 text-[11.5px] text-acero">Usuario: {{ $empleado->usuario_email }}</p>
                @endif
                <div class="mt-auto flex items-center justify-between pt-5">
                    <a href="{{ route('admin.personal.edit', $empleado) }}" class="enlace text-[13px]">Editar ficha →</a>
                    <x-eliminar :accion="route('admin.personal.destroy', $empleado)" confirmar="¿Eliminar a este empleado?" />
                </div>
            </article>
        @empty
            <p class="col-span-full py-16 text-center text-[15px] text-acero">No se encontraron empleados.</p>
        @endforelse
    </div>
    <div class="mt-8">{{ $empleados->links() }}</div>
@endsection
