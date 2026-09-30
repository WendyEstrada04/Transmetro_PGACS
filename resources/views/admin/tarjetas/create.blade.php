@extends('layouts.app')

@section('titulo', 'Emitir tarjeta')

@section('contenido')
    <x-titulo titulo="Emitir Tarjeta Ciudadana" :volver="route('admin.tarjetas.index')"
              subtitulo="Costo de Q{{ number_format(\App\Models\Tarjeta::COSTO_EMISION, 2) }}; incluye {{ \App\Models\Tarjeta::VIAJES_GRATIS }} viajes gratis." />

    <form method="POST" action="{{ route('admin.tarjetas.store') }}" class="caja max-w-2xl space-y-4">
        @csrf
        <div class="grid gap-4 md:grid-cols-2">
            <x-campo name="numero_tarjeta" label="Número de tarjeta" :value="$numeroSugerido" required maxlength="20" ayuda="Solo letras y números." />
            <x-seleccion name="tipo_tarjeta" label="Tipo de tarjeta" :opciones="$tipos" value="ciudadana" required />
        </div>

        <fieldset id="titular" class="grid gap-4 rounded-md border border-tm-100 bg-tm-50 p-4 md:grid-cols-2">
            <legend class="px-1 text-sm font-semibold text-tm-900">Titular (tarjeta personalizada)</legend>
            <x-campo name="nombre_titular" label="Nombre del titular" maxlength="200" />
            <x-campo name="dpi_titular" label="DPI del titular" maxlength="13" inputmode="numeric" />
        </fieldset>

        <div class="grid gap-4 md:grid-cols-2">
            <x-campo name="saldo_inicial" label="Recarga inicial (Q)" type="number" step="0.01"
                     :min="\App\Models\Tarjeta::RECARGA_MINIMA" :max="\App\Models\Tarjeta::RECARGA_MAXIMA"
                     ayuda="Opcional. De Q{{ \App\Models\Tarjeta::RECARGA_MINIMA }} a Q{{ \App\Models\Tarjeta::RECARGA_MAXIMA }}." />
            <x-seleccion name="metodo_pago" label="Forma de pago de la recarga" :opciones="$metodos" vacio="—" />
        </div>
        <button class="btn btn-primario">Emitir</button>
    </form>
@endsection

@push('scripts')
    <script>
        // Las tarjetas de adulto mayor y de persona con discapacidad son personalizadas.
        (() => {
            const tipo = document.getElementById('tipo_tarjeta');
            const titular = document.getElementById('titular');
            const actualizar = () => {
                const personalizada = tipo.value !== 'ciudadana';
                titular.hidden = !personalizada;
                titular.disabled = !personalizada;
            };
            tipo.addEventListener('change', actualizar);
            actualizar();
        })();
    </script>
@endpush
