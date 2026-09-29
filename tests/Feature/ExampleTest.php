<?php

beforeEach(fn () => $this->withoutVite());

test('la raíz envía al inicio de sesión si no hay sesión', function () {
    $this->get('/')->assertRedirect(route('login'));
});

test('la pantalla de inicio de sesión se muestra', function () {
    $this->get('/login')->assertOk()->assertSee('Iniciar sesión');
});

test('el panel pide iniciar sesión', function () {
    $this->get('/panel')->assertRedirect(route('login'));
    $this->get('/admin')->assertRedirect(route('login'));
    $this->get('/operador')->assertRedirect(route('login'));
});
