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
        'tipo_tarjeta',
        'nombre_titular',
        'dpi_titular',
        'costo_emision',
        'viajes_gratis_restantes',
    ];

    public const TIPOS = [
        'ciudadana' => 'Tarjeta Ciudadana',
        'adulto_mayor' => 'Adulto mayor',
        'discapacidad' => 'Persona con discapacidad',
    ];

    /** Tarjeta Ciudadana: costo de emisión e incluye 5 viajes gratis; recargas de Q5 a Q200. */
    public const COSTO_EMISION = 20.00;

    public const VIAJES_GRATIS = 5;

    public const RECARGA_MINIMA = 5;

    public const RECARGA_MAXIMA = 200;

    protected function casts(): array
    {
        return [
            'saldo' => 'decimal:2',
            'fecha_emision' => 'datetime',
            'costo_emision' => 'decimal:2',
            'viajes_gratis_restantes' => 'integer',
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
