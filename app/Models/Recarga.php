<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Recarga de saldo a una tarjeta (punto 18).
 */
class Recarga extends Model
{
    use HasUuids;

    protected $table = 'recargas';

    protected $primaryKey = 'id_recarga';

    public $timestamps = false;

    protected $fillable = [
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

    public function tarjeta(): BelongsTo
    {
        return $this->belongsTo(Tarjeta::class, 'id_tarjeta', 'id_tarjeta');
    }
}
