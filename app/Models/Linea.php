<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Línea del Transmetro (puntos 1, 5, 11 y 13).
 */
class Linea extends Model
{
    use HasUuids;

    protected $table = 'lineas';

    protected $primaryKey = 'id_linea';

    public $timestamps = false;

    protected $fillable = [
        'id_municipio',
        'codigo_linea',
        'distancia_total_km',
        'estado',
        'nombre',
        'tipo_servicio',
        'tarifa',
        'color',
        'ruta_circular',
    ];

    public const TIPOS_SERVICIO = [
        'paradas_continuas' => 'Paradas continuas (Q1.00)',
        'directo' => 'Directo (Q2.00)',
    ];

    protected function casts(): array
    {
        return [
            'distancia_total_km' => 'decimal:2',
            'tarifa' => 'decimal:2',
            'ruta_circular' => 'boolean',
        ];
    }

    public function municipio(): BelongsTo
    {
        return $this->belongsTo(Municipio::class, 'id_municipio', 'id_municipio');
    }

    /** Estaciones de la línea en el orden de la ruta (puntos 1 y 12). */
    public function estaciones(): BelongsToMany
    {
        return $this->belongsToMany(Estacion::class, 'linea_estacion', 'id_linea', 'id_estacion')
            ->withPivot('orden_parada', 'distancia_previa_km')
            ->orderByPivot('orden_parada');
    }

    public function buses(): HasMany
    {
        return $this->hasMany(Bus::class, 'id_linea_asignada', 'id_linea');
    }

    public function horarios(): HasMany
    {
        return $this->hasMany(HorarioLinea::class, 'id_linea', 'id_linea');
    }

    public function viajes(): HasMany
    {
        return $this->hasMany(Viaje::class, 'id_linea', 'id_linea');
    }

    /** Punto 5: mínimo de buses = número de estaciones. */
    public function minimoBuses(): int
    {
        return $this->estaciones()->count();
    }

    /** Punto 5: máximo de buses = doble del número de estaciones. */
    public function maximoBuses(): int
    {
        return 2 * $this->estaciones()->count();
    }

    /** Punto 8: todos los accesos de las estaciones de la línea. */
    public function accesos()
    {
        return Acceso::whereIn('id_estacion', $this->estaciones()->pluck('estaciones.id_estacion'))->get();
    }

    /** Horario de servicio del día indicado (o de hoy). */
    public function horarioDelDia(?\DateTimeInterface $fecha = null): ?HorarioLinea
    {
        $tipo = HorarioLinea::tipoDiaDe($fecha ?? now());

        return $this->horarios->firstWhere('tipo_dia', $tipo);
    }

    /** ¿La línea está en su horario de servicio en este momento? */
    public function enServicio(?\DateTimeInterface $momento = null): bool
    {
        $momento ??= now();
        $horario = $this->horarioDelDia($momento);
        $hora = $momento->format('H:i');

        return $this->estado === 'activa' && $horario && $hora >= $horario->inicio() && $hora <= $horario->fin();
    }
}
