<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Datos generales del personal. Contacto y dirección se guardan cifrados (AES-256).
 */
class Empleado extends Model
{
    use HasUuids;

    protected $table = 'empleados';

    protected $primaryKey = 'id_empleado';

    public $timestamps = false;

    protected $fillable = [
        'dpi',
        'nombre_completo',
        'fecha_nacimiento',
        'rol',
        'contacto_encriptado',
        'direccion_encriptada',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'fecha_nacimiento' => 'date',
            'contacto_encriptado' => 'encrypted',
            'direccion_encriptada' => 'encrypted',
            'activo' => 'boolean',
        ];
    }

    public function piloto(): HasOne
    {
        return $this->hasOne(Piloto::class, 'id_empleado', 'id_empleado');
    }

    public function seguridad(): HasOne
    {
        return $this->hasOne(Seguridad::class, 'id_empleado', 'id_empleado');
    }

    public function operador(): HasOne
    {
        return $this->hasOne(Operador::class, 'id_empleado', 'id_empleado');
    }

    public function usuario(): HasOne
    {
        return $this->hasOne(User::class, 'id_empleado', 'id_empleado');
    }
}
