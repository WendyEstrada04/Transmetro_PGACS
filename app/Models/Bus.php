<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

/**
 * Bus del Transmetro (puntos 4, 5, 6, 7, 14 y 15).
 */
class Bus extends Model
{
    use HasUuids;

    protected $table = 'buses';

    protected $primaryKey = 'id_bus';

    public $timestamps = false;

    protected $fillable = [
        'placa',
        'capacidad_maxima',
        'id_linea_asignada',
        'ocupacion_actual_pct',
        'estado_logico',
    ];

    protected function casts(): array
    {
        return [
            'capacidad_maxima' => 'integer',
            'ocupacion_actual_pct' => 'decimal:2',
        ];
    }

    public function linea(): BelongsTo
    {
        return $this->belongsTo(Linea::class, 'id_linea_asignada', 'id_linea');
    }

    public function parqueo(): HasOne
    {
        return $this->hasOne(Parqueo::class, 'id_bus_asignado', 'id_bus');
    }

    public function piloto(): HasOne
    {
        return $this->hasOne(Piloto::class, 'id_bus_asignado', 'id_bus');
    }

    public function viajes(): HasMany
    {
        return $this->hasMany(Viaje::class, 'id_bus', 'id_bus');
    }

    /** Punto 15: si el bus no llena el 25 % de su capacidad espera 5 minutos más en cada estación. */
    public function minutosEsperaExtra(): int
    {
        return $this->ocupacion_actual_pct < 25 ? 5 : 0;
    }

    /** Punto 7: cambia el bus de parqueo sin que quede sin parqueo (procedimiento almacenado). */
    public function cambiarParqueo(string $idParqueoNuevo): void
    {
        DB::statement('CALL sp_cambiar_parqueo_bus(?, ?)', [$this->id_bus, $idParqueoNuevo]);
    }
}
