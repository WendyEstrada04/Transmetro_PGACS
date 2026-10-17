@props(['name', 'label', 'value' => null, 'required' => false, 'ayuda' => null])

<div>
    <label for="{{ $name }}" class="rotulo mb-2 block !text-[10.5px] text-pizarra">
        {{ $label }} @if ($required)<span class="text-red-600">*</span>@endif
    </label>
    <textarea id="{{ $name }}" name="{{ $name }}" rows="3" @required($required)
              {{ $attributes->merge(['class' => 'w-full rounded-lg border border-niebla/50 bg-white px-3.5 py-2.5 text-[15px] text-noche transition duration-300 hover:border-niebla focus:border-noche focus:ring-4 focus:ring-cielo/35 focus:outline-none']) }}>{{ old($name, $value) }}</textarea>
    @if ($ayuda)
        <p class="mt-1 text-xs text-acero">{{ $ayuda }}</p>
    @endif
    @error($name)
        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
    @enderror
</div>
