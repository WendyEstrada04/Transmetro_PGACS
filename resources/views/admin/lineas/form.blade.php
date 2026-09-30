@extends('layouts.app')

@section('titulo', $linea->exists ? 'Editar línea' : 'Nueva línea')

@section('contenido')
    <x-titulo :titulo="$linea->exists ? 'Editar línea '.$linea->codigo_linea : 'Nueva línea'"
              :volver="$linea->exists ? route('admin.lineas.show', $linea) : route('admin.lineas.index')" />

    <form method="POST" class="caja max-w-xl space-y-4"
          action="{{ $linea->exists ? route('admin.lineas.update', $linea) : route('admin.lineas.store') }}">
        @csrf
        @if ($linea->exists) @method('PUT') @endif

        <x-campo name="codigo_linea" label="Código de la línea" :value="$linea->codigo_linea" required maxlength="20" ayuda="Ejemplo: L1, L2" />
        <x-campo name="nombre" label="Nombre de la línea" :value="$linea->nombre" maxlength="100" ayuda="Ejemplo: Eje Sur, Corredor Central" />
        <x-seleccion name="id_municipio" label="Municipio" :opciones="$municipios" :value="$linea->id_municipio" required vacio="Selecciona…" />
        <div class="grid gap-4 md:grid-cols-2">
            <x-seleccion name="tipo_servicio" label="Tipo de servicio" :opciones="\App\Models\Linea::TIPOS_SERVICIO"
                         :value="$linea->tipo_servicio ?? 'paradas_continuas'" required
                         onchange="document.getElementById('tarifa').value = this.value === 'directo' ? '2.00' : '1.00'" />
            <x-campo name="tarifa" label="Tarifa (Q)" type="number" step="0.01" min="0" max="20" :value="$linea->tarifa ?? '1.00'" required
                     ayuda="Q1.00 paradas continuas · Q2.00 servicio directo." />
            <x-campo name="color" label="Color de la línea (mapa)" type="color" :value="$linea->color ?? '#155240'" required class="h-10 p-1" />
            <label class="flex items-center gap-2 self-end pb-2 text-sm">
                <input type="checkbox" name="ruta_circular" value="1" @checked(old('ruta_circular', $linea->ruta_circular)) class="rounded border-slate-300">
                Ruta circular (regresa a la estación de inicio)
            </label>
        </div>

        @if ($linea->exists)
            <x-seleccion name="estado" label="Estado" :value="$linea->estado" required
                         :opciones="['activa' => 'Activa', 'inactiva' => 'Inactiva', 'mantenimiento' => 'Mantenimiento']"
                         ayuda="Para activarla necesita al menos 2 estaciones y entre n y 2n buses." />
        @else
            <p class="text-sm text-slate-500">La línea se crea <b>inactiva</b> y con el horario oficial (lunes a viernes 05:00–20:00, sábado 05:00–19:00, domingo y feriados 06:30–19:00). Después agrégale sus estaciones en orden y asígnale buses para activarla.</p>
        @endif

        <button class="btn btn-primario">Guardar</button>
    </form>
@endsection
