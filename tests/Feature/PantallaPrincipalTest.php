<?php

use App\Models\AlertaSistema;
use App\Models\Bus;
use App\Models\Linea;
use App\Services\PanelLinea;
use Illuminate\Support\Facades\DB;

/*
 * Pantalla principal del administrador: mapa plano con las rutas y panel deslizable por ruta.
 */

function lineaL1(): Linea
{
    return Linea::where('codigo_linea', 'L1')->firstOrFail();
}

test('la pantalla principal muestra el mapa con todas las rutas para elegir', function () {
    $respuesta = $this->actingAs($this->administrador())->get('/admin')->assertOk()
        ->assertSee('Red de rutas')
        ->assertSee('id="panel-linea"', false);

    Linea::all()->each(fn ($l) => $respuesta->assertSee('data-linea="'.$l->codigo_linea.'"', false));
});

test('el panel de una ruta trae estaciones en orden, cada bus con su chofer, y el operador y la seguridad de cada estación', function () {
    $linea = lineaL1();
    $estaciones = $linea->estaciones()->pluck('nombre')->all();
    $bus = Bus::where('id_linea_asignada', $linea->id_linea)->whereHas('piloto')->with('piloto.empleado')->firstOrFail();
    $operador = $this->operador()->empleado;
    $guardia = DB::table('seguridad')->join('accesos', 'accesos.id_acceso', '=', 'seguridad.id_acceso_asignado')
        ->join('empleados', 'empleados.id_empleado', '=', 'seguridad.id_empleado')
        ->where('accesos.id_estacion', $this->estacionDelOperador()->id_estacion)
        ->value('empleados.nombre_completo');

    $this->actingAs($this->administrador())->get(route('admin.lineas.panel', $linea))->assertOk()
        ->assertSee('data-panel="L1"', false)
        ->assertSeeInOrder($estaciones)
        ->assertSee('Chofer de cada bus')
        ->assertSee('Cada estación')
        ->assertSeeInOrder(['Cada estación', $this->estacionDelOperador()->nombre, $operador->nombre_completo])
        ->assertSee($guardia)
        ->assertSee($bus->placa)
        ->assertSee($bus->piloto->empleado->nombre_completo)
        ->assertSee('Permitido '.count($estaciones).'–'.(2 * count($estaciones)));
});

test('el panel cuenta los pasajeros de hoy de la ruta', function () {
    $usuario = $this->operador();
    $estacion = $this->estacionDelOperador();
    $linea = lineaL1();
    $antes = app(PanelLinea::class)->datos($linea)['pasajeros']['hoy'];

    $bus = $this->busDelOperador();
    $this->busEnEstacion($bus, $estacion);

    $this->actingAs($usuario)->post('/operador/pases', [
        'id_bus' => $bus->id_bus,
        'metodo_pago' => 'tarjeta_bancaria',
        'motivo' => 'sistema_caido',
    ])->assertSessionHas('estado');

    $datos = app(PanelLinea::class)->datos($linea->fresh());
    expect($datos['pasajeros']['hoy'])->toBe($antes + 1)
        ->and($datos['pasajeros']['recaudadoHoy'])->toBeGreaterThan(0)
        ->and($datos['pasajeros']['semana'])->toHaveCount(7)
        ->and($datos['pasajeros']['semana']->last()['pases'])->toBe($antes + 1);
});

test('las alertas abiertas de la ruta aparecen en su panel', function () {
    $linea = lineaL1();
    $estacion = $linea->estaciones()->firstOrFail();
    AlertaSistema::create([
        'id_estacion' => $estacion->id_estacion,
        'tipo_alerta' => 'refuerzo_bus',
        'descripcion' => 'Estación al 160 % de su aforo',
        'estado' => 'abierta',
        'fecha_creacion' => now(),
    ]);

    $this->actingAs($this->administrador())->get(route('admin.lineas.panel', $linea))->assertOk()
        ->assertSee('Estación al 160 % de su aforo')
        ->assertSee('Otro bus pedido');
});

test('el panel se arma con pocas consultas a la base', function () {
    $linea = lineaL1();
    DB::enableQueryLog();
    app(PanelLinea::class)->datos($linea);

    expect(count(DB::getQueryLog()))->toBe(1);
});

test('el operador no puede ver el panel de las rutas', function () {
    $this->actingAs($this->operador())->get(route('admin.lineas.panel', lineaL1()))->assertForbidden();
});
