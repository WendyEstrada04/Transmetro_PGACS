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
 * Tiene un molinete de entrada (cobra al subir) y uno de salida (cuenta al bajar):
 * pasajeros_a_bordo = subidas − bajadas, y la ocupación (%) la calcula la base de datos.
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
        'pasajeros_a_bordo',
        'estado_logico',
        'tipo_unidad',
    ];

    protected function casts(): array
    {
        return [
            'capacidad_maxima' => 'integer',
            'pasajeros_a_bordo' => 'integer',
            'ocupacion_actual_pct' => 'decimal:2',
        ];
    }

    /**
     * Tipos de unidad de la flota del Transmetro: [nombre, capacidad en pasajeros (sentados y de pie)].
     *  - Biarticulado: 28 m, 270 pasajeros (Prensa Libre, 2016; opera en la Línea 12).
     *  - Articulado: 18–20 m, 160 pasajeros (Busscar Urbanuss Pluss / Volvo; el fabricante del Marcopolo Viale indica hasta 180).
     *  - Convencional: 12 m, 119 pasajeros (Busscar Urbanuss Pluss / Scania).
     *  - Eléctrico TuBus: 12 m, 80 pasajeros (FOTON BJ6123EVCA de la Ruta 5).
     */
    public const TIPOS_UNIDAD = [
        'biarticulado' => ['Biarticulado', 270],
        'articulado' => ['Articulado', 160],
        'convencional' => ['Convencional', 119],
        'electrico' => ['Eléctrico TuBus', 80],
    ];

    public function tipoUnidad(): string
    {
        return self::TIPOS_UNIDAD[$this->tipo_unidad][0] ?? ucfirst((string) $this->tipo_unidad);
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

    public function mantenimientos(): HasMany
    {
        return $this->hasMany(MantenimientoBus::class, 'id_bus', 'id_bus')->orderByDesc('fecha_reporte');
    }

    public function molinetes(): HasMany
    {
        return $this->hasMany(Molinete::class, 'id_bus', 'id_bus');
    }

    public function molineteEntrada(): HasOne
    {
        return $this->hasOne(Molinete::class, 'id_bus', 'id_bus')->where('tipo', 'entrada_bus');
    }

    public function molineteSalida(): HasOne
    {
        return $this->hasOne(Molinete::class, 'id_bus', 'id_bus')->where('tipo', 'salida_bus');
    }

    public function lleno(): bool
    {
        return $this->pasajeros_a_bordo >= $this->capacidad_maxima;
    }

    public function viajes(): HasMany
    {
        return $this->hasMany(Viaje::class, 'id_bus', 'id_bus');
    }

    /** Punto 15: si el bus no llena el 25 % de su capacidad espera 5 minutos más en cada estación. */
    public function minutosEsperaExtra(): int
    {
        return $this->pasajeros_a_bordo * 100 < 25 * max(1, (int) $this->capacidad_maxima) ? 5 : 0;
    }

    /** Punto 7: cambia el bus de parqueo sin que quede sin parqueo (procedimiento almacenado). */
    public function cambiarParqueo(string $idParqueoNuevo): void
    {
        DB::statement('CALL sp_cambiar_parqueo_bus(?, ?)', [$this->id_bus, $idParqueoNuevo]);
    }
}
