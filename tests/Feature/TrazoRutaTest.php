<?php

use App\Http\Controllers\MapaController;
use App\Models\BitacoraAuditoria;
use App\Models\Linea;
use App\Services\ExportadorTrazos;
use Database\Seeders\Datos\TransmetroDatos;

/*
 * Trazo de las rutas por las calles (editor del mapa del administrador).
 */

beforeEach(function () {
    $this->linea = Linea::where('codigo_linea', 'L2')->firstOrFail();
    $this->trazo = [[14.6458, -90.5135], [14.6458, -90.5127], [14.6533, -90.5127], [14.6568, -90.5116]];
});

test('el administrador abre el editor del trazo', function () {
    $this->actingAs($this->administrador())->get(route('admin.lineas.trazo.edit', $this->linea))
        ->assertOk()
        ->assertSee('Trazar ruta')
        ->assertSee('Hipódromo del Norte');
});

test('un operador no puede entrar al editor del trazo', function () {
    $this->actingAs($this->operador())->get(route('admin.lineas.trazo.edit', $this->linea))->assertForbidden();
});

test('se guarda el trazo, se mueve una estación y queda en bitácora', function () {
    $estacion = $this->linea->estaciones()->where('nombre', 'Jocotenango')->firstOrFail();

    $this->actingAs($this->administrador())
        ->put(route('admin.lineas.trazo.update', $this->linea), [
            'trazo' => json_encode($this->trazo),
            'estaciones' => json_encode([$estacion->id_estacion => [14.652345, -90.513456]]),
        ])
        ->assertRedirect(route('admin.lineas.show', $this->linea))
        ->assertSessionHas('estado');

    $this->linea->refresh();
    $estacion->refresh();

    expect($this->linea->trazo)->toBe($this->trazo)
        ->and($this->linea->tieneTrazo())->toBeTrue()
        ->and((float) $estacion->latitud)->toBe(14.652345)
        ->and((float) $estacion->longitud)->toBe(-90.513456)
        ->and(BitacoraAuditoria::where('id_registro_afectado', $this->linea->id_linea)->where('tabla_afectada', 'lineas')->exists())->toBeTrue();
});

test('el mapa usa el trazo guardado', function () {
    $this->linea->update(['trazo' => $this->trazo]);

    $datos = collect(MapaController::datos(Linea::with('estaciones')->get()))->firstWhere('codigo', 'L2');

    expect($datos['trazo'])->toBe($this->trazo);
});

test('un trazo vacío borra el trazo de la línea', function () {
    $this->linea->update(['trazo' => $this->trazo]);

    $this->actingAs($this->administrador())
        ->put(route('admin.lineas.trazo.update', $this->linea), ['trazo' => '[]'])
        ->assertRedirect();

    expect($this->linea->refresh()->trazo)->toBeNull();
});

test('se rechazan puntos fuera de Guatemala o mal formados', function (string $trazo) {
    $antes = $this->linea->trazo;

    $this->actingAs($this->administrador())
        ->put(route('admin.lineas.trazo.update', $this->linea), ['trazo' => $trazo])
        ->assertSessionHasErrors('trazo');

    expect($this->linea->refresh()->trazo)->toBe($antes);
})->with([
    'fuera del país' => ['[[40.4, -3.7], [40.5, -3.6]]'],
    'un solo punto' => ['[[14.64, -90.51]]'],
    'texto' => ['[["a", "b"], [14.64, -90.51]]'],
    'no es lista' => ['{"x": 1}'],
]);

test('no se pueden mover estaciones de otra línea', function () {
    $ajena = Linea::where('codigo_linea', 'L1')->firstOrFail()->estaciones()->where('nombre', 'Correos')->firstOrFail();

    $this->actingAs($this->administrador())
        ->put(route('admin.lineas.trazo.update', $this->linea), [
            'trazo' => json_encode($this->trazo),
            'estaciones' => json_encode([$ajena->id_estacion => [14.64, -90.51]]),
        ])
        ->assertSessionHasErrors('estaciones');
});

test('los trazos se exportan a un archivo y los datos de prueba los conservan', function () {
    $this->linea->update(['trazo' => $this->trazo]);
    $archivo = tempnam(sys_get_temp_dir(), 'trazos');

    app(ExportadorTrazos::class)->exportar($archivo);
    $exportado = json_decode(file_get_contents($archivo), true);
    unlink($archivo);

    expect($exportado['L2']['trazo'])->toBe($this->trazo)
        ->and($exportado['L2']['estaciones'])->toHaveKey('San Sebastián');

    // El generador de datos de prueba usa ese trazo y esas ubicaciones.
    $exportado['L2']['estaciones']['Jocotenango'] = [14.6511, -90.5144];
    $datos = TransmetroDatos::generar(fn ($v) => $v, fn ($v) => $v, 2026, $exportado);
    $l2 = collect($datos['tablas']['lineas'])->firstWhere('codigo_linea', 'L2');
    $jocotenango = collect($datos['tablas']['estaciones'])->firstWhere('nombre', 'Jocotenango');

    expect(json_decode($l2['trazo'], true))->toBe($this->trazo)
        ->and([$jocotenango['latitud'], $jocotenango['longitud']])->toBe([14.6511, -90.5144]);
});
