<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;

/**
 * Convierte los errores de la base de datos (reglas de negocio de los triggers,
 * datos duplicados, llaves foráneas) en mensajes claros para el usuario.
 */
trait ManejaErroresBD
{
    protected function mensajeBD(QueryException $e): string
    {
        $codigo = $e->errorInfo[0] ?? (string) $e->getCode();
        $detalle = $e->errorInfo[2] ?? $e->getMessage();

        return match ($codigo) {
            '23505' => 'Ya existe un registro con ese dato (valor duplicado).',
            '23503' => 'No se puede completar la acción: el registro está relacionado con otros datos del sistema.',
            '23514' => 'Algún valor no cumple las reglas de la base de datos (revisa los rangos permitidos).',
            'P0001' => self::conTildes(preg_match('/ERROR:\s+(.+?)(\r?\n|$)/', $detalle, $m) ? trim($m[1]) : $detalle),
            default => 'Ocurrió un error al guardar en la base de datos.',
        };
    }

    /** Los mensajes de los triggers se escriben sin tildes en SQL; aquí se muestran bien escritos. */
    private static function conTildes(string $texto): string
    {
        return preg_replace_callback('/\b(estacion|quedaria|alcanzo|maximo|numero|esta|mas)\b/u', fn ($m) => [
            'estacion' => 'estación', 'quedaria' => 'quedaría', 'alcanzo' => 'alcanzó',
            'maximo' => 'máximo', 'numero' => 'número', 'esta' => 'está', 'mas' => 'más',
        ][$m[1]], $texto);
    }

    protected function volverConErrorBD(QueryException $e): RedirectResponse
    {
        report($e);

        return back()->withInput()->withErrors(['bd' => $this->mensajeBD($e)]);
    }
}
