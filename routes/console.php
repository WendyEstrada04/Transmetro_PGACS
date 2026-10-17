<?php

use App\Models\Estacion;
use App\Services\ExportadorTrazos;
use App\Services\Reportes;
use App\Services\SimuladorOperacion;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Guarda los trazos de las rutas y la ubicación de las estaciones en database/seeders/Datos/trazos.json
// para que "php artisan db:seed" los conserve.
Artisan::command('transmetro:guardar-trazos {--ruta= : Archivo de destino}', function (ExportadorTrazos $exportador) {
    $ruta = $exportador->exportar($this->option('ruta') ?: null);
    $this->info("Trazos guardados en {$ruta}");
})->purpose('Guarda los trazos de las rutas en un archivo para los datos de prueba');

// Escribe docs/Consultas_Reportes.sql con las consultas SQL de los reportes (punto 15), para el documento.
Artisan::command('transmetro:consultas-reportes', function () {
    file_put_contents(base_path('docs/Consultas_Reportes.sql'), Reportes::archivoSql());
    $this->info('Consultas guardadas en docs/Consultas_Reportes.sql');
})->purpose('Guarda las consultas SQL de los reportes en docs/');

// Simulación de la operación desde la terminal (sin abrir el navegador).
// Ejemplo: php artisan transmetro:simular --ciclos=30 --pausa=10   (5 minutos, un ciclo cada 10 s)
Artisan::command('transmetro:simular {--ciclos=1 : Cuántos ciclos ejecutar} {--pausa=0 : Segundos entre ciclos} {--pico= : Nombre de una estación para provocar hora pico al inicio}', function (SimuladorOperacion $simulador) {
    if ($nombre = $this->option('pico')) {
        $estacion = Estacion::where('nombre', $nombre)->first();
        if (! $estacion) {
            $this->error("No existe la estación {$nombre}.");

            return 1;
        }
        DB::transaction(fn () => $simulador->horaPico($estacion));
        $this->warn("Hora pico en {$estacion->nombre}.");
    }

    $ciclos = max(1, (int) $this->option('ciclos'));
    for ($i = 1; $i <= $ciclos; $i++) {
        foreach (array_reverse($simulador->ciclo()) as $evento) {
            $this->line("[ciclo {$simulador->numeroCiclo()}] {$evento['hora']} {$evento['texto']}");
        }
        if ($i < $ciclos && (int) $this->option('pausa') > 0) {
            sleep((int) $this->option('pausa'));
        }
    }

    return 0;
})->purpose('Simula pasajeros, buses, alertas y refuerzos (puntos 1, 14 y 15)');

// Cierra todas las sesiones abiertas: al levantar el sistema (iniciar.bat) siempre se empieza en la pantalla de inicio de sesión.
Artisan::command('transmetro:cerrar-sesiones', function () {
    $cerradas = 0;
    if (Schema::hasTable('sessions')) {
        $cerradas += DB::table('sessions')->delete();
    }
    foreach (File::glob(storage_path('framework/sessions/*')) as $archivo) {
        if (basename($archivo) !== '.gitignore' && File::delete($archivo)) {
            $cerradas++;
        }
    }
    DB::table('users')->whereNotNull('remember_token')->update(['remember_token' => null]);
    $this->info("Sesiones cerradas: {$cerradas}.");
})->purpose('Cierra todas las sesiones para empezar en el inicio de sesión');

// Motor de la simulación en segundo plano: mientras la simulación esté encendida ejecuta un ciclo cada pocos
// segundos, sin depender de que haya una página abierta. Lo arranca iniciar.bat junto con el servidor web,
// así el servidor queda libre para atender las pantallas (antes cada ciclo lo frenaba unos segundos).
Artisan::command('transmetro:motor {--pausa=5 : Segundos entre ciclos}', function (SimuladorOperacion $simulador) {
    $pausa = max(2, (int) $this->option('pausa'));
    $this->info("Motor de simulación en marcha (un ciclo cada {$pausa} s mientras la simulación esté encendida).");

    while (true) {
        try {
            $simulador->latido();
            if ($simulador->activa()) {
                $simulador->ciclo();
            }
        } catch (Throwable $e) {
            // Si se corta la conexión con la base de datos, se reintenta en el siguiente ciclo.
            report($e);
            DB::reconnect();
        }
        sleep($pausa);
    }
})->purpose('Ejecuta la simulación en segundo plano mientras esté encendida');
