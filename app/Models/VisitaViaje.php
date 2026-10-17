<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Parada visitada durante un viaje (puntos 1 y 15).
 * subieron / bajaron los cuentan los molinetes de entrada y salida del bus (triggers de la base).
 */
class VisitaViaje extends Model
{
    use HasUuids;

    protected $table = 'visitas_viaje';

    protected $primaryKey = 'id_visita';

    public $timestamps = false;

    protected $fillable = [
        'id_viaje',
        'id_estacion',
        'orden_visita',
        'hora_llegada',
        'hora_salida',
        'ocupacion_al_llegar_pct',
        'subieron',
        'bajaron',
    ];

    protected function casts(): array
    {
        return [
            'orden_visita' => 'integer',
            'hora_llegada' => 'datetime',
            'hora_salida' => 'datetime',
            'ocupacion_al_llegar_pct' => 'decimal:2',
            'subieron' => 'integer',
            'bajaron' => 'integer',
        ];
    }

    public function viaje(): BelongsTo
    {
        return $this->belongsTo(Viaje::class, 'id_viaje', 'id_viaje');
    }

    public function estacion(): BelongsTo
    {
        return $this->belongsTo(Estacion::class, 'id_estacion', 'id_estacion');
    }
}
