<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Alerta del sistema, p. ej. refuerzo de bus por saturación (punto 14).
 */
class AlertaSistema extends Model
{
    use HasUuids;

    protected $table = 'alertas_sistema';

    protected $primaryKey = 'id_alerta';

    public $timestamps = false;

    protected $fillable = [
        'id_estacion',
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

    public function scopeAbiertas(Builder $query): Builder
    {
        return $query->where('estado', 'abierta');
    }
}
