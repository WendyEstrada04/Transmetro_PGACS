<span class="etiqueta {{ match ($estado) { 'activa' => 'bg-tm-100 text-tm-700', 'bloqueada' => 'bg-red-100 text-red-700', default => 'bg-slate-100 text-slate-600' } }}">
    {{ ucfirst($estado) }}
</span>
