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

    /**
     * Teléfono y correo se guardan juntos y cifrados en contacto_encriptado con el formato
     * “Tel. 5555-5555 · nombre@correo.gt”; estos métodos los arman y los separan.
     */
    public static function armarContacto(string $telefono, ?string $correo): string
    {
        return implode(' · ', array_filter(['Tel. '.trim($telefono), $correo ? trim($correo) : null]));
    }

    public function telefono(): ?string
    {
        $contacto = (string) $this->contacto_encriptado;
        if (preg_match('/Tel\.?\s*([0-9+()\-\s]{7,})/i', $contacto, $m)) {
            return trim($m[1]);
        }

        // Contacto antiguo sin el formato: si no es un correo, se toma completo como teléfono.
        return $this->correo() ? null : (trim($contacto) ?: null);
    }

    public function correo(): ?string
    {
        return preg_match('/[\w.+-]+@[\w-]+(\.[\w-]+)+/', (string) $this->contacto_encriptado, $m) ? $m[0] : null;
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
