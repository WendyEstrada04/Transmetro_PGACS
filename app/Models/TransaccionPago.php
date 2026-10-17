<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pago del pasaje (punto 18): uno por cada persona que sube al bus, en el molinete de entrada del bus.
 * Guarda en qué bus, en qué estación y en qué parada del viaje subió.
 */
class TransaccionPago extends Model
{
    use HasUuids;

    protected $table = 'transacciones_pago';

    protected $primaryKey = 'id_transaccion';

    public $timestamps = false;

    protected $fillable = [
        'id_molinete',
        'id_tarjeta',
        'monto',
        'metodo_pago',
        'fecha_hora',
        'id_linea',
        'viaje_gratis',
        'id_bus',
        'id_estacion',
        'id_visita',
    ];

    public const METODOS = [
        'tarjeta_ciudadana' => 'Tarjeta Ciudadana',
        'tarjeta_bancaria' => 'Tarjeta bancaria sin contacto',
    ];

    protected function casts(): array
    {
        return [
            'monto' => 'decimal:2',
            'fecha_hora' => 'datetime',
            'viaje_gratis' => 'boolean',
        ];
    }

    public function molinete(): BelongsTo
    {
        return $this->belongsTo(Molinete::class, 'id_molinete', 'id_molinete');
    }

    public function tarjeta(): BelongsTo
    {
        return $this->belongsTo(Tarjeta::class, 'id_tarjeta', 'id_tarjeta');
    }

    public function bus(): BelongsTo
    {
        return $this->belongsTo(Bus::class, 'id_bus', 'id_bus');
    }

    public function estacion(): BelongsTo
    {
        return $this->belongsTo(Estacion::class, 'id_estacion', 'id_estacion');
    }

    public function linea(): BelongsTo
    {
        return $this->belongsTo(Linea::class, 'id_linea', 'id_linea');
    }
}
