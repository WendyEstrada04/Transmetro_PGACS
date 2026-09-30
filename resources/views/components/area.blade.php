@props(['name', 'label', 'value' => null, 'required' => false, 'ayuda' => null])

<div>
    <label for="{{ $name }}" class="mb-1 block text-sm font-medium">
        {{ $label }} @if ($required)<span class="text-red-600">*</span>@endif
    </label>
    <textarea id="{{ $name }}" name="{{ $name }}" rows="3" @required($required)
              {{ $attributes->merge(['class' => 'w-full rounded-md border border-slate-300 bg-white px-3 py-2 focus:border-tm-500 focus:ring-2 focus:ring-tm-300 focus:outline-none']) }}>{{ old($name, $value) }}</textarea>
    @if ($ayuda)
        <p class="mt-1 text-xs text-slate-500">{{ $ayuda }}</p>
    @endif
    @error($name)
        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
    @enderror
</div>
