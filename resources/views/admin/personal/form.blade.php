@extends('layouts.app')

@section('titulo', $empleado->exists ? 'Editar empleado' : 'Nuevo empleado')

@section('contenido')
    @php $rolActual = old('rol', $empleado->rol); @endphp

    <x-titulo :titulo="$empleado->exists ? $empleado->nombre_completo : 'Nuevo empleado'"
              :subtitulo="$empleado->exists ? 'Puesto: '.$roles[$empleado->rol] : null"
              :volver="route('admin.personal.index')" />

    <form method="POST" class="caja max-w-3xl space-y-6"
          action="{{ $empleado->exists ? route('admin.personal.update', $empleado) : route('admin.personal.store') }}">
        @csrf
        @if ($empleado->exists) @method('PUT') @endif

        {{-- Datos generales --}}
        <div class="grid gap-4 md:grid-cols-2">
            @unless ($empleado->exists)
                <x-seleccion name="rol" label="Puesto" :opciones="$roles" :value="$empleado->rol" required />
                <div></div>
            @endunless
            <x-campo name="nombre_completo" label="Nombre completo" :value="$empleado->nombre_completo" required maxlength="200" />
            <x-campo name="dpi" label="DPI" :value="$empleado->dpi" required maxlength="15" inputmode="numeric" placeholder="1234 56789 0101" ayuda="13 dígitos." />
            <x-campo name="fecha_nacimiento" label="Fecha de nacimiento" type="date" :value="$empleado->fecha_nacimiento?->format('Y-m-d')" required />
            <div class="hidden md:block"></div>
            <x-campo name="telefono" label="Teléfono" type="tel" :value="$empleado->exists ? $empleado->telefono() : null" required maxlength="20"
                     inputmode="tel" placeholder="5555-1234" ayuda="Se guarda cifrado." />
            <x-campo name="correo" label="Correo electrónico" type="email" :value="$empleado->exists ? $empleado->correo() : null" maxlength="150"
                     placeholder="nombre@correo.gt" ayuda="Opcional. Se guarda cifrado." />
            <div class="md:col-span-2">
                <x-campo name="direccion" label="Residencia (dirección)" :value="$empleado->exists ? $empleado->direccion_encriptada : null" required maxlength="255" ayuda="Se guarda cifrada." />
            </div>
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="activo" value="1" @checked(old('activo', $empleado->activo)) class="rounded border-slate-300">
                Activo
            </label>
        </div>

        {{-- Piloto (punto 9) --}}
        <fieldset data-rol="piloto" class="space-y-4 rounded-md border border-tm-100 bg-tm-50 p-4">
            <legend class="px-1 text-sm font-semibold text-tm-900">Datos del piloto</legend>
            <div class="grid gap-4 md:grid-cols-2">
                <x-campo name="numero_licencia" label="Número de licencia" :value="$empleado->piloto?->numero_licencia" maxlength="30" />
                <x-seleccion name="tipo_licencia" label="Tipo de licencia" :opciones="['A' => 'A', 'B' => 'B', 'C' => 'C']" :value="$empleado->piloto?->tipo_licencia ?? 'A'" />
                <x-campo name="anios_experiencia" label="Años de experiencia" type="number" min="0" max="60" :value="$empleado->piloto?->anios_experiencia ?? 0" />
                <x-campo name="nivel_educativo" label="Nivel educativo" :value="$empleado->piloto?->nivel_educativo" maxlength="50" />
                <div class="md:col-span-2">
                    <x-area name="historial_educativo" label="Historial educativo" :value="$empleado->piloto?->historial_educativo_encriptado" ayuda="Estudios y cursos. Se guarda cifrado." />
                </div>
                <x-seleccion name="id_bus_asignado" label="Bus que conduce" :opciones="$buses" :value="$empleado->piloto?->id_bus_asignado" vacio="Sin bus" />
            </div>
        </fieldset>

        {{-- Seguridad (punto 10) --}}
        <fieldset data-rol="seguridad" class="space-y-4 rounded-md border border-tm-100 bg-tm-50 p-4">
            <legend class="px-1 text-sm font-semibold text-tm-900">Datos del guardia de seguridad</legend>
            <div class="grid gap-4 md:grid-cols-2">
                <x-campo name="permisos_vigentes" label="Permisos vigentes" :value="$empleado->seguridad?->permisos_vigentes" maxlength="100" />
                <x-campo name="constancia_antecedentes_penales" label="Constancia de antecedentes penales" :value="$empleado->seguridad?->constancia_antecedentes_penales" maxlength="100" />
                <x-campo name="numero_placa_asignada" label="Número de placa" :value="$empleado->seguridad?->numero_placa_asignada" maxlength="15" />
                <x-seleccion name="id_acceso_asignado" label="Acceso asignado" :opciones="$accesos" :value="$empleado->seguridad?->id_acceso_asignado" vacio="Selecciona…"
                             ayuda="Cada acceso cuenta con al menos un agente." />
            </div>
        </fieldset>

        {{-- Operador (punto 16) --}}
        <fieldset data-rol="operador" class="space-y-4 rounded-md border border-tm-100 bg-tm-50 p-4">
            <legend class="px-1 text-sm font-semibold text-tm-900">Datos del operador</legend>
            <div class="grid gap-4 md:grid-cols-2">
                <x-campo name="nivel_estudios" label="Nivel de estudios" :value="$empleado->operador?->nivel_estudios" maxlength="50" />
                <x-campo name="anios_experiencia_monitoreo" label="Años de experiencia en monitoreo" type="number" min="0" max="60" :value="$empleado->operador?->anios_experiencia_monitoreo ?? 0" />
                <div class="md:col-span-2">
                    <x-seleccion name="id_estacion_asignada" label="Estación donde trabaja" :opciones="$estacionesOperador" :value="$empleado->operador?->id_estacion_asignada" vacio="Sin estación"
                                 ayuda="Un operador por estación, en la PC de la estación." />
                </div>
            </div>
            <p class="text-sm text-slate-600">Usuario para iniciar sesión en la PC de su estación (opcional):</p>
            <div class="grid gap-4 md:grid-cols-2">
                <x-campo name="email" label="Correo de acceso" type="email" :value="$empleado->usuario?->email" maxlength="255" />
                <x-campo name="password" label="Contraseña" type="password" minlength="8" autocomplete="new-password"
                         :ayuda="$empleado->usuario ? 'Déjala vacía para no cambiarla.' : 'Mínimo 8 caracteres.'" />
            </div>
        </fieldset>

        <button class="btn btn-primario">Guardar</button>
    </form>
@endsection

@push('scripts')
    <script>
        // Muestra solo la sección del puesto elegido.
        (() => {
            const selector = document.getElementById('rol');
            const mostrar = (rol) => document.querySelectorAll('fieldset[data-rol]').forEach((f) => {
                const visible = f.dataset.rol === rol;
                f.hidden = !visible;
                f.disabled = !visible;
            });
            mostrar(selector ? selector.value : @js($rolActual));
            selector?.addEventListener('change', (e) => mostrar(e.target.value));
        })();
    </script>
@endpush
