<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Horario de servicio de una línea: hora de inicio y fin en las estaciones extremo.
 */
class HorarioLinea extends Model
{
    use HasUuids;

    public const TIPOS_DIA = [
        'lunes_viernes' => 'Lunes a viernes',
        'sabado' => 'Sábado',
        'domingo_feriado' => 'Domingo y feriados',
    ];

    /** Horario oficial vigente de las líneas del Transmetro (muniguate.com). */
    public const HORARIO_OFICIAL = [
        'lunes_viernes' => ['05:00', '20:00'],
        'sabado' => ['05:00', '19:00'],
        'domingo_feriado' => ['06:30', '19:00'],
    ];

    protected $table = 'horarios_linea';

    protected $primaryKey = 'id_horario';

    public $timestamps = false;

    protected $fillable = [
        'id_linea',
        'tipo_dia',
        'hora_inicio',
        'hora_fin',
    ];

    public function linea(): BelongsTo
    {
        return $this->belongsTo(Linea::class, 'id_linea', 'id_linea');
    }

    /** Tipo de día que corresponde a una fecha (los feriados se tratan como domingo). */
    public static function tipoDiaDe(\DateTimeInterface $fecha): string
    {
        return match ((int) $fecha->format('w')) {
            0 => 'domingo_feriado',
            6 => 'sabado',
            default => 'lunes_viernes',
        };
    }

    public function inicio(): string
    {
        return substr($this->hora_inicio, 0, 5);
    }

    public function fin(): string
    {
        return substr($this->hora_fin, 0, 5);
    }
}
