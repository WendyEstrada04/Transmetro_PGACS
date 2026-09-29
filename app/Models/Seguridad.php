<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Guardia de seguridad asignado a un acceso (punto 10).
 */
class Seguridad extends Model
{
    protected $table = 'seguridad';

    protected $primaryKey = 'id_empleado';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'id_empleado',
        'permisos_vigentes',
        'constancia_antecedentes_penales',
        'numero_placa_asignada',
        'id_acceso_asignado',
    ];

    public function empleado(): BelongsTo
    {
        return $this->belongsTo(Empleado::class, 'id_empleado', 'id_empleado');
    }

    public function acceso(): BelongsTo
    {
        return $this->belongsTo(Acceso::class, 'id_acceso_asignado', 'id_acceso');
    }
}
