<?php

use App\Models\Empleado;

/*
 * Registro de empleados: el puesto y el teléfono y el correo por separado (se guardan cifrados).
 */

function datosPiloto(array $cambios = []): array
{
    return $cambios + [
        'rol' => 'piloto',
        'nombre_completo' => 'Ana Prueba López',
        'dpi' => '1234567890123',
        'fecha_nacimiento' => '1990-05-10',
        'telefono' => '5555-1234',
        'correo' => 'ana.prueba@correo.gt',
        'direccion' => 'Zona 1, Ciudad de Guatemala',
        'activo' => '1',
        'numero_licencia' => 'LIC-PRUEBA-1',
        'tipo_licencia' => 'A',
        'anios_experiencia' => 5,
        'nivel_educativo' => 'Diversificado',
    ];
}

test('el formulario de nuevo empleado pide puesto, teléfono y correo por separado', function () {
    $this->actingAs($this->administrador())->get(route('admin.personal.create'))->assertOk()
        ->assertSee('Puesto')
        ->assertSee('name="telefono"', false)
        ->assertSee('name="correo"', false)
        ->assertDontSee('Comunicación (teléfono / correo)');
});

test('se guardan el teléfono y el correo y se vuelven a mostrar por separado', function () {
    $this->actingAs($this->administrador())->post(route('admin.personal.store'), datosPiloto())
        ->assertSessionHasNoErrors();

    $empleado = Empleado::where('dpi', '1234567890123')->firstOrFail();
    expect($empleado->contacto_encriptado)->toBe('Tel. 5555-1234 · ana.prueba@correo.gt')
        ->and($empleado->telefono())->toBe('5555-1234')
        ->and($empleado->correo())->toBe('ana.prueba@correo.gt');

    $this->actingAs($this->administrador())->get(route('admin.personal.edit', $empleado))
        ->assertSee('value="5555-1234"', false)
        ->assertSee('value="ana.prueba@correo.gt"', false);
});

test('el correo es opcional pero el teléfono debe ser válido', function () {
    $this->actingAs($this->administrador())->post(route('admin.personal.store'), datosPiloto(['correo' => '', 'telefono' => 'abc']))
        ->assertSessionHasErrors('telefono');

    $this->actingAs($this->administrador())->post(route('admin.personal.store'), datosPiloto(['correo' => '']))
        ->assertSessionHasNoErrors();
    expect(Empleado::where('dpi', '1234567890123')->firstOrFail()->contacto_encriptado)->toBe('Tel. 5555-1234');
});

test('los contactos de los datos de prueba se separan bien', function () {
    $empleado = Empleado::firstOrFail();

    expect($empleado->telefono())->toMatch('/^5\d{3}-\d{4}$/')
        ->and($empleado->correo())->toContain('@');
});
