<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Operador (punto 16): trabaja en la PC de UNA estación. Desde su pantalla ve cuánta gente espera,
 * los buses detenidos y en camino, pide otro bus, atiende las alertas de la estación, controla los
 * molinetes de sus accesos y ve a los guardias de turno.
 */
class Operador extends Model
{
    protected $table = 'operadores';

    protected $primaryKey = 'id_empleado';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'id_empleado',
        'nivel_estudios',
        'anios_experiencia_monitoreo',
        'id_estacion_asignada',
    ];

    protected function casts(): array
    {
        return [
            'anios_experiencia_monitoreo' => 'integer',
        ];
    }

    public function empleado(): BelongsTo
    {
        return $this->belongsTo(Empleado::class, 'id_empleado', 'id_empleado');
    }

    /** Estación en cuya PC trabaja el operador. */
    public function estacion(): BelongsTo
    {
        return $this->belongsTo(Estacion::class, 'id_estacion_asignada', 'id_estacion');
    }
}
