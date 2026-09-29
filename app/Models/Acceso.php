<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Acceso de una estación (puntos 3 y 10).
 */
class Acceso extends Model
{
    use HasUuids;

    protected $table = 'accesos';

    protected $primaryKey = 'id_acceso';

    public $timestamps = false;

    protected $fillable = [
        'id_estacion',
        'ubicacion_fisica',
    ];

    public function estacion(): BelongsTo
    {
        return $this->belongsTo(Estacion::class, 'id_estacion', 'id_estacion');
    }

    public function molinete(): HasOne
    {
        return $this->hasOne(Molinete::class, 'id_acceso', 'id_acceso');
    }

    /** Punto 10: guardias de seguridad asignados al acceso. */
    public function guardias(): HasMany
    {
        return $this->hasMany(Seguridad::class, 'id_acceso_asignado', 'id_acceso');
    }
}
