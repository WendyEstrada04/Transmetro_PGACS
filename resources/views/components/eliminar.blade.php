@props(['accion', 'texto' => 'Eliminar', 'confirmar' => '¿Seguro que quieres eliminar este registro?'])

<form method="POST" action="{{ $accion }}" class="inline" onsubmit="return confirm(@js($confirmar))">
    @csrf
    @method('DELETE')
    <button type="submit" {{ $attributes->merge(['class' => 'btn btn-peligro btn-chico']) }}>{{ $texto }}</button>
</form>
