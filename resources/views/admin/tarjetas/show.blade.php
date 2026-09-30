@extends('layouts.app')

@section('titulo', 'Tarjeta '.$tarjeta->numero_tarjeta)

@section('contenido')
    <x-titulo :titulo="'Tarjeta '.$tarjeta->numero_tarjeta" :volver="route('admin.tarjetas.index')"
              :subtitulo="($tipos[$tarjeta->tipo_tarjeta] ?? $tarjeta->tipo_tarjeta).($tarjeta->nombre_titular ? ' · '.$tarjeta->nombre_titular.' (DPI '.$tarjeta->dpi_titular.')' : '')" />

    <div class="mb-6 grid gap-4 md:grid-cols-3">
        <div class="caja">
            <p class="text-xs text-slate-500">Saldo</p>
            <p class="text-3xl font-semibold text-tm-900">Q{{ number_format($tarjeta->saldo, 2) }}</p>
            <p class="mt-1 text-sm">Viajes gratis restantes: <b>{{ $tarjeta->viajes_gratis_restantes }}</b></p>
            <p class="mt-1">@include('admin.tarjetas._estado', ['estado' => $tarjeta->estado])</p>
            <p class="mt-2 text-xs text-slate-500">Emitida el {{ $tarjeta->fecha_emision?->format('d/m/Y') }} · costo Q{{ number_format($tarjeta->costo_emision, 2) }}</p>
        </div>

        <form method="POST" action="{{ route('admin.tarjetas.recargar', $tarjeta) }}" class="caja space-y-3">
            @csrf
            <h2 class="font-semibold text-tm-900">Recargar</h2>
            <x-campo name="monto" label="Monto (Q)" type="number" step="0.01" required
                     :min="\App\Models\Tarjeta::RECARGA_MINIMA" :max="\App\Models\Tarjeta::RECARGA_MAXIMA"
                     ayuda="De Q{{ \App\Models\Tarjeta::RECARGA_MINIMA }} a Q{{ \App\Models\Tarjeta::RECARGA_MAXIMA }}." />
            <x-seleccion name="metodo_pago" label="Forma de pago" :opciones="$metodos" required />
            <button class="btn btn-primario w-full" @disabled($tarjeta->estado !== 'activa')>Recargar</button>
        </form>

        <form method="POST" action="{{ route('admin.tarjetas.estado', $tarjeta) }}" class="caja space-y-3">
            @csrf
            @method('PATCH')
            <h2 class="font-semibold text-tm-900">Estado de la tarjeta</h2>
            <x-seleccion name="estado" label="Estado" :value="$tarjeta->estado" required
                         :opciones="['activa' => 'Activa', 'bloqueada' => 'Bloqueada (robo o extravío)', 'vencida' => 'Vencida']" />
            <button class="btn btn-secundario w-full">Cambiar estado</button>
        </form>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <section>
            <h2 class="mb-3 text-lg font-semibold text-tm-900">Últimas recargas</h2>
            <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
                <table class="tabla">
                    <thead><tr><th>Fecha</th><th>Forma de pago</th><th class="text-right">Monto</th></tr></thead>
                    <tbody>
                        @forelse ($recargas as $recarga)
                            <tr>
                                <td>{{ $recarga->fecha_hora?->format('d/m/Y H:i') }}</td>
                                <td>{{ $metodos[$recarga->metodo_pago] ?? $recarga->metodo_pago }}</td>
                                <td class="text-right">Q{{ number_format($recarga->monto, 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="text-center text-slate-400">Sin recargas.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
        <section>
            <h2 class="mb-3 text-lg font-semibold text-tm-900">Últimos viajes</h2>
            <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
                <table class="tabla">
                    <thead><tr><th>Fecha</th><th>Estación · acceso</th><th>Línea</th><th class="text-right">Cobro</th></tr></thead>
                    <tbody>
                        @forelse ($pagos as $pago)
                            <tr>
                                <td>{{ $pago->fecha_hora?->format('d/m/Y H:i') }}</td>
                                <td>{{ $pago->molinete->acceso->estacion->nombre }} · {{ $pago->molinete->acceso->ubicacion_fisica }}</td>
                                <td>{{ $pago->linea?->codigo_linea ?? '—' }}</td>
                                <td class="text-right">{{ $pago->viaje_gratis ? 'Gratis' : 'Q'.number_format($pago->monto, 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-center text-slate-400">Sin viajes.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
@endsection
