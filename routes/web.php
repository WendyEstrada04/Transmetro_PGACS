<?php

use App\Http\Controllers\Admin\InicioController;
use App\Http\Controllers\Auth\LoginController;
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

    // Administrador (municipalidad)
    Route::middleware('rol:administrador')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/', InicioController::class)->name('inicio');
    });

    // Operador de estación
    Route::middleware('rol:operador')->prefix('operador')->name('operador.')->group(function () {
        Route::get('/', [EstacionController::class, 'show'])->name('estacion');
        Route::post('/aforo', [EstacionController::class, 'actualizarAforo'])->name('aforo');
        Route::post('/alertas/{alerta}/atender', [EstacionController::class, 'atenderAlerta'])->name('alertas.atender');
    });
});
