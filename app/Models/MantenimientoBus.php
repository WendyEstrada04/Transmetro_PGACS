<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Historial de mantenimiento de un bus: fallas reportadas por el operador y mantenimientos realizados.
 */
class MantenimientoBus extends Model
{
    use HasUuids;

    protected $table = 'mantenimientos_bus';

    protected $primaryKey = 'id_mantenimiento';

    public $timestamps = false;

    public const TIPOS = ['falla' => 'Falla reportada', 'preventivo' => 'Mantenimiento preventivo', 'correctivo' => 'Reparación'];

    public const CATEGORIAS = [
        'motor' => 'Motor', 'frenos' => 'Frenos', 'llantas' => 'Llantas', 'puertas' => 'Puertas',
        'electrico' => 'Sistema eléctrico', 'aire' => 'Aire acondicionado', 'validador' => 'Validador de tarjetas',
        'limpieza' => 'Limpieza', 'otro' => 'Otro',
    ];

    public const PRIORIDADES = ['baja' => 'Baja', 'media' => 'Media', 'alta' => 'Alta (saca el bus de servicio)'];

    public const ESTADOS = ['reportado' => 'Reportado', 'en_proceso' => 'En taller', 'resuelto' => 'Resuelto'];

    protected $fillable = [
        'id_bus',
        'id_empleado_reporta',
        'tipo',
        'categoria',
        'descripcion',
        'prioridad',
        'estado',
        'kilometraje',
        'fecha_reporte',
        'fecha_cierre',
    ];

    protected function casts(): array
    {
        return [
            'kilometraje' => 'integer',
            'fecha_reporte' => 'datetime',
            'fecha_cierre' => 'datetime',
        ];
    }

    public function bus(): BelongsTo
    {
        return $this->belongsTo(Bus::class, 'id_bus', 'id_bus');
    }

    public function reportadoPor(): BelongsTo
    {
        return $this->belongsTo(Empleado::class, 'id_empleado_reporta', 'id_empleado');
    }

    public function abierto(): bool
    {
        return $this->estado !== 'resuelto';
    }
}
