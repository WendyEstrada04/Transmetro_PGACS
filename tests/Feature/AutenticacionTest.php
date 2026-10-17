<?php

/*
 * Inicio de sesión y control de acceso por rol (seguridad OWASP A01 / A07).
 */

test('las pantallas internas piden iniciar sesión', function (string $ruta) {
    $this->get($ruta)->assertRedirect(route('login'));
})->with(['/panel', '/mapa', '/admin', '/admin/lineas', '/operador']);

test('el administrador inicia sesión y llega a su panel', function () {
    $this->post('/login', ['email' => 'admin@gmail.com', 'password' => 'admin123'])
        ->assertRedirect(route('panel'));

    $this->assertAuthenticatedAs($this->administrador());
    $this->get('/panel')->assertRedirect(route('admin.inicio'));
});

test('el operador inicia sesión y llega a su estación', function () {
    $this->post('/login', ['email' => 'operador@gmail.com', 'password' => 'operador123']);

    $this->assertAuthenticatedAs($this->operador());
    $this->get('/panel')->assertRedirect(route('operador.inicio'));
});

test('una contraseña incorrecta no deja entrar y muestra un mensaje genérico', function () {
    $this->from('/login')
        ->post('/login', ['email' => 'admin@gmail.com', 'password' => 'incorrecta'])
        ->assertRedirect('/login')
        ->assertSessionHasErrors(['email' => 'El correo o la contraseña no son correctos.']);

    $this->assertGuest();
});

test('después de 5 intentos fallidos se bloquea el inicio de sesión', function () {
    foreach (range(1, 5) as $intento) {
        $this->post('/login', ['email' => 'admin@gmail.com', 'password' => 'mala'.$intento]);
    }

    // Aunque ahora la contraseña sea la correcta, sigue bloqueado.
    $this->post('/login', ['email' => 'admin@gmail.com', 'password' => 'admin123'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('cerrar sesión saca al usuario', function () {
    $this->actingAs($this->administrador())->post('/logout')->assertRedirect(route('login'));
    $this->assertGuest();
});

test('un operador no puede entrar a la administración', function () {
    $this->actingAs($this->operador())->get('/admin')->assertForbidden();
    $this->actingAs($this->operador())->get('/admin/tarjetas')->assertForbidden();
});

test('un administrador no puede usar el panel del operador', function () {
    $this->actingAs($this->administrador())->get('/operador')->assertForbidden();
});
