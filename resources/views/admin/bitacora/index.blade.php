@extends('layouts.app')

@section('titulo', 'Bitácora de auditoría')

@section('contenido')
    @php
        // Valor legible: sí/no, vacío, estados con guion bajo ("en_mantenimiento" → "En mantenimiento").
        $valor = function ($v) use ($valores) {
            if (is_bool($v)) return $v ? 'Sí' : 'No';
            if ($v === null || $v === '') return '—';
            if (is_array($v)) return json_encode($v, JSON_UNESCAPED_UNICODE);
            $t = (string) $v;
            if (isset($valores[$t])) return $valores[$t];
            return preg_match('/^[a-z]+(_[a-z]+)+$|^[a-z]+$/', $t) ? \Illuminate\Support\Str::ucfirst(str_replace('_', ' ', $t)) : $t;
        };
        $estiloAccion = ['INSERT' => 'bg-emerald-100 text-emerald-800', 'UPDATE' => 'bg-sky-100 text-sky-800', 'DELETE' => 'bg-red-100 text-red-700'];
        $hayFiltros = $filtros !== [];
    @endphp

    <x-titulo titulo="Bitácora de auditoría"
              subtitulo="Quién realizó cada operación, en qué módulo, sobre qué registro, qué cambió y cuándo. La bitácora es de solo lectura: la base de datos impide modificarla o borrarla." />

    {{-- Cifras del filtro actual --}}
    <dl class="grid grid-cols-2 gap-3 md:grid-cols-5">
        @foreach ([['Registros', $resumen->total], ['Hoy', $resumen->hoy], ['Registros nuevos', $resumen->altas], ['Cambios', $resumen->cambios], ['Eliminaciones', $resumen->bajas]] as [$texto, $n])
            <div class="rounded-xl bg-white p-4 shadow-sm">
                <dt class="rotulo !text-[10px] text-acero">{{ $texto }}</dt>
                <dd class="mt-1 text-[28px] font-black tabular-nums leading-none tracking-[-0.03em]" data-cifra="{{ \Illuminate\Support\Str::slug($texto) }}">{{ number_format((int) $n) }}</dd>
            </div>
        @endforeach
    </dl>

    {{-- Filtros --}}
    <form method="GET" class="mt-6 grid gap-3 rounded-xl bg-white p-4 shadow-sm sm:grid-cols-2 lg:grid-cols-[repeat(5,minmax(0,1fr))_auto]" id="filtros-bitacora">
        <label class="text-sm">
            <span class="rotulo mb-1.5 block !text-[10px] text-acero">Módulo</span>
            <select name="modulo" class="w-full rounded-lg border border-niebla/50 bg-white px-3 py-2 text-sm">
                <option value="">Todos</option>
                @foreach ($modulos as $clave => $texto)
                    <option value="{{ $clave }}" @selected(($filtros['modulo'] ?? null) === $clave)>{{ $texto }}</option>
                @endforeach
            </select>
        </label>
        <label class="text-sm">
            <span class="rotulo mb-1.5 block !text-[10px] text-acero">Acción</span>
            <select name="accion" class="w-full rounded-lg border border-niebla/50 bg-white px-3 py-2 text-sm">
                <option value="">Todas</option>
                @foreach ($acciones as $clave => $texto)
                    <option value="{{ $clave }}" @selected(($filtros['accion'] ?? null) === $clave)>{{ $texto }}</option>
                @endforeach
            </select>
        </label>
        <label class="text-sm">
            <span class="rotulo mb-1.5 block !text-[10px] text-acero">Usuario</span>
            <select name="usuario" class="w-full rounded-lg border border-niebla/50 bg-white px-3 py-2 text-sm">
                <option value="">Todos</option>
                <option value="sistema" @selected(($filtros['usuario'] ?? null) === 'sistema')>Sistema (automático)</option>
                @foreach ($usuarios as $u)
                    <option value="{{ $u->id }}" @selected((string) ($filtros['usuario'] ?? '') === (string) $u->id)>{{ $u->name }} · {{ $u->rol }}</option>
                @endforeach
            </select>
        </label>
        <div class="grid grid-cols-2 gap-2">
            <label class="text-sm">
                <span class="rotulo mb-1.5 block !text-[10px] text-acero">Desde</span>
                <input type="date" name="desde" value="{{ $filtros['desde'] ?? '' }}" class="w-full rounded-lg border border-niebla/50 bg-white px-2 py-2 text-sm">
            </label>
            <label class="text-sm">
                <span class="rotulo mb-1.5 block !text-[10px] text-acero">Hasta</span>
                <input type="date" name="hasta" value="{{ $filtros['hasta'] ?? '' }}" class="w-full rounded-lg border border-niebla/50 bg-white px-2 py-2 text-sm">
            </label>
        </div>
        <label class="text-sm">
            <span class="rotulo mb-1.5 block !text-[10px] text-acero">Buscar</span>
            <input type="search" name="buscar" value="{{ $filtros['buscar'] ?? '' }}" placeholder="Placa, nombre, estado…" class="w-full rounded-lg border border-niebla/50 bg-white px-3 py-2 text-sm">
        </label>
        <div class="flex items-end gap-3">
            <button type="submit" class="btn btn-secundario">Filtrar</button>
            @if ($hayFiltros)<a href="{{ route('admin.bitacora.index') }}" class="enlace whitespace-nowrap text-sm">Quitar filtros</a>@endif
        </div>
    </form>

    {{-- Registros --}}
    <div class="mt-8 overflow-hidden rounded-xl bg-white shadow-sm">
        <div class="hidden grid-cols-[150px_200px_150px_minmax(0,1fr)_minmax(0,1.4fr)] gap-4 border-b border-noche/10 px-5 py-3 lg:grid">
            <span class="rotulo !text-[10px] text-acero">Fecha y hora</span>
            <span class="rotulo !text-[10px] text-acero">Usuario</span>
            <span class="rotulo !text-[10px] text-acero">Módulo</span>
            <span class="rotulo !text-[10px] text-acero">Registro</span>
            <span class="rotulo !text-[10px] text-acero">Qué cambió</span>
        </div>
        <ul id="lista-bitacora">
            @forelse ($registros as $r)
                @php
                    $usuario = $r->usuario;
                    $estacionOperador = $usuario?->empleado?->operador?->estacion?->nombre;
                    $antes = $r->datos_previos ?? [];
                    $despues = $r->datos_nuevos ?? [];
                    $nombre = $r->id_registro_afectado ? ($nombres[$r->tabla_afectada.'|'.$r->id_registro_afectado] ?? null) : null;
                    $nombre ??= $antes['placa'] ?? $despues['placa'] ?? $antes['nombre_completo'] ?? $despues['nombre_completo'] ?? $despues['estacion'] ?? $antes['estacion'] ?? null;
                    $nombre ??= $despues['_registro'] ?? $antes['_registro'] ?? null;
                    $nombre ??= $r->id_registro_afectado ? 'Registro '.substr($r->id_registro_afectado, 0, 8) : 'Varios registros';
                    // Los campos que empiezan con "_" son datos de referencia (por ejemplo el nombre del registro), no cambios.
                    $claves = array_values(array_filter(array_unique(array_merge(array_keys($antes), array_keys($despues))), fn ($c) => ! str_starts_with($c, '_')));
                @endphp
                <li class="fila-bitacora grid gap-2 border-b border-noche/[0.06] px-5 py-4 text-[13.5px] lg:grid-cols-[150px_200px_150px_minmax(0,1fr)_minmax(0,1.4fr)] lg:gap-4">
                    <div class="tabular-nums">
                        <span class="block font-bold">{{ $r->fecha_registro?->format('d/m/Y') }}</span>
                        <span class="text-acero">{{ $r->fecha_registro?->format('H:i:s') }}</span>
                    </div>
                    <div class="min-w-0">
                        @if ($usuario)
                            <span class="block truncate font-bold">{{ $usuario->name }}</span>
                            <span class="text-[12px] text-acero">{{ $usuario->esAdministrador() ? 'Administrador' : 'Operador' }}{{ $estacionOperador ? ' · '.$estacionOperador : '' }}</span>
                        @else
                            <span class="block font-bold">Sistema</span>
                            <span class="text-[12px] text-acero">Automático (central o simulación)</span>
                        @endif
                    </div>
                    <div>
                        <span class="block font-bold">{{ $modulos[$r->tabla_afectada] ?? $valor($r->tabla_afectada) }}</span>
                        <span class="mt-1 inline-block rounded-full px-2 py-0.5 text-[11px] font-extrabold {{ $estiloAccion[$r->accion] ?? 'bg-noche/[0.06]' }}">{{ $acciones[$r->accion] ?? $r->accion }}</span>
                    </div>
                    <div class="min-w-0 font-semibold">{{ $nombre }}</div>
                    <dl class="grid gap-1">
                        @foreach ($claves as $campo)
                            @php $a = $antes[$campo] ?? null; $d = $despues[$campo] ?? null; @endphp
                            <div class="flex flex-wrap items-baseline gap-x-2">
                                <dt class="text-[12px] text-acero">{{ $campos[$campo] ?? $valor($campo) }}:</dt>
                                <dd>
                                    @if ($r->accion === 'UPDATE')
                                        <span class="text-acero line-through decoration-acero/50">{{ $valor($a) }}</span>
                                        <span class="text-acero">→</span>
                                        <span class="font-bold">{{ $valor($d) }}</span>
                                    @else
                                        <span class="font-bold">{{ $valor($r->accion === 'DELETE' ? $a : $d) }}</span>
                                    @endif
                                </dd>
                            </div>
                        @endforeach
                        @if ($claves === [])<span class="text-acero">—</span>@endif
                    </dl>
                </li>
            @empty
                <li class="px-5 py-16 text-center text-[15px] text-acero">No hay operaciones registradas con este filtro.</li>
            @endforelse
        </ul>
    </div>
    <div class="mt-8">{{ $registros->links() }}</div>
@endsection
