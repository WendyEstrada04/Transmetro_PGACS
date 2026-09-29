<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Operador de la PC de trabajo de una estación (punto 16).
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

    public function estacion(): BelongsTo
    {
        return $this->belongsTo(Estacion::class, 'id_estacion_asignada', 'id_estacion');
    }
}
