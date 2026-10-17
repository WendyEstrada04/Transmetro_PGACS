<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Alerta del sistema: refuerzo por estación saturada (punto 14) o bus lleno con gente sin poder subir.
 */
class AlertaSistema extends Model
{
    use HasUuids;

    protected $table = 'alertas_sistema';

    protected $primaryKey = 'id_alerta';

    public $timestamps = false;

    protected $fillable = [
        'id_estacion',
        'id_bus',
        'tipo_alerta',
        'descripcion',
        'estado',
        'fecha_creacion',
        'fecha_cierre',
    ];

    protected function casts(): array
    {
        return [
            'fecha_creacion' => 'datetime',
            'fecha_cierre' => 'datetime',
        ];
    }

    public function estacion(): BelongsTo
    {
        return $this->belongsTo(Estacion::class, 'id_estacion', 'id_estacion');
    }

    /** Alertas que se resuelven enviando otro bus. */
    public const DE_REFUERZO = ['refuerzo_bus', 'bus_lleno'];

    public const TIPOS = [
        'refuerzo_bus' => 'Estación saturada',
        'bus_lleno' => 'Bus lleno',
        'bahia_ocupada' => 'Bahía ocupada',
        'espera_minima' => 'Espera mínima',
        'otro' => 'Otro',
    ];

    public function bus(): BelongsTo
    {
        return $this->belongsTo(Bus::class, 'id_bus', 'id_bus');
    }

    public function pideBus(): bool
    {
        return in_array($this->tipo_alerta, self::DE_REFUERZO, true);
    }

    public function scopeAbiertas(Builder $query): Builder
    {
        return $query->where('estado', 'abierta');
    }
}
