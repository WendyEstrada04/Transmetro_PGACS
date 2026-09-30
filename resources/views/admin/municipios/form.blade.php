@extends('layouts.app')

@section('titulo', $municipio->exists ? 'Editar municipio' : 'Nuevo municipio')

@section('contenido')
    <x-titulo :titulo="$municipio->exists ? 'Editar municipio' : 'Nuevo municipio'" :volver="route('admin.municipios.index')" />

    <form method="POST" class="caja max-w-xl space-y-4"
          action="{{ $municipio->exists ? route('admin.municipios.update', $municipio) : route('admin.municipios.store') }}">
        @csrf
        @if ($municipio->exists) @method('PUT') @endif

        <x-campo name="codigo_iso" label="Código" :value="$municipio->codigo_iso" required maxlength="10" ayuda="Ejemplo: GT-0101" />
        <x-campo name="nombre" label="Nombre" :value="$municipio->nombre" required maxlength="150" />
        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" name="activo" value="1" @checked(old('activo', $municipio->activo)) class="rounded border-slate-300">
            Activo
        </label>

        <button class="btn btn-primario">Guardar</button>
    </form>
@endsection
