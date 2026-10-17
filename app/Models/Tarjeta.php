<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Tarjeta Ciudadana · Ciudad Integrada (punto 18). Tres tipos:
 *  - Vecino (18 a 59 años): cuesta Q20. Se puede afiliar con un perfil en la app/web de POCAE; así, si se pierde
 *    o la roban, el saldo y los datos pasan a una tarjeta nueva. Sin afiliar funciona al portador.
 *  - Adulto mayor (60 años o más): la primera es gratis y la reposición cuesta Q20. Tiene 4 viajes de
 *    cortesía diarios en Transmetro (no acumulables, de lunes a domingo).
 *  - Persona con discapacidad: gratuita.
 * Atrás lleva impreso su número (00 0040 0001) y un código QR con ese número.
 *
 * La emisión, afiliación, reposición y recargas se hacen en los puntos de atención (POCAE) y de recarga.
 * En el sistema del Transmetro el administrador consulta las tarjetas y controla su estado:
 * bloquear, desbloquear y dar de baja.
 */
class Tarjeta extends Model
{
    use HasUuids;

    protected $table = 'tarjetas';

    protected $primaryKey = 'id_tarjeta';

    public $timestamps = false;

    protected $fillable = [
        'numero_tarjeta',
        'saldo',
        'estado',
        'fecha_emision',
        'tipo_tarjeta',
        'nombre_titular',
        'dpi_titular',
        'costo_emision',
        'viajes_gratis_restantes',
        'afiliada',
        'fecha_nacimiento_titular',
        'id_tarjeta_anterior',
        'motivo_estado',
        'fecha_estado',
    ];

    public const TIPOS = [
        'vecino' => 'Vecino',
        'adulto_mayor' => 'Adulto mayor',
        'discapacidad' => 'Persona con discapacidad',
    ];

    /** Para quién es cada tarjeta. */
    public const DESCRIPCIONES = [
        'vecino' => 'De 18 a 59 años · Q20 · se puede afiliar (perfil POCAE) o usar al portador',
        'adulto_mayor' => 'De 60 años en adelante · la primera es gratis · 4 viajes de cortesía diarios',
        'discapacidad' => 'Para personas con discapacidad · gratuita',
    ];

    /** Edades permitidas por tipo (null = sin límite). */
    public const EDADES = [
        'vecino' => [18, 59],
        'adulto_mayor' => [60, null],
        'discapacidad' => [null, null],
    ];

    /** Costo de la tarjeta Vecino y de la reposición de una de adulto mayor. */
    public const COSTO_EMISION = 20.00;

    public const COSTO_REPOSICION = 20.00;

    /** Viajes de bienvenida de una tarjeta Vecino nueva. */
    public const VIAJES_GRATIS = 5;

    /** Viajes de cortesía por día de la tarjeta de adulto mayor (no acumulables). */
    public const CORTESIA_DIARIA_ADULTO_MAYOR = 4;

    public const RECARGA_MINIMA = 5;

    public const RECARGA_MAXIMA = 200;

    public const ESTADOS = [
        'activa' => 'Activa',
        'bloqueada' => 'Bloqueada',
        'vencida' => 'Vencida',
        'reemplazada' => 'Reemplazada',
        'baja' => 'Dada de baja',
    ];

    /** Motivos para bloquear una tarjeta (se puede desbloquear después). */
    public const MOTIVOS_BLOQUEO = [
        'robo_extravio' => 'Robo o extravío reportado',
        'uso_indebido' => 'Uso indebido o sospecha de fraude',
        'solicitud_titular' => 'Solicitud del titular',
        'falla_lectura' => 'Falla de lectura en los validadores',
    ];

    /** Motivos para dar de baja una tarjeta (la baja es definitiva). */
    public const MOTIVOS_BAJA = [
        'fraude' => 'Fraude comprobado',
        'danada' => 'Tarjeta dañada sin reposición',
        'robo_sin_afiliacion' => 'Robo o extravío de tarjeta al portador',
        'solicitud_titular' => 'Solicitud del titular',
        'vencimiento' => 'Vencimiento',
    ];

    /** Costo de emitir una tarjeta nueva de este tipo (o de reponerla). */
    public static function costo(string $tipo, bool $reposicion = false): float
    {
        return match ($tipo) {
            'vecino' => self::COSTO_EMISION,
            'adulto_mayor' => $reposicion ? self::COSTO_REPOSICION : 0.0,
            default => 0.0,
        };
    }

    /** Quita espacios y guiones: 00 0040 0001 → 0000400001. */
    public static function normalizarNumero(?string $numero): string
    {
        return preg_replace('/\D/', '', (string) $numero);
    }

    /** 0000400001 → 00 0040 0001 (como va impreso atrás de la tarjeta). */
    public static function formatearNumero(string $numero): string
    {
        return strlen($numero) === 10 ? substr($numero, 0, 2).' '.substr($numero, 2, 4).' '.substr($numero, 6, 4) : $numero;
    }

    /** Siguiente número libre (las tarjetas se numeran en orden). */
    public static function siguienteNumero(): string
    {
        $ultimo = (int) static::query()->max('numero_tarjeta');

        return str_pad((string) max($ultimo + 1, 400001), 10, '0', STR_PAD_LEFT);
    }

    public function numeroFormateado(): string
    {
        return self::formatearNumero($this->numero_tarjeta);
    }

    /** Vecino sin afiliar: la tarjeta es de quien la tenga y no se puede reponer. */
    public function alPortador(): bool
    {
        return ! $this->afiliada;
    }

    public function estadoTexto(): string
    {
        return self::ESTADOS[$this->estado] ?? ucfirst((string) $this->estado);
    }

    /** Dada de baja o reemplazada: su estado ya no cambia (regla trg_tarjetas_estado_final). */
    public function estadoDefinitivo(): bool
    {
        return in_array($this->estado, ['baja', 'reemplazada'], true);
    }

    /** Viajes de cortesía que le quedan hoy a una tarjeta de adulto mayor. */
    public function cortesiasRestantesHoy(): int
    {
        if ($this->tipo_tarjeta !== 'adulto_mayor') {
            return 0;
        }
        $usadas = $this->transacciones()->where('viaje_gratis', true)->where('fecha_hora', '>=', now()->startOfDay())->count();

        return max(0, self::CORTESIA_DIARIA_ADULTO_MAYOR - $usadas);
    }

    protected function casts(): array
    {
        return [
            'saldo' => 'decimal:2',
            'fecha_emision' => 'datetime',
            'costo_emision' => 'decimal:2',
            'viajes_gratis_restantes' => 'integer',
            'afiliada' => 'boolean',
            'fecha_nacimiento_titular' => 'date',
            'fecha_estado' => 'datetime',
        ];
    }

    public function recargas(): HasMany
    {
        return $this->hasMany(Recarga::class, 'id_tarjeta', 'id_tarjeta');
    }

    public function anterior(): BelongsTo
    {
        return $this->belongsTo(self::class, 'id_tarjeta_anterior', 'id_tarjeta');
    }

    public function reposicion(): HasOne
    {
        return $this->hasOne(self::class, 'id_tarjeta_anterior', 'id_tarjeta');
    }

    public function transacciones(): HasMany
    {
        return $this->hasMany(TransaccionPago::class, 'id_tarjeta', 'id_tarjeta');
    }
}
