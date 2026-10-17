<?php

namespace App\Services;

use App\Models\BitacoraAuditoria;
use App\Models\User;

/**
 * Registro en la bitácora de auditoría (RF-17, RNF-07): quién hizo qué cambio, en qué registro y cuándo.
 * Los datos personales (DPI, teléfono, correo, dirección e historial educativo) nunca se copian a la bitácora:
 * solo se indica que fueron modificados (RNF-12).
 */
class Auditoria
{
    public static function registrar(string $tabla, ?string $id, string $accion, ?array $antes, ?array $despues, ?User $usuario = null): void
    {
        $usuario ??= auth()->user();

        BitacoraAuditoria::create([
            'id_usuario' => $usuario?->id,
            'id_empleado_usuario' => $usuario?->id_empleado,
            'tabla_afectada' => $tabla,
            'id_registro_afectado' => $id,
            'accion' => $accion,
            'datos_previos' => $antes,
            'datos_nuevos' => $despues,
        ]);
    }

    /** Campos que identifican el registro (placa, nombre, línea, estación…), en orden de preferencia. */
    public const IDENTIFICADORES = ['placa', 'bus', 'nombre_completo', 'linea', 'estacion', 'acceso'];

    /**
     * Registra un cambio solo con los campos que cambiaron; si nada cambió, no registra nada.
     * Guarda además el nombre del registro en "_registro" (por ejemplo la placa), para que el cambio se
     * pueda reconocer y buscar aunque el registro se elimine después.
     */
    public static function cambio(string $tabla, string $id, array $antes, array $despues, ?User $usuario = null): void
    {
        [$a, $d] = self::diferencias($antes, $despues);
        if ($d === []) {
            return;
        }
        $valor = fn ($c) => $despues[$c] ?? $antes[$c] ?? null;
        $nombre = collect(['placa', 'bus', 'nombre_completo', 'linea'])->map($valor)->filter()->first()
            ?? collect(['estacion', 'acceso'])->map($valor)->filter()->implode(' · ');
        if ($nombre !== '' && ! array_intersect_key($d, array_flip(self::IDENTIFICADORES))) {
            $d['_registro'] = $nombre;
        }
        self::registrar($tabla, $id, 'UPDATE', $a, $d, $usuario);
    }

    /** @return array{0: array, 1: array} campos anteriores y nuevos que son distintos */
    public static function diferencias(array $antes, array $despues): array
    {
        $a = [];
        $d = [];
        foreach (array_unique(array_merge(array_keys($antes), array_keys($despues))) as $campo) {
            $va = $antes[$campo] ?? null;
            $vd = $despues[$campo] ?? null;
            if ((string) json_encode($va) !== (string) json_encode($vd)) {
                $a[$campo] = $va;
                $d[$campo] = $vd;
            }
        }

        return [$a, $d];
    }
}
