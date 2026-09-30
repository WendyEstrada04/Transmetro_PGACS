@extends('layouts.app')

@section('titulo', 'Tarjetas')

@section('contenido')
    <x-titulo titulo="Tarjeta Ciudadana" subtitulo="Tarjetas de acceso al Transmetro, saldo, recargas y viajes (punto 18).">
        <a href="{{ route('admin.tarjetas.create') }}" class="btn btn-primario">+ Emitir tarjeta</a>
    </x-titulo>

    <form method="GET" class="mb-4 flex flex-wrap items-end gap-2">
        <div class="w-64"><x-campo name="buscar" label="Número o titular" :value="request('buscar')" /></div>
        <div class="w-56"><x-seleccion name="tipo" label="Tipo" :opciones="$tipos" :value="request('tipo')" vacio="Todos" /></div>
        <button class="btn btn-secundario">Buscar</button>
    </form>

    <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
        <table class="tabla">
            <thead><tr><th>Número</th><th>Tipo</th><th class="text-right">Saldo</th><th class="text-right">Viajes gratis</th><th>Estado</th><th class="text-right">Viajes</th><th>Emitida</th><th></th></tr></thead>
            <tbody>
                @forelse ($tarjetas as $tarjeta)
                    <tr>
                        <td class="font-mono">{{ $tarjeta->numero_tarjeta }}</td>
                        <td>
                            {{ $tipos[$tarjeta->tipo_tarjeta] ?? $tarjeta->tipo_tarjeta }}
                            @if ($tarjeta->nombre_titular)<span class="block text-xs text-slate-500">{{ $tarjeta->nombre_titular }}</span>@endif
                        </td>
                        <td class="text-right">Q{{ number_format($tarjeta->saldo, 2) }}</td>
                        <td class="text-right">{{ $tarjeta->viajes_gratis_restantes }}</td>
                        <td>@include('admin.tarjetas._estado', ['estado' => $tarjeta->estado])</td>
                        <td class="text-right">{{ $tarjeta->transacciones_count }}</td>
                        <td>{{ $tarjeta->fecha_emision?->format('d/m/Y') }}</td>
                        <td class="text-right"><a href="{{ route('admin.tarjetas.show', $tarjeta) }}" class="btn btn-secundario btn-chico">Ver / recargar</a></td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center text-slate-400">No hay tarjetas.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $tarjetas->links() }}</div>
@endsection
