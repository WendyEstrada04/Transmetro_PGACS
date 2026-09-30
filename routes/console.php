<?php

use App\Services\ExportadorTrazos;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Guarda los trazos de las rutas y la ubicación de las estaciones en database/seeders/Datos/trazos.json
// para que "php artisan db:seed" los conserve.
Artisan::command('transmetro:guardar-trazos {--ruta= : Archivo de destino}', function (ExportadorTrazos $exportador) {
    $ruta = $exportador->exportar($this->option('ruta') ?: null);
    $this->info("Trazos guardados en {$ruta}");
})->purpose('Guarda los trazos de las rutas en un archivo para los datos de prueba');
