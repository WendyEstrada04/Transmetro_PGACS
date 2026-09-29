<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Piloto de bus (punto 9: historial educativo, residencia y comunicación).
 */
class Piloto extends Model
{
    protected $table = 'pilotos';

    protected $primaryKey = 'id_empleado';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'id_empleado',
        'numero_licencia',
        'tipo_licencia',
        'anios_experiencia',
        'nivel_educativo',
        'historial_educativo_encriptado',
        'id_bus_asignado',
    ];

    protected function casts(): array
    {
        return [
            'anios_experiencia' => 'integer',
            'historial_educativo_encriptado' => 'encrypted',
        ];
    }

    public function empleado(): BelongsTo
    {
        return $this->belongsTo(Empleado::class, 'id_empleado', 'id_empleado');
    }

    public function bus(): BelongsTo
    {
        return $this->belongsTo(Bus::class, 'id_bus_asignado', 'id_bus');
    }
}
