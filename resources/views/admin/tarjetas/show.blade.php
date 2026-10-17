@extends('layouts.app')

@section('titulo', 'Tarjeta '.$tarjeta->numeroFormateado())

@section('contenido')
    @php
        use App\Models\Tarjeta;
        $edad = $tarjeta->fecha_nacimiento_titular?->age;
    @endphp
    <x-titulo :titulo="'Tarjeta '.$tarjeta->numeroFormateado()" :volver="route('admin.tarjetas.index')"
              :subtitulo="($tipos[$tarjeta->tipo_tarjeta] ?? $tarjeta->tipo_tarjeta).' · '.($tarjeta->afiliada ? $tarjeta->nombre_titular.' (DPI '.$tarjeta->dpi_titular.($edad !== null ? ', '.$edad.' años' : '').')' : 'al portador')" />

    {{-- La tarjeta por los dos lados: el número y el código QR van atrás --}}
    <div class="mb-8 grid gap-6 sm:grid-cols-2 lg:max-w-3xl">
        <x-tarjeta-ciudadana :tarjeta="$tarjeta" cara="frente" />
        <x-tarjeta-ciudadana :tarjeta="$tarjeta" cara="reverso" />
    </div>

    <div class="mb-6 grid gap-4 md:grid-cols-3">
        <div class="caja">
            <p class="text-xs text-slate-500">Saldo</p>
            <p class="text-3xl font-semibold text-tm-900">Q{{ number_format($tarjeta->saldo, 2) }}</p>
            @if ($tarjeta->tipo_tarjeta === 'adulto_mayor')
                <p class="mt-1 text-sm" data-cortesias>Viajes de cortesía que le quedan hoy: <b>{{ $cortesiasHoy }} de {{ Tarjeta::CORTESIA_DIARIA_ADULTO_MAYOR }}</b> <span class="text-xs text-slate-500">(no se acumulan)</span></p>
            @else
                <p class="mt-1 text-sm">Viajes gratis restantes: <b>{{ $tarjeta->viajes_gratis_restantes }}</b></p>
            @endif
            <p class="mt-2 text-xs text-slate-500">Emitida el {{ $tarjeta->fecha_emision?->format('d/m/Y') }} · {{ (float) $tarjeta->costo_emision > 0 ? 'costo Q'.number_format($tarjeta->costo_emision, 2) : 'sin costo' }}</p>
            <p class="mt-1 text-xs text-slate-500">{{ Tarjeta::DESCRIPCIONES[$tarjeta->tipo_tarjeta] ?? '' }}</p>
            @if ($tarjeta->anterior)
                <p class="mt-2 text-xs">Repone a la tarjeta <a class="enlace" href="{{ route('admin.tarjetas.show', $tarjeta->anterior) }}">{{ $tarjeta->anterior->numeroFormateado() }}</a></p>
            @endif
            @if ($tarjeta->reposicion)
                <p class="mt-2 text-xs">Su saldo pasó a la tarjeta <a class="enlace" href="{{ route('admin.tarjetas.show', $tarjeta->reposicion) }}">{{ $tarjeta->reposicion->numeroFormateado() }}</a></p>
            @endif
        </div>

        {{-- Control de la tarjeta: bloquear, desbloquear y dar de baja --}}
        <section class="caja md:col-span-2" data-control-tarjeta>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="font-semibold text-tm-900">Control de la tarjeta</h2>
                @include('admin.tarjetas._estado', ['estado' => $tarjeta->estado])
            </div>
            @if ($tarjeta->estado !== 'activa' && ($tarjeta->motivo_estado || $tarjeta->fecha_estado))
                <p class="mt-2 text-sm text-pizarra" data-motivo-estado>
                    {{ $tarjeta->estadoTexto() }}{{ $tarjeta->fecha_estado ? ' el '.$tarjeta->fecha_estado->format('d/m/Y H:i') : '' }}{{ $tarjeta->motivo_estado ? ' · '.$tarjeta->motivo_estado : '' }}
                </p>
            @endif

            @if ($tarjeta->estadoDefinitivo())
                <p class="mt-4 rounded-lg bg-slate-50 px-4 py-3 text-sm text-pizarra">
                    Estado definitivo: la tarjeta no se puede volver a activar ni usar para pagar.
                    @if ($tarjeta->reposicion) Su saldo pasó a la tarjeta <a class="enlace" href="{{ route('admin.tarjetas.show', $tarjeta->reposicion) }}">{{ $tarjeta->reposicion->numeroFormateado() }}</a>. @endif
                </p>
            @else
                <div class="mt-4 grid gap-5 md:grid-cols-2">
                    @if ($tarjeta->estado === 'activa')
                        <form method="POST" action="{{ route('admin.tarjetas.bloquear', $tarjeta) }}" class="space-y-3">
                            @csrf
                            @method('PATCH')
                            <h3 class="text-sm font-bold text-noche">Bloquear</h3>
                            <p class="text-xs text-acero">La tarjeta deja de funcionar en los molinetes y no se puede recargar. Se puede desbloquear después.</p>
                            <x-seleccion name="motivo" label="Motivo" :opciones="Tarjeta::MOTIVOS_BLOQUEO" vacio="Elige el motivo…" required />
                            <x-campo name="observacion" label="Observación (opcional)" maxlength="120" placeholder="Ej.: denuncia recibida por teléfono" />
                            <button class="btn btn-primario w-full">Bloquear tarjeta</button>
                        </form>
                    @elseif ($tarjeta->estado === 'bloqueada')
                        <form method="POST" action="{{ route('admin.tarjetas.desbloquear', $tarjeta) }}" class="space-y-3">
                            @csrf
                            @method('PATCH')
                            <h3 class="text-sm font-bold text-noche">Desbloquear</h3>
                            <p class="text-xs text-acero">La tarjeta vuelve a funcionar con su saldo de Q{{ number_format($tarjeta->saldo, 2) }}.</p>
                            <button class="btn btn-primario w-full">Desbloquear tarjeta</button>
                        </form>
                    @else
                        <div class="space-y-2">
                            <h3 class="text-sm font-bold text-noche">Tarjeta vencida</h3>
                            <p class="text-xs text-acero">No funciona en los molinetes. El titular la renueva en un punto de atención POCAE.</p>
                        </div>
                    @endif

                    <form method="POST" action="{{ route('admin.tarjetas.baja', $tarjeta) }}" class="space-y-3 md:border-l md:border-noche/10 md:pl-5"
                          onsubmit="return confirm('¿Dar de baja la tarjeta {{ $tarjeta->numeroFormateado() }}? La baja es definitiva.')">
                        @csrf
                        @method('PATCH')
                        <h3 class="text-sm font-bold text-red-700">Dar de baja</h3>
                        <p class="text-xs text-acero">La tarjeta queda anulada y no se puede volver a activar.
                            {{ $tarjeta->afiliada ? 'Por ser afiliada, el titular puede pedir en POCAE que su saldo pase a una tarjeta nueva.' : 'Es al portador: el saldo no se puede trasladar.' }}</p>
                        <x-seleccion name="motivo_baja" label="Motivo" :opciones="Tarjeta::MOTIVOS_BAJA" vacio="Elige el motivo…" required />
                        <x-campo name="observacion_baja" label="Observación (opcional)" maxlength="120" />
                        <button class="btn btn-secundario w-full !border-red-300 !text-red-700">Dar de baja</button>
                    </form>
                </div>
            @endif
            <p class="mt-5 border-t border-noche/[0.08] pt-3 text-xs text-acero">La emisión, la afiliación, la reposición y las recargas se realizan en los puntos de atención POCAE y en los puntos de recarga.</p>
        </section>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <section>
            <h2 class="mb-3 text-lg font-semibold text-tm-900">Últimas recargas <span class="text-xs font-normal text-acero">(puntos de recarga y en línea)</span></h2>
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
                    <thead><tr><th>Fecha</th><th>Subió en</th><th>Línea</th><th class="text-right">Cobro</th></tr></thead>
                    <tbody>
                        @forelse ($pagos as $pago)
                            <tr>
                                <td>{{ $pago->fecha_hora?->format('d/m/Y H:i') }}</td>
                                <td>{{ $pago->estacion?->nombre ?? $pago->molinete?->acceso?->estacion?->nombre ?? '—' }}{{ $pago->bus ? ' · bus '.$pago->bus->placa : ($pago->molinete?->acceso ? ' · '.$pago->molinete->acceso->ubicacion_fisica : '') }}</td>
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
