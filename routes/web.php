<?php

use App\Http\Controllers\Admin\BitacoraController;
use App\Http\Controllers\Admin\BusController;
use App\Http\Controllers\Admin\EstacionController as AdminEstacionController;
use App\Http\Controllers\Admin\InicioController;
use App\Http\Controllers\Admin\LineaController;
use App\Http\Controllers\Admin\MantenimientoController;
use App\Http\Controllers\Admin\MunicipioController;
use App\Http\Controllers\Admin\PanelLineaController;
use App\Http\Controllers\Admin\PersonalController;
use App\Http\Controllers\Admin\ReporteController;
use App\Http\Controllers\Admin\SimulacionController;
use App\Http\Controllers\Admin\TarjetaController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\MapaController;
use App\Http\Controllers\Operador\EstacionController as OperadorEstacionController;
use App\Http\Controllers\PanelController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route(auth()->check() ? 'panel' : 'login'));

// Invitados (sin sesión)
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');
});

// Usuarios con sesión
Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
    Route::get('/panel', PanelController::class)->name('panel');
    Route::get('/mapa', MapaController::class)->name('mapa');
    Route::get('/mapa/buses', [MapaController::class, 'buses'])->name('mapa.buses');
    Route::post('/mapa/simulacion', [MapaController::class, 'simulacion'])->name('mapa.simulacion');
    Route::post('/mapa/simulacion/ciclo', [MapaController::class, 'ciclo'])->name('mapa.ciclo');

    // Administrador (municipalidad)
    Route::middleware('rol:administrador')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/', InicioController::class)->name('inicio');

        // Líneas, estaciones y municipios son los oficiales del Transmetro: solo se consultan.
        // En la operación diaria se cambia el estado de una línea y el de cada acceso (molinete).
        Route::get('municipios', [MunicipioController::class, 'index'])->name('municipios.index');

        Route::resource('estaciones', AdminEstacionController::class)->only(['index', 'show'])
            ->parameters(['estaciones' => 'estacion']);
        Route::patch('estaciones/{estacion}/accesos/{acceso}/estado', [AdminEstacionController::class, 'cambiarEstadoAcceso'])
            ->name('estaciones.accesos.estado');

        Route::get('lineas/{linea}/panel', PanelLineaController::class)->name('lineas.panel');
        Route::resource('lineas', LineaController::class)->only(['index', 'show'])->parameters(['lineas' => 'linea']);
        Route::patch('lineas/{linea}/estado', [LineaController::class, 'cambiarEstado'])->name('lineas.estado');

        Route::resource('buses', BusController::class)->except('show')->parameters(['buses' => 'bus']);
        Route::put('buses/{bus}/parqueo', [BusController::class, 'cambiarParqueo'])->name('buses.parqueo');
        Route::patch('buses/{bus}/molinetes/{molinete}/estado', [BusController::class, 'cambiarEstadoMolinete'])->name('buses.molinetes.estado');
        Route::post('buses/{bus}/mantenimientos', [MantenimientoController::class, 'store'])->name('buses.mantenimientos.store');
        Route::patch('mantenimientos/{mantenimiento}', [MantenimientoController::class, 'update'])->name('mantenimientos.update');

        Route::get('bitacora', [BitacoraController::class, 'index'])->name('bitacora.index');
        Route::resource('personal', PersonalController::class)->except('show')->parameters(['personal' => 'empleado']);

        Route::get('reportes', [ReporteController::class, 'index'])->name('reportes.index');
        Route::get('reportes/{reporte}', [ReporteController::class, 'show'])->name('reportes.show');

        // Simulación de la operación (pasajeros, buses, alertas y refuerzos automáticos)
        Route::controller(SimulacionController::class)->prefix('simulacion')->name('simulacion.')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('estado', 'estado')->name('estado');
            Route::post('ciclo', 'ciclo')->name('ciclo');
            Route::post('iniciar', 'iniciar')->name('iniciar');
            Route::post('pausar', 'pausar')->name('pausar');
            Route::post('hora-pico', 'horaPico')->name('hora-pico');
            Route::post('reiniciar', 'reiniciar')->name('reiniciar');
        });

        // Tarjetas: solo consulta y control de estado (emisión y recargas se hacen en POCAE y los puntos de recarga).
        Route::resource('tarjetas', TarjetaController::class)->only(['index', 'show'])
            ->parameters(['tarjetas' => 'tarjeta']);
        Route::patch('tarjetas/{tarjeta}/bloquear', [TarjetaController::class, 'bloquear'])->name('tarjetas.bloquear');
        Route::patch('tarjetas/{tarjeta}/desbloquear', [TarjetaController::class, 'desbloquear'])->name('tarjetas.desbloquear');
        Route::patch('tarjetas/{tarjeta}/baja', [TarjetaController::class, 'darDeBaja'])->name('tarjetas.baja');
    });

    // Operador: trabaja en la PC de una estación (el administrador no ve esta pantalla)
    Route::middleware('rol:operador')->prefix('operador')->name('operador.')->controller(OperadorEstacionController::class)->group(function () {
        Route::get('/', 'show')->name('inicio');
        Route::post('/refuerzo', 'solicitarRefuerzo')->name('refuerzo');
        Route::post('/alertas/{alerta}/atender', 'atenderAlerta')->name('alertas.atender');
        Route::patch('/accesos/{acceso}/estado', 'cambiarEstadoAcceso')->name('accesos.estado');
        Route::post('/pases', 'registrarPase')->name('pases');
        Route::post('/bajadas', 'registrarBajadas')->name('bajadas');
        Route::post('/fallas', 'reportarFalla')->name('fallas');
    });
});
