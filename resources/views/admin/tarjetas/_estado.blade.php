<span class="etiqueta {{ match ($estado) { 'activa' => 'bg-tm-100 text-tm-700', 'bloqueada' => 'bg-red-100 text-red-700', 'baja' => 'bg-slate-800 text-white', 'reemplazada' => 'bg-amber-100 text-amber-800', default => 'bg-slate-100 text-slate-600' } }}">
    {{ \App\Models\Tarjeta::ESTADOS[$estado] ?? ucfirst($estado) }}
</span>
