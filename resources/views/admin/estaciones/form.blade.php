@extends('layouts.app')

@section('titulo', $estacion->exists ? 'Editar estación' : 'Nueva estación')

@section('contenido')
    <x-titulo :titulo="$estacion->exists ? 'Editar estación' : 'Nueva estación'"
              :volver="$estacion->exists ? route('admin.estaciones.show', $estacion) : route('admin.estaciones.index')" />

    <form method="POST" class="caja max-w-2xl space-y-4"
          action="{{ $estacion->exists ? route('admin.estaciones.update', $estacion) : route('admin.estaciones.store') }}">
        @csrf
        @if ($estacion->exists) @method('PUT') @endif

        <div class="grid gap-4 md:grid-cols-2">
            <x-campo name="nombre" label="Nombre" :value="$estacion->nombre" required maxlength="150" />
            <x-seleccion name="id_municipio" label="Municipio" :opciones="$municipios" :value="$estacion->id_municipio" required vacio="Selecciona…" />
            <div class="md:col-span-2">
                <x-campo name="direccion" label="Dirección" :value="$estacion->direccion" maxlength="200" ayuda="Ejemplo: 8a. avenida y 6a. calle, zona 1" />
            </div>
            <x-campo name="aforo_maximo" label="Aforo máximo (personas)" type="number" min="1" :value="$estacion->aforo_maximo" required />
            <div></div>
            <x-campo name="latitud" label="Latitud" type="number" step="0.000001" :value="$estacion->latitud" required ayuda="Ejemplo: 14.638000" />
            <x-campo name="longitud" label="Longitud" type="number" step="0.000001" :value="$estacion->longitud" required ayuda="Ejemplo: -90.514100" />
        </div>

        @unless ($estacion->exists)
            <div class="rounded-md border border-tm-100 bg-tm-50 p-4">
                <p class="mb-3 text-sm font-medium text-tm-900">Accesos iniciales (mínimo 2, cada uno con su molinete)</p>
                <div class="grid gap-4 md:grid-cols-2">
                    <x-campo name="acceso_1" label="Acceso 1 — ubicación" value="Acceso Norte" required maxlength="100" />
                    <x-campo name="ip_1" label="IP del molinete 1" value="10.10.0.11" required />
                    <x-campo name="acceso_2" label="Acceso 2 — ubicación" value="Acceso Sur" required maxlength="100" />
                    <x-campo name="ip_2" label="IP del molinete 2" value="10.10.0.12" required />
                </div>
            </div>
        @endunless

        <button class="btn btn-primario">Guardar</button>
    </form>
@endsection
