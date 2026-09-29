<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Municipalidad a la que pertenecen líneas y estaciones (punto 11).
 */
class Municipio extends Model
{
    use HasUuids;

    protected $table = 'municipios';

    protected $primaryKey = 'id_municipio';

    public $timestamps = false;

    protected $fillable = [
        'codigo_iso',
        'nombre',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    public function lineas(): HasMany
    {
        return $this->hasMany(Linea::class, 'id_municipio', 'id_municipio');
    }

    public function estaciones(): HasMany
    {
        return $this->hasMany(Estacion::class, 'id_municipio', 'id_municipio');
    }
}
