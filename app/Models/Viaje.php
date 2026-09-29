<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Recorrido real de un bus en una línea (punto 1).
 */
class Viaje extends Model
{
    use HasUuids;

    protected $table = 'viajes';

    protected $primaryKey = 'id_viaje';

    public $timestamps = false;

    protected $fillable = [
        'id_bus',
        'id_linea',
        'fecha_hora_inicio',
        'fecha_hora_fin',
        'estado',
    ];

    protected function casts(): array
    {
        return [
            'fecha_hora_inicio' => 'datetime',
            'fecha_hora_fin' => 'datetime',
        ];
    }

    public function bus(): BelongsTo
    {
        return $this->belongsTo(Bus::class, 'id_bus', 'id_bus');
    }

    public function linea(): BelongsTo
    {
        return $this->belongsTo(Linea::class, 'id_linea', 'id_linea');
    }

    public function visitas(): HasMany
    {
        return $this->hasMany(VisitaViaje::class, 'id_viaje', 'id_viaje')->orderBy('orden_visita');
    }
}
