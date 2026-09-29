<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bahía de parqueo de una estación (puntos 6 y 7).
 */
class Parqueo extends Model
{
    use HasUuids;

    protected $table = 'parqueos';

    protected $primaryKey = 'id_parqueo';

    public $timestamps = false;

    protected $fillable = [
        'id_estacion',
        'codigo_bahia',
        'id_bus_asignado',
    ];

    public function estacion(): BelongsTo
    {
        return $this->belongsTo(Estacion::class, 'id_estacion', 'id_estacion');
    }

    public function bus(): BelongsTo
    {
        return $this->belongsTo(Bus::class, 'id_bus_asignado', 'id_bus');
    }
}
