<?php

use App\Models\AlertaSistema;
use App\Models\Bus;
use App\Models\Empleado;
use App\Models\Estacion;
use App\Models\Linea;
use App\Models\Municipio;
use App\Models\Parqueo;
use App\Models\Tarjeta;
use App\Models\Viaje;
use Illuminate\Support\Facades\DB;

/*
 * Lista de verificación: cada botón que guarda, cambia o borra algo tiene aquí su prueba.
 * Líneas, estaciones y municipios son los oficiales del Transmetro: solo se consulta y se cambia su estado.
 * (Completa las demás pruebas, que ya cubren reportes, pases, simulación y reglas de la BD.)
 */

// ---------- Navegación ----------

test('el mapa enlaza a los catálogos de líneas, estaciones y municipios', function () {
    $this->actingAs($this->administrador())->get(route('admin.inicio'))->assertOk()
        ->assertSee(route('admin.lineas.index'), false)
        ->assertSee(route('admin.estaciones.index'), false)
        ->assertSee(route('admin.municipios.index'), false);
});

// ---------- Buses ----------

test('editar un bus guarda los cambios', function () {
    $bus = Bus::firstOrFail();
    $this->actingAs($this->administrador())->put(route('admin.buses.update', $bus), [
        'placa' => $bus->placa, 'tipo_unidad' => $bus->tipo_unidad, 'capacidad_maxima' => 95, 'id_linea_asignada' => $bus->id_linea_asignada,
        'estado_logico' => $bus->estado_logico, 'ocupacion_actual_pct' => 33,
    ])->assertSessionHasNoErrors()->assertRedirect();

    expect($bus->fresh()->capacidad_maxima)->toBe(95);
});

test('cambiar el parqueo de un bus usa el procedimiento almacenado', function () {
    $bus = Bus::whereIn('id_bus', DB::table('parqueos')->whereNotNull('id_bus_asignado')->select('id_bus_asignado'))->firstOrFail();
    $libre = DB::table('parqueos')->whereNull('id_bus_asignado')->value('id_parqueo');

    $this->actingAs($this->administrador())->put(route('admin.buses.parqueo', $bus), ['id_parqueo_nuevo' => $libre])
        ->assertSessionHasNoErrors()->assertRedirect();

    expect(DB::table('parqueos')->where('id_parqueo', $libre)->value('id_bus_asignado'))->toBe($bus->id_bus);
});

test('eliminar un bus lo quita y libera su parqueo', function () {
    $bus = Bus::whereNull('id_linea_asignada')->first() ?? Bus::firstOrFail();
    $this->actingAs($this->administrador())->delete(route('admin.buses.destroy', $bus))->assertRedirect();

    expect(Bus::find($bus->id_bus))->toBeNull()
        ->and(DB::table('parqueos')->where('id_bus_asignado', $bus->id_bus)->exists())->toBeFalse();
});

// ---------- Líneas, estaciones y municipios: los oficiales, solo consulta ----------

test('líneas, estaciones y municipios no se pueden crear, editar ni eliminar', function () {
    $admin = $this->administrador();
    $linea = Linea::firstOrFail();
    $estacion = Estacion::firstOrFail();
    $municipio = Municipio::firstOrFail();

    foreach (['lineas', 'estaciones', 'municipios'] as $catalogo) {
        $this->actingAs($admin)->get("/admin/{$catalogo}/create")->assertNotFound();
        $this->actingAs($admin)->post("/admin/{$catalogo}", [])->assertMethodNotAllowed();
    }
    $this->actingAs($admin)->get("/admin/lineas/{$linea->id_linea}/edit")->assertNotFound();
    $this->actingAs($admin)->get("/admin/lineas/{$linea->id_linea}/trazo")->assertNotFound();
    $this->actingAs($admin)->delete("/admin/lineas/{$linea->id_linea}")->assertMethodNotAllowed();
    $this->actingAs($admin)->put("/admin/estaciones/{$estacion->id_estacion}", [])->assertMethodNotAllowed();
    $this->actingAs($admin)->delete("/admin/municipios/{$municipio->id_municipio}")->assertNotFound();

    expect(Linea::find($linea->id_linea))->not->toBeNull()
        ->and(Estacion::find($estacion->id_estacion))->not->toBeNull()
        ->and(Municipio::find($municipio->id_municipio))->not->toBeNull();
});

test('las pantallas de consulta no muestran botones para crear, editar ni eliminar', function () {
    $admin = $this->administrador();
    $linea = Linea::where('codigo_linea', 'L1')->firstOrFail();
    $estacion = Estacion::where('nombre', 'San Sebastián')->firstOrFail();

    $this->actingAs($admin)->get(route('admin.inicio'))->assertOk()->assertDontSee('Nueva ruta');
    $this->actingAs($admin)->get(route('admin.lineas.index'))->assertOk()->assertDontSee('Nueva línea');
    $this->actingAs($admin)->get(route('admin.estaciones.index'))->assertOk()->assertDontSee('Nueva estación');
    $this->actingAs($admin)->get(route('admin.municipios.index'))->assertOk()->assertDontSee('Nuevo municipio');
    $this->actingAs($admin)->get(route('admin.lineas.show', $linea))->assertOk()
        ->assertSee('Cambiar estado')->assertDontSee('Eliminar línea')->assertDontSee('Guardar horario')->assertDontSee('+ Agregar');
    $this->actingAs($admin)->get(route('admin.estaciones.show', $estacion))->assertOk()
        ->assertSee('Estado del molinete')->assertDontSee('Agregar acceso')->assertDontSee('Quitar acceso')->assertDontSee('Agregar parqueo');
    $this->actingAs($admin)->get(route('admin.lineas.panel', $linea))->assertOk()
        ->assertSee('Estado de la línea')->assertDontSee('Editar línea')->assertDontSee('Trazo de la ruta');
});

test('cambiar el estado de una línea (mantenimiento, inactiva y otra vez activa)', function () {
    $admin = $this->administrador();
    $linea = Linea::where('codigo_linea', 'L1')->firstOrFail();

    $this->actingAs($admin)->patch(route('admin.lineas.estado', $linea), ['estado' => 'mantenimiento'])
        ->assertSessionHasNoErrors()->assertSessionHas('estado');
    expect($linea->fresh()->estado)->toBe('mantenimiento');

    $this->actingAs($admin)->patch(route('admin.lineas.estado', $linea), ['estado' => 'inactiva'])->assertSessionHasNoErrors();
    expect($linea->fresh()->estado)->toBe('inactiva');

    $this->actingAs($admin)->patch(route('admin.lineas.estado', $linea), ['estado' => 'activa'])->assertSessionHasNoErrors();
    expect($linea->fresh()->estado)->toBe('activa');

    $this->actingAs($admin)->patch(route('admin.lineas.estado', $linea), ['estado' => 'borrada'])->assertSessionHasErrors('estado');
});

test('una línea no se activa si no tiene entre n y 2n buses (punto 5)', function () {
    $linea = Linea::where('codigo_linea', 'L1')->firstOrFail();
    $linea->update(['estado' => 'inactiva']);
    DB::table('buses')->where('id_linea_asignada', $linea->id_linea)->update(['id_linea_asignada' => null]);

    $this->actingAs($this->administrador())->patch(route('admin.lineas.estado', $linea), ['estado' => 'activa'])
        ->assertSessionHasErrors('estado');
    expect($linea->fresh()->estado)->toBe('inactiva');
});

test('cambiar el estado del molinete de un acceso', function () {
    $admin = $this->administrador();
    $estacion = Estacion::where('nombre', 'San Sebastián')->firstOrFail();
    $acceso = $estacion->accesos()->firstOrFail();

    $this->actingAs($admin)->patch(route('admin.estaciones.accesos.estado', [$estacion, $acceso]), ['estado_operativo' => 'mantenimiento'])
        ->assertSessionHasNoErrors()->assertSessionHas('estado');
    expect($acceso->molinete()->value('estado_operativo'))->toBe('mantenimiento');

    $this->actingAs($admin)->patch(route('admin.estaciones.accesos.estado', [$estacion, $acceso]), ['estado_operativo' => 'activo'])
        ->assertSessionHasNoErrors();
    expect($acceso->molinete()->value('estado_operativo'))->toBe('activo');

    $this->actingAs($admin)->patch(route('admin.estaciones.accesos.estado', [$estacion, $acceso]), ['estado_operativo' => 'roto'])
        ->assertSessionHasErrors('estado_operativo');

    $otra = Estacion::where('id_estacion', '!=', $estacion->id_estacion)->firstOrFail();
    $this->actingAs($admin)->patch(route('admin.estaciones.accesos.estado', [$otra, $acceso]), ['estado_operativo' => 'inactivo'])
        ->assertNotFound();
});

test('el operador no puede cambiar estados de líneas ni de accesos', function () {
    $linea = Linea::firstOrFail();
    $estacion = Estacion::where('nombre', 'San Sebastián')->firstOrFail();
    $acceso = $estacion->accesos()->firstOrFail();

    $this->actingAs($this->operador())->patch(route('admin.lineas.estado', $linea), ['estado' => 'inactiva'])->assertForbidden();
    $this->actingAs($this->operador())->patch(route('admin.estaciones.accesos.estado', [$estacion, $acceso]), ['estado_operativo' => 'inactivo'])->assertForbidden();
});

// ---------- Personal ----------

test('editar y eliminar un empleado', function () {
    $admin = $this->administrador();
    $this->actingAs($admin)->post(route('admin.personal.store'), datosPiloto(['dpi' => '9988776655443', 'numero_licencia' => 'LIC-Z']))->assertSessionHasNoErrors();
    $empleado = Empleado::where('dpi', '9988776655443')->firstOrFail();

    $this->actingAs($admin)->put(route('admin.personal.update', $empleado), datosPiloto(['dpi' => '9988776655443', 'numero_licencia' => 'LIC-Z', 'telefono' => '4444-0000']))
        ->assertSessionHasNoErrors();
    expect($empleado->fresh()->telefono())->toBe('4444-0000');

    $this->actingAs($admin)->delete(route('admin.personal.destroy', $empleado))->assertRedirect();
    expect(Empleado::find($empleado->id_empleado))->toBeNull();
});

// ---------- Tarjetas ----------

test('bloquear y desbloquear una tarjeta conserva su saldo', function () {
    $admin = $this->administrador();
    $tarjeta = Tarjeta::where('estado', 'activa')->firstOrFail();
    $saldo = (float) $tarjeta->saldo;

    $this->actingAs($admin)->patch(route('admin.tarjetas.bloquear', $tarjeta), ['motivo' => 'robo_extravio'])->assertSessionHasNoErrors();
    expect($tarjeta->fresh()->estado)->toBe('bloqueada');

    $this->actingAs($admin)->patch(route('admin.tarjetas.desbloquear', $tarjeta))->assertSessionHasNoErrors();
    expect($tarjeta->fresh()->estado)->toBe('activa')->and((float) $tarjeta->fresh()->saldo)->toBe($saldo);
});

// ---------- Simulación y operador ----------

test('reiniciar aforos deja cada estación entre el 20 % y el 50 % de su aforo', function () {
    DB::table('estaciones')->update(['aforo_actual' => DB::raw('aforo_maximo')]);
    $this->actingAs($this->administrador())->post(route('admin.simulacion.reiniciar'))->assertRedirect()->assertSessionHas('estado');
    expect(DB::table('estaciones')->whereRaw('aforo_actual > ceil(aforo_maximo * 0.5) OR aforo_actual < floor(aforo_maximo * 0.2)')->count())->toBe(0);
});

test('el operador atiende una alerta de su estación, pero no la de otra estación', function () {
    $operador = $this->operador();
    $suya = $this->estacionDelOperador();
    $otra = Estacion::where('id_estacion', '!=', $suya->id_estacion)->firstOrFail();
    $alerta = AlertaSistema::create(['id_estacion' => $suya->id_estacion, 'tipo_alerta' => 'otro', 'descripcion' => 'Prueba', 'estado' => 'abierta', 'fecha_creacion' => now()]);
    $ajena = AlertaSistema::create(['id_estacion' => $otra->id_estacion, 'tipo_alerta' => 'otro', 'descripcion' => 'Prueba', 'estado' => 'abierta', 'fecha_creacion' => now()]);

    $this->actingAs($operador)->post(route('operador.alertas.atender', $alerta))->assertRedirect()->assertSessionHas('estado');
    expect($alerta->fresh()->estado)->toBe('atendida');

    $this->actingAs($operador)->post(route('operador.alertas.atender', $ajena))->assertForbidden();
    $this->actingAs($operador)->post(route('operador.alertas.atender', $alerta))->assertSessionHas('estado', 'Esa alerta ya fue atendida.');
});

// ---------- Buses: en qué estación va cada uno ----------

test('el listado de buses muestra en qué estación va cada bus y cuál es la próxima de su ruta', function () {
    $bus = $this->busDelOperador();
    $recorrido = $bus->linea->estaciones()->get()->values();
    Viaje::where('id_bus', $bus->id_bus)->delete();
    $viaje = Viaje::create(['id_bus' => $bus->id_bus, 'id_linea' => $bus->id_linea_asignada, 'fecha_hora_inicio' => now(), 'estado' => 'en_curso']);
    foreach ($recorrido->take(2) as $k => $e) {
        $viaje->visitas()->create(['id_estacion' => $e->id_estacion, 'orden_visita' => $k + 1, 'hora_llegada' => now(), 'hora_salida' => now(), 'ocupacion_al_llegar_pct' => 50]);
    }

    $this->actingAs($this->administrador())->get(route('admin.buses.index'))->assertOk()
        ->assertSeeInOrder([$this->busDelOperador()->placa, 'En', $recorrido[1]->nombre, 'Próxima', $recorrido[2]->nombre]);
});

test('un bus sin viaje en curso lo indica en el listado', function () {
    $bus = $this->busDelOperador();
    Viaje::where('id_bus', $bus->id_bus)->delete();

    $this->actingAs($this->administrador())->get(route('admin.buses.index'))->assertOk()
        ->assertSeeInOrder([$this->busDelOperador()->placa, 'Sin viaje en curso']);
});

// ---------- Placas de Guatemala ----------

test('los buses tienen placa de Guatemala (como C387JGL) y el formulario valida el formato', function () {
    expect(Bus::pluck('placa')->every(fn ($p) => preg_match('/^C\d{3}[A-Z]{3}$/', $p) === 1))->toBeTrue();

    $parqueo = Parqueo::whereNull('id_bus_asignado')->firstOrFail();
    $admin = $this->administrador();
    $this->actingAs($admin)->post(route('admin.buses.store'), ['placa' => 'TM-999', 'tipo_unidad' => 'articulado', 'capacidad_maxima' => 160,
        'estado_logico' => 'fuera_de_servicio', 'id_parqueo' => $parqueo->id_parqueo])->assertSessionHasErrors('placa');
    $this->actingAs($admin)->post(route('admin.buses.store'), ['placa' => 'c-387 jgl', 'tipo_unidad' => 'articulado', 'capacidad_maxima' => 160,
        'estado_logico' => 'fuera_de_servicio', 'id_parqueo' => $parqueo->id_parqueo])->assertSessionHasNoErrors();

    expect(Bus::where('placa', 'C387JGL')->exists())->toBeTrue()
        ->and(errorDeBD(fn () => Bus::create(['placa' => 'TM-998', 'capacidad_maxima' => 80, 'estado_logico' => 'fuera_de_servicio'])))
        ->toContain('chk_buses_placa');
});

// ---------- Buses: tipo de unidad y capacidad según la línea ----------

test('cada línea opera con el tipo de unidad que le corresponde y su capacidad', function () {
    $esperado = [
        'L1' => ['convencional'], 'L2' => ['convencional'], 'L6' => ['articulado'], 'L7' => ['articulado'],
        'L12' => ['articulado', 'biarticulado'], 'L13' => ['articulado'], 'L18' => ['articulado'], 'R5' => ['electrico'],
    ];
    $buses = Bus::with('linea')->whereNotNull('id_linea_asignada')->get();

    foreach ($esperado as $codigo => $tipos) {
        $deLinea = $buses->filter(fn ($b) => $b->linea->codigo_linea === $codigo);
        expect($deLinea)->not->toBeEmpty()
            ->and($deLinea->pluck('tipo_unidad')->unique()->sort()->values()->all())->toBe($tipos);
    }
    $buses->each(fn ($b) => expect($b->capacidad_maxima)->toBe(Bus::TIPOS_UNIDAD[$b->tipo_unidad][1]));
    expect(Bus::TIPOS_UNIDAD)->toMatchArray([
        'biarticulado' => ['Biarticulado', 270], 'articulado' => ['Articulado', 160],
        'convencional' => ['Convencional', 119], 'electrico' => ['Eléctrico TuBus', 80],
    ]);
});

test('el formulario de bus exige un tipo de unidad válido y lo muestra en la ficha del bus', function () {
    $bus = $this->busDelOperador();
    $this->actingAs($this->administrador())->put(route('admin.buses.update', $bus), [
        'placa' => $bus->placa, 'tipo_unidad' => 'tren', 'capacidad_maxima' => 160, 'estado_logico' => $bus->estado_logico,
    ])->assertSessionHasErrors('tipo_unidad');
    expect(errorDeBD(fn () => $bus->update(['tipo_unidad' => 'tren'])))->toContain('chk_buses_tipo_unidad');
    $bus->refresh();

    $this->actingAs($this->administrador())->get(route('admin.buses.create'))->assertOk()
        ->assertSee('Tipo de unidad')->assertSee('Biarticulado · 270 pasajeros');
    $this->actingAs($this->administrador())->get(route('admin.buses.edit', $bus))->assertOk()
        ->assertSee($bus->tipoUnidad().' · '.$bus->capacidad_maxima.' pasajeros');
});

// ---------- Buses: buscador por placa y unidades sin piloto ----------

test('el buscador de buses filtra por placa (con guion, espacios o minúsculas)', function () {
    $bus = $this->busDelOperador();
    $otra = Bus::where('id_bus', '!=', $bus->id_bus)->orderBy('placa')->firstOrFail();
    $escrita = strtolower(substr($bus->placa, 0, 4).'-'.substr($bus->placa, 4));

    $this->actingAs($this->administrador())->get(route('admin.buses.index', ['placa' => $escrita]))->assertOk()
        ->assertSee($bus->placa)->assertDontSee($otra->placa);
});

test('el apartado "Sin piloto asignado" muestra solo las unidades sin piloto y explica qué hacer', function () {
    $sinPiloto = Bus::whereDoesntHave('piloto')->pluck('placa');
    $conPiloto = Bus::whereHas('piloto')->orderBy('placa')->value('placa');

    $respuesta = $this->actingAs($this->administrador())->get(route('admin.buses.index', ['piloto' => 'sin']))->assertOk()
        ->assertSee('No pueden ponerse en ruta')->assertDontSee($conPiloto);
    $sinPiloto->each(fn ($placa) => $respuesta->assertSee($placa));
});

test('una unidad sin piloto no puede ponerse en ruta', function () {
    $bus = Bus::whereDoesntHave('piloto')->firstOrFail();

    $this->actingAs($this->administrador())->put(route('admin.buses.update', $bus), [
        'placa' => $bus->placa, 'tipo_unidad' => $bus->tipo_unidad, 'capacidad_maxima' => $bus->capacidad_maxima, 'estado_logico' => 'en_ruta',
    ])->assertSessionHasErrors('estado_logico');

    expect($bus->refresh()->estado_logico)->not->toBe('en_ruta')
        ->and(errorDeBD(fn () => $bus->update(['estado_logico' => 'en_ruta'])))->toContain('no tiene piloto asignado');
});

test('si el piloto deja su bus mientras va en ruta, el bus termina su viaje y queda en espera', function () {
    $bus = $this->busDelOperador();
    $this->busEnEstacion($bus, null, 5);

    $bus->piloto->update(['id_bus_asignado' => null]);

    expect($bus->refresh()->estado_logico)->toBe('en_espera')
        ->and($bus->pasajeros_a_bordo)->toBe(0)
        ->and(Viaje::where('id_bus', $bus->id_bus)->where('estado', 'en_curso')->exists())->toBeFalse();
});
