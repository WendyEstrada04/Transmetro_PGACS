@props(['name', 'label', 'opciones' => [], 'value' => null, 'required' => false, 'vacio' => null, 'ayuda' => null])

<div>
    <label for="{{ $name }}" class="rotulo mb-2 block !text-[10.5px] text-pizarra">
        {{ $label }} @if ($required)<span class="text-red-600">*</span>@endif
    </label>
    <select id="{{ $name }}" name="{{ $name }}" @required($required)
            {{ $attributes->merge(['class' => 'w-full rounded-lg border border-niebla/50 bg-white px-3.5 py-2.5 text-[15px] text-noche transition duration-300 hover:border-niebla focus:border-noche focus:ring-4 focus:ring-cielo/35 focus:outline-none']) }}>
        @if (! is_null($vacio))
            <option value="">{{ $vacio }}</option>
        @endif
        @foreach ($opciones as $valor => $texto)
            <option value="{{ $valor }}" @selected((string) old($name, $value) === (string) $valor)>{{ $texto }}</option>
        @endforeach
    </select>
    @if ($ayuda)
        <p class="mt-1 text-xs text-acero">{{ $ayuda }}</p>
    @endif
    @error($name)
        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
    @enderror
</div>
