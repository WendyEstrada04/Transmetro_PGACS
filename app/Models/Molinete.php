<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Molinete. Hay tres tipos:
 *  - estacion:    en un acceso de la estación; controla el ingreso y cuenta a la gente que espera.
 *  - entrada_bus: en la puerta de subida del bus; cobra el pasaje de cada persona que sube.
 *  - salida_bus:  en la puerta de bajada del bus; cuenta a las personas que bajan.
 */
class Molinete extends Model
{
    use HasUuids;

    protected $table = 'molinetes';

    protected $primaryKey = 'id_molinete';

    public $timestamps = false;

    protected $fillable = [
        'id_acceso',
        'id_bus',
        'tipo',
        'ip_address',
        'estado_operativo',
    ];

    public const TIPOS = [
        'estacion' => 'Acceso de estación',
        'entrada_bus' => 'Entrada del bus (cobra)',
        'salida_bus' => 'Salida del bus (cuenta)',
    ];

    public const ESTADOS = [
        'activo' => 'Activo',
        'inactivo' => 'Inactivo',
        'mantenimiento' => 'En mantenimiento',
    ];

    public function bus(): BelongsTo
    {
        return $this->belongsTo(Bus::class, 'id_bus', 'id_bus');
    }

    public function acceso(): BelongsTo
    {
        return $this->belongsTo(Acceso::class, 'id_acceso', 'id_acceso');
    }

    public function transacciones(): HasMany
    {
        return $this->hasMany(TransaccionPago::class, 'id_molinete', 'id_molinete');
    }
}
