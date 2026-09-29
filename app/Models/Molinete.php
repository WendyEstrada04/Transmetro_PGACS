<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Molinete de un acceso, donde se registran los pagos.
 */
class Molinete extends Model
{
    use HasUuids;

    protected $table = 'molinetes';

    protected $primaryKey = 'id_molinete';

    public $timestamps = false;

    protected $fillable = [
        'id_acceso',
        'ip_address',
        'estado_operativo',
    ];

    public function acceso(): BelongsTo
    {
        return $this->belongsTo(Acceso::class, 'id_acceso', 'id_acceso');
    }

    public function transacciones(): HasMany
    {
        return $this->hasMany(TransaccionPago::class, 'id_molinete', 'id_molinete');
    }
}
