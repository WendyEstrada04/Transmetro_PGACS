<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Estación del Transmetro (puntos 2, 3, 6, 11 y 14).
 */
class Estacion extends Model
{
    use HasUuids;

    protected $table = 'estaciones';

    protected $primaryKey = 'id_estacion';

    public $timestamps = false;

    protected $fillable = [
        'id_municipio',
        'nombre',
        'direccion',
        'aforo_maximo',
        'aforo_actual',
        'latitud',
        'longitud',
    ];

    protected function casts(): array
    {
        return [
            'aforo_maximo' => 'integer',
            'aforo_actual' => 'integer',
            'latitud' => 'decimal:6',
            'longitud' => 'decimal:6',
        ];
    }

    public function municipio(): BelongsTo
    {
        return $this->belongsTo(Municipio::class, 'id_municipio', 'id_municipio');
    }

    public function lineas(): BelongsToMany
    {
        return $this->belongsToMany(Linea::class, 'linea_estacion', 'id_estacion', 'id_linea')
            ->withPivot('orden_parada', 'distancia_previa_km')
            ->orderByRaw(Linea::ORDEN_SQL);
    }

    public function accesos(): HasMany
    {
        return $this->hasMany(Acceso::class, 'id_estacion', 'id_estacion');
    }

    public function parqueos(): HasMany
    {
        return $this->hasMany(Parqueo::class, 'id_estacion', 'id_estacion');
    }

    public function alertas(): HasMany
    {
        return $this->hasMany(AlertaSistema::class, 'id_estacion', 'id_estacion');
    }

    public function operadores(): HasMany
    {
        return $this->hasMany(Operador::class, 'id_estacion_asignada', 'id_estacion');
    }

    /** Porcentaje de ocupación de la estación respecto a su aforo máximo. */
    public function porcentajeAforo(): float
    {
        return round($this->aforo_actual * 100 / $this->aforo_maximo, 1);
    }

    /** Punto 14: la estación necesita refuerzo al tener 50 % más de su capacidad. */
    public function requiereRefuerzo(): bool
    {
        return $this->aforo_actual >= 1.5 * $this->aforo_maximo;
    }
}
