<?php

use App\Models\Estacion;
use App\Services\ExportadorTrazos;
use App\Services\LimpiezaSimulacion;
use App\Services\Reportes;
use App\Services\SimuladorOperacion;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
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

// Motor de la simulación en segundo plano. Mientras la simulación esté encendida calcula la operación las 24 horas:
// un ciclo cada pocos segundos si alguien tiene una pantalla abierta y uno cada pocos minutos si nadie la ve.
// Además, cada hora borra la operación simulada vieja (LimpiezaSimulacion) y, en Render, visita /up cada 10 minutos
// para que el plan gratis no apague el servidor. iniciar.bat (laptop) y docker/iniciar.sh (Render) lo arrancan.
Artisan::command('transmetro:motor {--pausa=5 : Segundos entre cada revisión} {--minutos=0 : Terminar después de N minutos (0 = nunca); el arranque lo vuelve a iniciar}', function (SimuladorOperacion $simulador, LimpiezaSimulacion $limpieza) {
    $pausa = max(2, (int) $this->option('pausa'));
    $minutos = max(0, (int) $this->option('minutos'));
    $url = rtrim((string) config('transmetro.url_mantener_despierto'), '/');
    $inicio = time();
    $ultimoLatido = $ultimaLimpieza = $ultimaVisita = 0;
    $this->info('Motor de simulación en marcha.');

    while ($minutos === 0 || time() - $inicio < $minutos * 60) {
        try {
            if (time() - $ultimoLatido >= 60) {
                $simulador->latido();
                $ultimoLatido = time();
            }
            $simulador->cicloDelMotor();
            if (time() - $ultimaLimpieza >= 3600) {
                $ultimaLimpieza = time();
                $resultado = $limpieza->limpiarSegunEspacio();
                if (array_sum($resultado['borrados'])) {
                    $this->line('Limpieza: '.json_encode($resultado['borrados'])." · base {$resultado['tamano_mb']} MB");
                }
            }
        } catch (Throwable $e) {
            // Si se corta la conexión con la base de datos, se reintenta en la siguiente vuelta.
            report($e);
            DB::reconnect();
        }

        if ($url !== '' && time() - $ultimaVisita >= 600) {
            $ultimaVisita = time();
            try {
                Http::timeout(30)->get($url.'/up');
            } catch (Throwable) {
                // Si la visita falla no pasa nada: se intenta de nuevo en 10 minutos.
            }
        }
        sleep($pausa);
    }

    return 0;
})->purpose('Calcula la simulación en segundo plano, limpia los datos viejos y mantiene despierto el servidor');

// Borra a mano la operación simulada vieja. Ejemplo: php artisan transmetro:limpiar --horas=24
Artisan::command('transmetro:limpiar {--horas= : Conservar solo las últimas N horas (por defecto, config/transmetro.php)}', function (LimpiezaSimulacion $limpieza) {
    $horas = $this->option('horas') !== null ? (int) $this->option('horas') : (int) config('transmetro.retencion_horas');
    if ($horas <= 0) {
        $this->warn('La limpieza está desactivada (TRANSMETRO_RETENCION_HORAS=0). Use --horas=N.');

        return 0;
    }
    foreach ($limpieza->limpiar($horas) as $tabla => $n) {
        $this->line(str_pad($tabla, 20).$n.' filas borradas');
    }
    $this->info("Base de datos: {$limpieza->tamanoBaseMb()} MB");

    return 0;
})->purpose('Borra los pagos, bajadas, viajes y alertas simulados más antiguos que N horas');
