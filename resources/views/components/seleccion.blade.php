@props(['name', 'label', 'opciones' => [], 'value' => null, 'required' => false, 'vacio' => null, 'ayuda' => null])

<div>
    <label for="{{ $name }}" class="mb-1 block text-sm font-medium">
        {{ $label }} @if ($required)<span class="text-red-600">*</span>@endif
    </label>
    <select id="{{ $name }}" name="{{ $name }}" @required($required)
            {{ $attributes->merge(['class' => 'w-full rounded-md border border-slate-300 bg-white px-3 py-2 focus:border-tm-500 focus:ring-2 focus:ring-tm-300 focus:outline-none']) }}>
        @if (! is_null($vacio))
            <option value="">{{ $vacio }}</option>
        @endif
        @foreach ($opciones as $valor => $texto)
            <option value="{{ $valor }}" @selected((string) old($name, $value) === (string) $valor)>{{ $texto }}</option>
        @endforeach
    </select>
    @if ($ayuda)
        <p class="mt-1 text-xs text-slate-500">{{ $ayuda }}</p>
    @endif
    @error($name)
        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
    @enderror
</div>
