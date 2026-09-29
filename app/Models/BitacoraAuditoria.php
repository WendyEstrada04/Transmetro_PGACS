<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bitácora de auditoría: quién cambió qué y cuándo.
 */
class BitacoraAuditoria extends Model
{
    use HasUuids;

    protected $table = 'bitacora_auditoria';

    protected $primaryKey = 'id_bitacora';

    public $timestamps = false;

    protected $fillable = [
        'id_usuario',
        'id_empleado_usuario',
        'tabla_afectada',
        'id_registro_afectado',
        'accion',
        'datos_previos',
        'datos_nuevos',
        'fecha_registro',
    ];

    protected function casts(): array
    {
        return [
            'datos_previos' => 'array',
            'datos_nuevos' => 'array',
            'fecha_registro' => 'datetime',
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_usuario');
    }

    public function empleado(): BelongsTo
    {
        return $this->belongsTo(Empleado::class, 'id_empleado_usuario', 'id_empleado');
    }
}
