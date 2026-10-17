<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Limpieza automática de la operación simulada.
 *
 * Con la simulación encendida las 24 horas, cada día se generan miles de pagos, bajadas y paradas de buses.
 * La base de datos gratuita de Supabase permite 500 MB, así que el motor en segundo plano llama a esta clase
 * cada hora para borrar lo que ya es viejo:
 *  - pagos con tarjeta (transacciones_pago) y bajadas de los buses (bajadas_bus);
 *  - viajes ya terminados, con sus paradas (visitas_viaje se borran en cascada);
 *  - alertas ya cerradas o atendidas.
 * No se tocan las tarjetas ni sus saldos, las recargas, el personal, los buses, las líneas ni la bitácora
 * (que por regla no se puede borrar). Los reportes del día siguen completos; el historial queda limitado a
 * las últimas horas configuradas.
 */
class LimpiezaSimulacion
{
    /** Filas que se borran por consulta, para no bloquear la base de datos mucho tiempo. */
    private const LOTE = 20000;

    /** Horas que se conservan cuando la base pasa del límite de tamaño. */
    public const HORAS_DE_EMERGENCIA = 12;

    /**
     * Borra la operación simulada con más de $horas de antigüedad.
     *
     * @return array<string, int> filas borradas por tabla
     */
    public function limpiar(int $horas): array
    {
        $corte = Carbon::now()->subHours(max(1, $horas));

        return [
            'transacciones_pago' => $this->borrarPorLotes('transacciones_pago', 'fecha_hora < ?', [$corte]),
            'bajadas_bus' => $this->borrarPorLotes('bajadas_bus', 'fecha_hora < ?', [$corte]),
            'viajes' => $this->borrarPorLotes('viajes', "estado <> 'en_curso' AND fecha_hora_fin < ?", [$corte]),
            'alertas_sistema' => $this->borrarPorLotes('alertas_sistema', "estado <> 'abierta' AND fecha_cierre < ?", [$corte]),
        ];
    }

    /**
     * Limpieza normal según config/transmetro.php y, si la base sigue pasando del límite, limpieza de emergencia.
     *
     * @return array{horas: int, borrados: array<string, int>, tamano_mb: float}
     */
    public function limpiarSegunEspacio(): array
    {
        $horas = (int) config('transmetro.retencion_horas');
        $borrados = $horas > 0 ? $this->limpiar($horas) : [];

        $tamano = $this->tamanoBaseMb();
        if ($tamano > (int) config('transmetro.limite_base_mb')) {
            $horas = self::HORAS_DE_EMERGENCIA;
            foreach ($this->limpiar($horas) as $tabla => $n) {
                $borrados[$tabla] = ($borrados[$tabla] ?? 0) + $n;
            }
            $tamano = $this->tamanoBaseMb();
        }

        return ['horas' => $horas, 'borrados' => $borrados, 'tamano_mb' => $tamano];
    }

    /** Tamaño actual de la base de datos en MB (lo que cuenta Supabase para el límite de 500 MB). */
    public function tamanoBaseMb(): float
    {
        return round((float) DB::selectOne('SELECT pg_database_size(current_database()) AS b')->b / 1048576, 1);
    }

    private function borrarPorLotes(string $tabla, string $condicion, array $valores): int
    {
        $total = 0;
        do {
            $n = DB::delete(
                "DELETE FROM {$tabla} WHERE ctid IN (SELECT ctid FROM {$tabla} WHERE {$condicion} LIMIT ".self::LOTE.')',
                $valores
            );
            $total += $n;
        } while ($n === self::LOTE);

        return $total;
    }
}
