<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pago registrado en un molinete (punto 18).
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
    ];

    protected function casts(): array
    {
        return [
            'monto' => 'decimal:2',
            'fecha_hora' => 'datetime',
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
}
