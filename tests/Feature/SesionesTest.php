<?php

use Illuminate\Support\Facades\DB;

/*
 * Al levantar el sistema siempre se empieza en el inicio de sesión.
 */

test('el comando cierra las sesiones abiertas y la página vuelve a pedir usuario', function () {
    DB::table('sessions')->insert(['id' => 'sesion-prueba', 'user_id' => $this->operador()->id, 'payload' => '', 'last_activity' => time()]);

    $this->artisan('transmetro:cerrar-sesiones')->assertSuccessful();

    expect(DB::table('sessions')->count())->toBe(0);
    $this->get('/')->assertRedirect(route('login'));
});

test('la sesión se cierra al cerrar el navegador', function () {
    expect(config('session.expire_on_close'))->toBeTrue();
});
