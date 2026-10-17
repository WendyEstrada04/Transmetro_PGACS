<?php

use App\Models\BitacoraAuditoria;
use App\Models\Bus;
use App\Models\Empleado;
use App\Models\Estacion;
use App\Models\Linea;
use App\Models\Tarjeta;
use App\Models\User;
use App\Services\Auditoria;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * RF-17 y RNF-07: bitácora de auditoría. Cada operación importante queda registrada con su usuario,
 * fecha y los datos anteriores y nuevos; el administrador la consulta y nadie la puede modificar.
 */

function empleadoParaBitacora(array $cambios = []): array
{
    return $cambios + [
        'rol' => 'piloto', 'nombre_completo' => 'Bitácora Prueba Pérez', 'dpi' => '5566778899001',
        'fecha_nacimiento' => '1988-03-15', 'telefono' => '5555-9090', 'correo' => 'bitacora@correo.gt',
        'direccion' => 'Zona 5, Ciudad de Guatemala', 'activo' => '1', 'numero_licencia' => 'LIC-BITA-1',
        'tipo_licencia' => 'A', 'anios_experiencia' => 4, 'nivel_educativo' => 'Diversificado',
    ];
}

function ultimoRegistro(string $tabla): ?BitacoraAuditoria
{
    return BitacoraAuditoria::where('tabla_afectada', $tabla)->orderByDesc('fecha_registro')->first();
}

test('el administrador ve la bitácora en el menú Gestión y con sus cifras', function () {
    $this->actingAs($this->administrador())->get(route('admin.bitacora.index'))
        ->assertOk()
        ->assertSee('Bitácora de auditoría')
        ->assertSee('Quién cambió qué y cuándo')
        ->assertSee('id="lista-bitacora"', false)
        ->assertSee('data-cifra="registros"', false);
});

test('el operador no puede ver la bitácora', function () {
    $this->actingAs($this->operador())->get(route('admin.bitacora.index'))->assertForbidden();
});

test('editar un bus deja en la bitácora solo lo que cambió, con quién y cuándo', function () {
    $admin = $this->administrador();
    $bus = Bus::firstOrFail();
    $antes = $bus->capacidad_maxima;

    $this->actingAs($admin)->put(route('admin.buses.update', $bus), [
        'placa' => $bus->placa, 'tipo_unidad' => $bus->tipo_unidad, 'capacidad_maxima' => $antes + 1,
        'id_linea_asignada' => $bus->id_linea_asignada, 'estado_logico' => $bus->estado_logico,
    ])->assertSessionHasNoErrors();

    $r = ultimoRegistro('buses');
    expect($r->accion)->toBe('UPDATE')
        ->and($r->id_registro_afectado)->toBe($bus->id_bus)
        ->and($r->id_usuario)->toBe($admin->id)
        ->and($r->datos_previos)->toBe(['capacidad_maxima' => $antes])
        ->and($r->datos_nuevos)->toEqual(['capacidad_maxima' => $antes + 1, '_registro' => $bus->placa])
        ->and($r->fecha_registro)->not->toBeNull();

    $this->actingAs($admin)->get(route('admin.bitacora.index', ['modulo' => 'buses']))
        ->assertOk()->assertSee($bus->placa)->assertSee('Capacidad')->assertSee((string) ($antes + 1));
});

test('registrar y eliminar un bus quedan en la bitácora', function () {
    $admin = $this->administrador();
    $libre = DB::table('parqueos')->whereNull('id_bus_asignado')->value('id_parqueo');
    $this->actingAs($admin)->post(route('admin.buses.store'), [
        'placa' => 'C998BTA', 'tipo_unidad' => 'articulado', 'capacidad_maxima' => 160,
        'id_linea_asignada' => null, 'estado_logico' => 'fuera_de_servicio', 'id_parqueo' => $libre,
    ])->assertSessionHasNoErrors();
    $bus = Bus::where('placa', 'C998BTA')->firstOrFail();

    $alta = BitacoraAuditoria::where('id_registro_afectado', $bus->id_bus)->where('accion', 'INSERT')->firstOrFail();
    expect($alta->datos_nuevos['placa'])->toBe('C998BTA')->and($alta->datos_nuevos['parqueo'])->not->toBeNull();

    $this->actingAs($admin)->delete(route('admin.buses.destroy', $bus))->assertRedirect();
    $baja = BitacoraAuditoria::where('id_registro_afectado', $bus->id_bus)->where('accion', 'DELETE')->firstOrFail();
    expect($baja->datos_previos['placa'])->toBe('C998BTA')->and($baja->datos_nuevos)->toBeNull();

    // El bus ya no existe, pero la bitácora lo sigue mostrando por su placa.
    $this->actingAs($admin)->get(route('admin.bitacora.index', ['accion' => 'DELETE']))->assertOk()->assertSee('C998BTA')->assertSee('Eliminación');
});

test('cambiar el estado de una línea y de un molinete queda en la bitácora', function () {
    $admin = $this->administrador();
    $linea = Linea::where('codigo_linea', 'L1')->firstOrFail();
    $this->actingAs($admin)->patch(route('admin.lineas.estado', $linea), ['estado' => 'mantenimiento'])->assertSessionHasNoErrors();
    expect(ultimoRegistro('lineas')->datos_nuevos)->toEqual(['estado' => 'mantenimiento', '_registro' => 'L1']);

    $estacion = Estacion::where('nombre', 'San Sebastián')->firstOrFail();
    $acceso = $estacion->accesos()->firstOrFail();
    $this->actingAs($admin)->patch(route('admin.estaciones.accesos.estado', [$estacion, $acceso]), ['estado_operativo' => 'mantenimiento'])->assertSessionHasNoErrors();
    $r = ultimoRegistro('accesos');
    expect($r->datos_previos)->toEqual(['estado_operativo' => 'activo'])->and($r->datos_nuevos)->toEqual(['estado_operativo' => 'mantenimiento', '_registro' => 'San Sebastián · '.$acceso->ubicacion_fisica]);
});

test('los datos personales del empleado no se copian a la bitácora (RNF-12)', function () {
    $admin = $this->administrador();
    $this->actingAs($admin)->post(route('admin.personal.store'), empleadoParaBitacora())->assertSessionHasNoErrors();
    $empleado = Empleado::where('dpi', '5566778899001')->firstOrFail();
    $alta = ultimoRegistro('empleados');
    expect($alta->accion)->toBe('INSERT')->and($alta->datos_nuevos['nombre_completo'])->toBe('Bitácora Prueba Pérez');

    $this->actingAs($admin)->put(route('admin.personal.update', $empleado), empleadoParaBitacora(['telefono' => '4444-1212']))->assertSessionHasNoErrors();
    $cambio = ultimoRegistro('empleados');
    expect($cambio->accion)->toBe('UPDATE')->and($cambio->datos_nuevos)->toEqual(['datos_personales' => 'actualizados', '_registro' => 'Bitácora Prueba Pérez']);

    // Ningún registro de la bitácora contiene el DPI, el teléfono, el correo ni la dirección.
    $texto = BitacoraAuditoria::where('id_registro_afectado', $empleado->id_empleado)->get()->toJson();
    expect($texto)->not->toContain('5566778899001')->not->toContain('4444-1212')->not->toContain('bitacora@correo.gt')->not->toContain('Zona 5');

    $this->actingAs($admin)->delete(route('admin.personal.destroy', $empleado))->assertRedirect();
    expect(ultimoRegistro('empleados')->accion)->toBe('DELETE');
});

test('las acciones del operador aparecen con su estación', function () {
    $tarjeta = Tarjeta::where('estado', 'activa')->firstOrFail();
    $this->actingAs($this->administrador())->patch(route('admin.tarjetas.bloquear', $tarjeta), ['motivo' => 'robo_extravio'])->assertSessionHasNoErrors();
    $estacion = Estacion::where('nombre', 'San Sebastián')->firstOrFail();
    $acceso = $estacion->accesos()->firstOrFail();
    $this->actingAs($this->operador())->patch(route('operador.accesos.estado', $acceso), ['estado_operativo' => 'mantenimiento'])->assertSessionHasNoErrors();

    $this->actingAs($this->administrador())->get(route('admin.bitacora.index'))
        ->assertOk()->assertSee('Operador · San Sebastián')->assertSee($tarjeta->numeroFormateado());
});

test('los filtros de la bitácora: módulo, acción, usuario, fechas y búsqueda', function () {
    $admin = $this->administrador();
    $linea = Linea::where('codigo_linea', 'L2')->firstOrFail();
    $this->actingAs($admin)->patch(route('admin.lineas.estado', $linea), ['estado' => 'mantenimiento']);
    $hoy = now()->toDateString();

    $this->actingAs($admin)->get(route('admin.bitacora.index', ['modulo' => 'lineas', 'accion' => 'UPDATE', 'usuario' => $admin->id, 'desde' => $hoy, 'hasta' => $hoy]))
        ->assertOk()->assertSee('Línea L2')->assertDontSee('No hay operaciones registradas');
    $this->actingAs($admin)->get(route('admin.bitacora.index', ['buscar' => 'mantenimiento', 'modulo' => 'lineas']))->assertOk()->assertSee('Línea L2');
    $this->actingAs($admin)->get(route('admin.bitacora.index', ['modulo' => 'lineas', 'desde' => now()->addDay()->toDateString()]))
        ->assertOk()->assertSee('No hay operaciones registradas');
    // Fechas inválidas o texto con SQL no rompen la consulta.
    $this->actingAs($admin)->get(route('admin.bitacora.index', ['desde' => 'ayer', 'buscar' => "x' OR 1=1; DROP TABLE buses; --"]))
        ->assertOk()->assertSee('No hay operaciones registradas');
    expect(DB::table('buses')->exists())->toBeTrue();
});

test('la bitácora no se puede modificar ni borrar (trigger trg_bitacora_inmutable)', function () {
    $tarjeta = Tarjeta::where('estado', 'activa')->firstOrFail();
    $this->actingAs($this->administrador())->patch(route('admin.tarjetas.bloquear', $tarjeta), ['motivo' => 'robo_extravio']);
    $r = ultimoRegistro('tarjetas');

    expect(fn () => DB::transaction(fn () => DB::table('bitacora_auditoria')->where('id_bitacora', $r->id_bitacora)->update(['accion' => 'DELETE'])))
        ->toThrow(QueryException::class, 'no se puede modificar');
    expect(fn () => DB::transaction(fn () => DB::table('bitacora_auditoria')->where('id_bitacora', $r->id_bitacora)->delete()))
        ->toThrow(QueryException::class, 'no se puede eliminar');
    expect(BitacoraAuditoria::find($r->id_bitacora)->accion)->toBe('UPDATE');
});

test('al eliminar un usuario sus registros se conservan sin el usuario', function () {
    $tarjeta = Tarjeta::where('estado', 'activa')->firstOrFail();
    $admin = User::create(['name' => 'Admin temporal', 'email' => 'temporal@transmetro.test', 'password' => 'Temporal2026!', 'rol' => 'administrador']);
    $this->actingAs($admin)->patch(route('admin.tarjetas.bloquear', $tarjeta), ['motivo' => 'robo_extravio'])->assertSessionHasNoErrors();
    $r = ultimoRegistro('tarjetas');

    $admin->delete();
    expect(BitacoraAuditoria::find($r->id_bitacora))->not->toBeNull()
        ->and(BitacoraAuditoria::find($r->id_bitacora)->id_usuario)->toBeNull();
});

test('la falla que reporta el operador queda con la placa del bus y los motivos se leen en español', function () {
    $bus = $this->busDelOperador();
    $this->actingAs($this->operador())->post(route('operador.fallas'), [
        'id_bus_falla' => $bus->id_bus, 'categoria' => 'frenos', 'prioridad' => 'media', 'descripcion' => 'Ruido al frenar',
    ])->assertSessionHasNoErrors();

    $registro = ultimoRegistro('mantenimientos_bus');
    expect($registro->datos_nuevos)->toHaveKey('bus', $bus->placa)
        ->and($registro->datos_nuevos)->not->toHaveKey('id_bus_falla');

    BitacoraAuditoria::create(['tabla_afectada' => 'bajadas_bus', 'accion' => 'INSERT', 'datos_nuevos' => ['motivo' => 'contador_fallo']]);
    $this->actingAs($this->administrador())->get(route('admin.bitacora.index'))
        ->assertOk()->assertSee('Falló el contador de salida')->assertDontSee($bus->id_bus);
});

test('buscar la placa muestra todos los cambios del bus, incluso solo de capacidad', function () {
    $bus = Bus::firstOrFail();
    Auditoria::cambio('buses', $bus->id_bus, ['placa' => $bus->placa, 'capacidad_maxima' => 90],
        ['placa' => $bus->placa, 'capacidad_maxima' => 101], $this->administrador());

    $registro = ultimoRegistro('buses');
    expect($registro->datos_nuevos)->toEqual(['capacidad_maxima' => 101, '_registro' => $bus->placa]);

    $this->actingAs($this->administrador())->get(route('admin.bitacora.index', ['buscar' => $bus->placa]))
        ->assertOk()->assertSee($bus->placa)->assertSee('101')->assertDontSee('_registro');
});
