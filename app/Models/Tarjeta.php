<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tarjeta de acceso Transmetro (punto 18).
 */
class Tarjeta extends Model
{
    use HasUuids;

    protected $table = 'tarjetas';

    protected $primaryKey = 'id_tarjeta';

    public $timestamps = false;

    protected $fillable = [
        'numero_tarjeta',
        'saldo',
        'estado',
        'fecha_emision',
    ];

    protected function casts(): array
    {
        return [
            'saldo' => 'decimal:2',
            'fecha_emision' => 'datetime',
        ];
    }

    public function recargas(): HasMany
    {
        return $this->hasMany(Recarga::class, 'id_tarjeta', 'id_tarjeta');
    }

    public function transacciones(): HasMany
    {
        return $this->hasMany(TransaccionPago::class, 'id_tarjeta', 'id_tarjeta');
    }
}
