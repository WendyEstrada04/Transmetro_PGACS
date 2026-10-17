@extends('layouts.app')

@section('titulo', $reporte['titulo'])

@php
    use App\Services\Reportes;

    $numericos = ['numero', 'km', 'pct', 'moneda'];
    $agrupar = $reporte['agrupar'];
    $columnas = collect($reporte['columnas'])->except($agrupar ? [$agrupar] : []);
    $grupos = $agrupar ? collect($filas)->groupBy($agrupar, true) : collect(['' => collect($filas)]);
    $textoFiltros = collect($filtros)->map(fn ($v, $k) => ($k === 'linea' ? 'Línea ' : 'Municipio ').$v)->join(' · ');
    $generado = now()->format('d/m/Y H:i');
@endphp

@push('head')
    <style>
        .reporte-tabla { width: 100%; border-collapse: collapse; font-size: 13px; }
        .reporte-tabla th { text-align: left; font-size: 10.5px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase;
                            color: #7e8896; padding: 10px 12px; border-bottom: 2px solid #162133; white-space: nowrap; }
        .reporte-tabla td { padding: 8px 12px; border-bottom: 1px solid rgb(22 33 51 / .07); vertical-align: top; white-space: nowrap; }
        .reporte-tabla td.envolver { white-space: normal; min-width: 14rem; }
        .reporte-tabla tbody tr:nth-child(even):not(.fila-grupo) td { background: rgb(22 33 51 / .025); }
        .reporte-tabla .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .reporte-tabla .fila-grupo td { padding: 14px 12px 8px; border-bottom: 2px solid var(--c, #162133); background: #fff; }
        .marca-linea { display: inline-flex; align-items: center; justify-content: center; min-width: 2.4em; padding: 1px 7px; border-radius: 6px;
                       font-weight: 900; color: var(--t, #fff); background: var(--c, #162133); }
        .estado-ok { color: #2f6f4f; background: #e3f2ea; }
        .estado-alerta { color: #b42318; background: #fde8e7; }
        .solo-impresion { display: none; }

        @page {
            size: A4 {{ $reporte['horizontal'] ? 'landscape' : 'portrait' }};
            margin: 14mm 12mm 16mm;
            @bottom-left { content: "Transmetro · Sistema de Control — {{ $reporte['titulo'] }}"; font: 7.5pt sans-serif; color: #7e8896; }
            @bottom-right { content: "Página " counter(page) " de " counter(pages); font: 7.5pt sans-serif; color: #7e8896; }
        }
        @media print {
            header.barra, footer, section.banda, .no-imprimir, [data-aviso] { display: none !important; }
            .solo-impresion { display: block !important; }
            html, body { background: #fff !important; }
            * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            main.contenido { max-width: none !important; padding: 0 !important; }
            .revelar, .aparecer, .mascara > span { opacity: 1 !important; transform: none !important; animation: none !important; }
            .hoja { box-shadow: none !important; border-radius: 0 !important; padding: 0 !important; }
            .reporte-tabla { font-size: 8.5pt; }
            .reporte-tabla th { font-size: 7pt; padding: 5px 6px; }
            .reporte-tabla td { padding: 4px 6px; white-space: normal; }
            .reporte-tabla td.envolver { min-width: 0; }
            .reporte-tabla th { white-space: normal; }
            .reporte-tabla .num { white-space: nowrap; }
            .overflow-x-auto { overflow: visible !important; }
            .reporte-tabla thead { display: table-header-group; }
            .reporte-tabla tr { break-inside: avoid; }
            .reporte-tabla .fila-grupo { break-after: avoid; }
            .resumen { margin-bottom: 5mm !important; }
            .resumen > div { padding: 3mm 4mm !important; }
            .resumen .valor { font-size: 15pt !important; }
        }
    </style>
@endpush

@section('contenido')
    <x-titulo :titulo="$reporte['titulo']" :subtitulo="$reporte['descripcion']" :volver="route('admin.reportes.index')" seccion="Reportes">
        <button type="button" onclick="window.print()" class="btn btn-primario no-imprimir">Imprimir o guardar en PDF</button>
    </x-titulo>

    @if ($reporte['filtros'])
        <form method="GET" class="no-imprimir mb-6 flex flex-wrap items-end gap-3 rounded-xl bg-white p-4 shadow-sm">
            @if (in_array('linea', $reporte['filtros'], true))
                <label class="text-sm">
                    <span class="rotulo mb-1.5 block !text-[10px] text-acero">Línea</span>
                    <select name="linea" class="rounded-lg border border-niebla/50 bg-white px-3 py-2 text-sm">
                        <option value="">Todas las líneas</option>
                        @foreach ($lineas as $codigo => $texto)
                            <option value="{{ $codigo }}" @selected(($filtros['linea'] ?? null) === $codigo)>{{ $texto }}</option>
                        @endforeach
                    </select>
                </label>
            @endif
            @if (in_array('municipio', $reporte['filtros'], true))
                <label class="text-sm">
                    <span class="rotulo mb-1.5 block !text-[10px] text-acero">Municipio</span>
                    <select name="municipio" class="rounded-lg border border-niebla/50 bg-white px-3 py-2 text-sm">
                        <option value="">Todos los municipios</option>
                        @foreach ($municipios as $nombre)
                            <option value="{{ $nombre }}" @selected(($filtros['municipio'] ?? null) === $nombre)>{{ $nombre }}</option>
                        @endforeach
                    </select>
                </label>
            @endif
            <button type="submit" class="btn btn-secundario">Filtrar</button>
            @if ($filtros)<a href="{{ route('admin.reportes.show', $clave) }}" class="enlace text-sm">Quitar filtros</a>@endif
        </form>
    @endif

    <div class="hoja rounded-xl bg-white p-6 shadow-sm md:p-8">
        {{-- Encabezado del documento (solo al imprimir o guardar en PDF) --}}
        <div class="solo-impresion mb-5 border-b-2 border-noche pb-4">
            <div class="flex items-start justify-between gap-6">
                <div class="flex items-center gap-3">
                    <svg class="h-10 w-10" viewBox="0 0 40 40" fill="none" aria-hidden="true">
                        <rect width="40" height="40" rx="11" fill="#162133"/>
                        <path d="M10 28c6 0 6-16 12-16s6 10 8 10" stroke="#8eb9fc" stroke-width="3.2" stroke-linecap="round"/>
                        <circle cx="10" cy="28" r="3.4" fill="#fff"/><circle cx="30" cy="22" r="3.4" fill="#fff"/>
                    </svg>
                    <div>
                        <p class="text-[13pt] font-black tracking-[0.2em]">TRANSMETRO</p>
                        <p class="text-[8pt] tracking-[0.18em] text-acero">SISTEMA DE CONTROL · MUNICIPALIDAD DE GUATEMALA</p>
                    </div>
                </div>
                <div class="text-right text-[8pt] leading-relaxed text-acero">
                    <p>Generado el <b class="text-noche">{{ $generado }}</b></p>
                    <p>por {{ auth()->user()->name }}</p>
                </div>
            </div>
            <p class="mt-5 text-[8pt] font-bold uppercase tracking-[0.18em] text-acero">Reporte</p>
            <h1 class="text-[20pt] font-black leading-tight tracking-tight">{{ $reporte['titulo'] }}</h1>
            <p class="mt-1 text-[9pt] text-pizarra">{{ $reporte['descripcion'] }}</p>
            <p class="mt-1 text-[8.5pt] text-acero">{{ $textoFiltros ?: 'Toda la red' }} · {{ count($filas) }} {{ count($filas) === 1 ? 'registro' : 'registros' }}</p>
        </div>

        {{-- Cifras clave --}}
        @if ($resumen)
            <div class="resumen mb-8 grid gap-px overflow-hidden rounded-xl bg-noche/[0.08]" style="grid-template-columns: repeat({{ count($resumen) }}, minmax(0, 1fr))">
                @foreach ($resumen as $cifra)
                    <div class="bg-papel p-5">
                        <p class="rotulo !text-[9.5px] text-acero">{{ $cifra[0] }}</p>
                        <p class="valor mt-1.5 text-[26px] font-black leading-none tracking-[-0.03em] tabular-nums">{{ $cifra[1] }}</p>
                        @isset($cifra[2])<p class="mt-1.5 text-[11px] leading-snug text-acero">{{ $cifra[2] }}</p>@endisset
                    </div>
                @endforeach
            </div>
        @endif

        <p class="no-imprimir mb-3 text-[12.5px] text-acero">
            {{ $textoFiltros ?: 'Toda la red' }} · {{ count($filas) }} {{ count($filas) === 1 ? 'registro' : 'registros' }} · Generado el {{ $generado }}
        </p>

        <div class="overflow-x-auto">
            <table class="reporte-tabla" data-reporte="{{ $clave }}">
                <thead>
                    <tr>
                        @foreach ($columnas as [$titulo, $tipo])
                            <th class="{{ in_array($tipo, $numericos, true) ? 'num' : '' }}">{{ $titulo }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse ($grupos as $grupo => $deGrupo)
                        @if ($agrupar)
                            <tr class="fila-grupo" style="--c: {{ $colores[$grupo][0] ?? '#7e8896' }}; --t: {{ $colores[$grupo][1] ?? '#ffffff' }}">
                                <td colspan="{{ $columnas->count() }}">
                                    <span class="flex items-center gap-2.5">
                                        <span class="marca-linea text-[12px]">{{ $grupo }}</span>
                                        @isset($lineas[$grupo])<b class="text-[13.5px]">{{ \Illuminate\Support\Str::after($lineas[$grupo], ' · ') }}</b>@endisset
                                        <span class="text-[12px] text-acero">· {{ $deGrupo->count() }} {{ $deGrupo->count() === 1 ? 'registro' : 'registros' }}</span>
                                    </span>
                                </td>
                            </tr>
                        @endif
                        @foreach ($deGrupo as $fila)
                            <tr>
                                @foreach ($columnas as $columna => [$titulo, $tipo])
                                    @php $valor = $fila[$columna] ?? null; @endphp
                                    <td class="{{ in_array($tipo, $numericos, true) ? 'num' : '' }} {{ $columna === 'direccion' ? 'envolver' : '' }}">
                                        @if ($valor === null || $valor === '')
                                            <span class="text-niebla">—</span>
                                        @elseif ($tipo === 'numero')
                                            {{ number_format((float) $valor, fmod((float) $valor, 1) ? 1 : 0) }}
                                        @elseif ($tipo === 'km')
                                            {{ number_format((float) $valor, 2) }} km
                                        @elseif ($tipo === 'pct')
                                            {{ number_format((float) $valor, 1) }} %
                                        @elseif ($tipo === 'moneda')
                                            Q{{ number_format((float) $valor, 2) }}
                                        @elseif ($tipo === 'fecha')
                                            {{ \Illuminate\Support\Carbon::parse($valor)->format('d/m/Y H:i') }}
                                        @elseif ($tipo === 'hora')
                                            {{ \Illuminate\Support\Carbon::parse($valor)->format('H:i') }}
                                        @elseif ($tipo === 'estado')
                                            <span class="etiqueta {{ in_array($valor, ['Cumple', 'Normal', 'Cuadra'], true) ? 'estado-ok' : 'estado-alerta' }}">{{ $valor }}</span>
                                        @elseif ($columna === 'linea' || $columna === 'codigo')
                                            <span class="marca-linea text-[11px]" style="--c: {{ $colores[$valor][0] ?? '#7e8896' }}; --t: {{ $colores[$valor][1] ?? '#ffffff' }}">{{ $valor }}</span>
                                        @else
                                            {{ Reportes::ETIQUETAS[$valor] ?? $valor }}
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    @empty
                        <tr><td colspan="{{ $columnas->count() }}" class="py-8 text-center text-acero">No hay datos con estos filtros.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <p class="solo-impresion mt-4 text-[7.5pt] text-acero">Fuente: base de datos del Sistema de Control Transmetro. Datos al {{ $generado }}.</p>
    </div>
@endsection
