<span class="etiqueta {{ match ($estado) { 'activa' => 'bg-tm-100 text-tm-700', 'mantenimiento' => 'bg-amber-100 text-amber-800', default => 'bg-slate-100 text-slate-600' } }}">
    {{ ucfirst($estado) }}
</span>
