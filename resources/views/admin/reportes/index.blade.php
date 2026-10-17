@extends('layouts.app')

@section('titulo', 'Reportes')

@section('contenido')
    <x-titulo titulo="Reportes" subtitulo="Estaciones, líneas y buses asignados a cada línea, más la operación del día. Se filtran por línea o municipio y se imprimen o guardan en PDF." />

    @php
        $ayudas = [
            'Lo que pide el enunciado' => 'Reportes de estaciones, líneas y buses asignados a ellas.',
            'Operación' => 'Complementan los anteriores con el recorrido y el movimiento de pasajeros.',
        ];
    @endphp

    @foreach ($reportes as $seccion => $deSeccion)
        <section class="mb-10">
            <div class="mb-4 flex items-end justify-between gap-3 border-b-2 border-noche pb-2">
                <div>
                    <h2 class="text-[22px] font-black leading-none tracking-[-0.03em]">{{ $seccion === 'Lo que pide el enunciado' ? 'Estaciones, líneas y buses' : $seccion }}</h2>
                    <p class="mt-1 text-[12px] text-acero">{{ $ayudas[$seccion] ?? '' }}</p>
                </div>
                <span class="text-[28px] font-black leading-none tracking-[-0.04em] text-acero">{{ $deSeccion->count() }}</span>
            </div>
            <div class="grid gap-4 md:grid-cols-3">
                @foreach ($deSeccion as $clave => $r)
                    <a href="{{ route('admin.reportes.show', $clave) }}" class="caja group flex flex-col transition hover:ring-2 hover:ring-tm-300" data-reporte="{{ $clave }}">
                        <p class="text-[17px] font-extrabold tracking-tight text-noche">{{ $r['titulo'] }}</p>
                        <p class="mt-1 flex-1 text-[12.5px] leading-relaxed text-acero">{{ $r['descripcion'] }}</p>
                        <p class="mt-4 flex items-center justify-between text-[12px] font-bold text-noche">
                            <span>{{ $r['agrupar'] ? 'Agrupado por línea' : 'Toda la red' }} · {{ $r['horizontal'] ? 'hoja horizontal' : 'hoja vertical' }}</span>
                            <svg class="h-5 w-5 transition-transform duration-500 group-hover:translate-x-1" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M4 10h12M11 5l5 5-5 5"/></svg>
                        </p>
                    </a>
                @endforeach
            </div>
        </section>
    @endforeach
@endsection
