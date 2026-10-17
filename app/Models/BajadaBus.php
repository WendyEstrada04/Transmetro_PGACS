<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Personas que bajaron del bus, contadas por el molinete de salida (no cobra).
 * Cada registro es una lectura del contador: una persona (molinete real) o varias juntas (simulación).
 */
class BajadaBus extends Model
{
    use HasUuids;

    protected $table = 'bajadas_bus';

    protected $primaryKey = 'id_bajada';

    public $timestamps = false;

    protected $fillable = [
        'id_molinete',
        'id_bus',
        'id_estacion',
        'id_visita',
        'cantidad',
        'fecha_hora',
    ];

    protected function casts(): array
    {
        return [
            'cantidad' => 'integer',
            'fecha_hora' => 'datetime',
        ];
    }

    public function bus(): BelongsTo
    {
        return $this->belongsTo(Bus::class, 'id_bus', 'id_bus');
    }

    public function estacion(): BelongsTo
    {
        return $this->belongsTo(Estacion::class, 'id_estacion', 'id_estacion');
    }
}
