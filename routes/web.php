<?php

use App\Http\Controllers\Admin\BusController;
use App\Http\Controllers\Admin\EstacionController as AdminEstacionController;
use App\Http\Controllers\Admin\InicioController;
use App\Http\Controllers\Admin\LineaController;
use App\Http\Controllers\Admin\MunicipioController;
use App\Http\Controllers\Admin\PersonalController;
use App\Http\Controllers\Admin\TarjetaController;
use App\Http\Controllers\Admin\TrazoLineaController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\MapaController;
use App\Http\Controllers\Operador\EstacionController;
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

    // Administrador (municipalidad)
    Route::middleware('rol:administrador')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/', InicioController::class)->name('inicio');

        Route::resource('municipios', MunicipioController::class)->except('show')
            ->parameters(['municipios' => 'municipio']);

        Route::resource('estaciones', AdminEstacionController::class)->except('destroy')
            ->parameters(['estaciones' => 'estacion']);
        Route::controller(AdminEstacionController::class)->prefix('estaciones/{estacion}')->name('estaciones.')->group(function () {
            Route::post('accesos', 'guardarAcceso')->name('accesos.store');
            Route::put('accesos/{acceso}', 'actualizarAcceso')->name('accesos.update');
            Route::delete('accesos/{acceso}', 'eliminarAcceso')->name('accesos.destroy');
            Route::post('parqueos', 'guardarParqueo')->name('parqueos.store');
            Route::delete('parqueos/{parqueo}', 'eliminarParqueo')->name('parqueos.destroy');
        });

        Route::resource('lineas', LineaController::class)->parameters(['lineas' => 'linea']);
        Route::post('lineas/{linea}/estaciones', [LineaController::class, 'agregarEstacion'])->name('lineas.estaciones.store');
        Route::delete('lineas/{linea}/estaciones/{estacion}', [LineaController::class, 'quitarEstacion'])->name('lineas.estaciones.destroy');
        Route::put('lineas/{linea}/horarios', [LineaController::class, 'guardarHorarios'])->name('lineas.horarios');
        Route::get('lineas/{linea}/trazo', [TrazoLineaController::class, 'edit'])->name('lineas.trazo.edit');
        Route::put('lineas/{linea}/trazo', [TrazoLineaController::class, 'update'])->name('lineas.trazo.update');
        Route::post('lineas/{linea}/trazo/kml', [TrazoLineaController::class, 'importar'])->name('lineas.trazo.importar');

        Route::resource('buses', BusController::class)->except('show')->parameters(['buses' => 'bus']);
        Route::put('buses/{bus}/parqueo', [BusController::class, 'cambiarParqueo'])->name('buses.parqueo');

        Route::resource('personal', PersonalController::class)->except('show')->parameters(['personal' => 'empleado']);

        Route::resource('tarjetas', TarjetaController::class)->only(['index', 'create', 'store', 'show'])
            ->parameters(['tarjetas' => 'tarjeta']);
        Route::post('tarjetas/{tarjeta}/recargas', [TarjetaController::class, 'recargar'])->name('tarjetas.recargar');
        Route::patch('tarjetas/{tarjeta}/estado', [TarjetaController::class, 'cambiarEstado'])->name('tarjetas.estado');
    });

    // Operador de estación
    Route::middleware('rol:operador')->prefix('operador')->name('operador.')->group(function () {
        Route::get('/', [EstacionController::class, 'show'])->name('estacion');
        Route::post('/aforo', [EstacionController::class, 'actualizarAforo'])->name('aforo');
        Route::post('/alertas/{alerta}/atender', [EstacionController::class, 'atenderAlerta'])->name('alertas.atender');
        Route::post('/pases', [EstacionController::class, 'registrarPase'])->name('pases');
    });
});
