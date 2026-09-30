<?php

namespace App\Services;

use App\Models\Linea;

/**
 * Guarda en un archivo JSON el trazo de cada línea y la ubicación de sus estaciones.
 *
 * Los datos de prueba (php artisan db:seed) BORRAN y vuelven a cargar las líneas; con este
 * archivo (database/seeders/Datos/trazos.json) el seeder conserva los trazos dibujados en el
 * mapa y las estaciones que se movieron a su ubicación correcta. El archivo se sube a GitHub.
 */
class ExportadorTrazos
{
    public static function rutaPorDefecto(): string
    {
        return database_path('seeders/Datos/trazos.json');
    }

    /** @return array<string, array{trazo: array, estaciones: array<string, array{0: float, 1: float}>}> */
    public function datos(): array
    {
        $datos = [];
        foreach (Linea::with('estaciones')->orderBy('codigo_linea')->get() as $linea) {
            $datos[$linea->codigo_linea] = [
                'trazo' => $linea->tieneTrazo() ? $linea->trazo : [],
                'estaciones' => $linea->estaciones->mapWithKeys(fn ($e) => [
                    $e->nombre => [round((float) $e->latitud, 6), round((float) $e->longitud, 6)],
                ])->all(),
            ];
        }

        return $datos;
    }

    public function exportar(?string $ruta = null): string
    {
        $ruta ??= self::rutaPorDefecto();
        $json = json_encode($this->datos(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        // Cada punto [lat, lon] en una sola línea para que el archivo sea fácil de leer.
        $json = preg_replace('/\[\s+(-?[\d.]+),\s+(-?[\d.]+)\s+\]/', '[$1, $2]', $json);
        file_put_contents($ruta, $json."\n");

        return $ruta;
    }
}
