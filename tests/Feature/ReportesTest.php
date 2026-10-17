<?php

use App\Models\Linea;
use App\Models\VisitaViaje;
use App\Services\Reportes;

/*
 * Punto 15: reportes de estaciones, líneas y buses asignados a ellas. Las consultas SQL van en el documento (docs/Consultas_Reportes.sql).
 */

test('el administrador ve la lista de reportes', function () {
    $this->actingAs($this->administrador())->get('/admin/reportes')
        ->assertOk()
        ->assertSeeInOrder(['Estaciones, líneas y buses', 'Estaciones', 'Líneas', 'Buses asignados a cada línea', 'Operación']);
});

test('cada reporte carga con sus cifras clave y su diseño para PDF, sin mostrar la consulta SQL', function (string $reporte) {
    $this->actingAs($this->administrador())->get("/admin/reportes/{$reporte}")
        ->assertOk()
        ->assertSee('Imprimir o guardar en PDF')
        ->assertSee('class="resumen', false)
        ->assertSee('@page', false)
        ->assertDontSee('Detalle técnico')
        ->assertDontSee('SELECT', false)
        ->assertDontSee('CSV');
})->with(['estaciones', 'lineas', 'buses', 'recorridos', 'visitas', 'pasajeros', 'cobros']);

test('los buses salen agrupados por línea, con el nombre de la línea y cuántos tiene', function () {
    $l1 = Linea::where('codigo_linea', 'L1')->firstOrFail();

    $this->actingAs($this->administrador())->get('/admin/reportes/buses?linea=L1')->assertOk()
        ->assertSee('class="fila-grupo"', false)
        ->assertSeeInOrder([$l1->nombre, $l1->buses()->count().' registros'])
        ->assertSee('En espera');   // los estados se muestran con palabras, no como en_espera
});

test('un reporte que no existe da 404', function () {
    $this->actingAs($this->administrador())->get('/admin/reportes/inventado')->assertNotFound();
});

test('un operador no puede ver los reportes', function () {
    $this->actingAs($this->operador())->get('/admin/reportes/lineas')->assertForbidden();
});

test('el reporte de líneas cuenta estaciones y buses y revisa la regla de n a 2n', function () {
    $filas = collect(app(Reportes::class)->ejecutar('lineas'))->keyBy('codigo');

    expect($filas->keys()->all())->toBe(['L1', 'L2', 'L6', 'L7', 'L12', 'L13', 'L18', 'R5'])
        ->and($filas['L6']['estaciones'])->toBe(16)
        ->and($filas['L6']['buses_asignados'])->toBe(Linea::where('codigo_linea', 'L6')->first()->buses()->count())
        ->and($filas->pluck('regla_buses')->unique()->all())->toBe(['Cumple']);
});

test('el reporte de estaciones se puede filtrar por línea y por municipio', function () {
    $reportes = app(Reportes::class);

    expect($reportes->ejecutar('estaciones', ['linea' => 'L2']))->toHaveCount(5)
        ->and(collect($reportes->ejecutar('estaciones', ['municipio' => 'Villa Nueva']))->pluck('estacion')->sort()->values()->all())
        ->toBe(['Centra Sur', 'Javier', 'Monte María']);

    $sanSebastian = collect($reportes->ejecutar('estaciones', ['linea' => 'L1']))->firstWhere('estacion', 'San Sebastián');
    expect($sanSebastian['lineas'])->toBe('L1, L2')
        ->and($sanSebastian['estado_aforo'])->toBe('Requiere refuerzo')
        ->and($sanSebastian['guardias'])->toBeGreaterThanOrEqual($sanSebastian['accesos']);
});

test('el reporte de buses muestra los buses de la línea y los que no tienen línea', function () {
    $reportes = app(Reportes::class);
    $todos = collect($reportes->ejecutar('buses'));

    expect($reportes->ejecutar('buses', ['linea' => 'L1']))->toHaveCount(12)
        ->and($todos->where('linea', 'Sin línea'))->toHaveCount(3)
        ->and($todos->last()['linea'])->toBe('Sin línea')
        ->and($todos->whereNotNull('parqueo'))->toHaveCount($todos->count());
});

test('el recorrido acumula la distancia total de la línea', function () {
    $filas = app(Reportes::class)->ejecutar('recorridos', ['linea' => 'L1']);
    $linea = Linea::where('codigo_linea', 'L1')->firstOrFail();

    expect($filas)->toHaveCount(10)
        ->and($filas[0]['estacion'])->toBe('San Sebastián')
        ->and((float) end($filas)['km_acumulado'])->toEqualWithDelta((float) $linea->distancia_total_km, 0.01);
});

test('el reporte de visitas muestra las estaciones que visitó cada bus', function () {
    $filas = app(Reportes::class)->ejecutar('visitas', ['linea' => 'L6']);

    expect($filas)->not->toBeEmpty()
        ->and(collect($filas)->pluck('linea')->unique()->all())->toBe(['L6']);
});

test('el resumen del reporte de estaciones suma lo de la tabla', function () {
    $filas = app(Reportes::class)->ejecutar('estaciones');
    $resumen = collect(Reportes::resumen('estaciones', $filas))->keyBy(0);

    expect($resumen['Estaciones'][1])->toBe(number_format(count($filas)))
        ->and($resumen['Personas esperando'][1])->toBe(number_format(collect($filas)->sum('aforo_actual')));
});

test('los filtros no permiten inyección SQL', function () {
    $this->actingAs($this->administrador())
        ->get('/admin/reportes/estaciones?linea='.urlencode("L1' OR '1'='1"))
        ->assertSessionHasErrors('linea');

    // Aunque el texto llegara a la consulta, va como parámetro y no como parte del SQL.
    expect(app(Reportes::class)->ejecutar('estaciones', ['linea' => "L1' OR '1'='1"]))->toBeEmpty();
});

test('docs/Consultas_Reportes.sql tiene las mismas consultas que usa la app', function () {
    expect(file_get_contents(base_path('docs/Consultas_Reportes.sql')))->toBe(Reportes::archivoSql());
});

test('la verificación de cobros cuadra: cada persona que subió tiene su pago y lo cobrado coincide con la tarifa', function () {
    $filas = collect(app(Reportes::class)->ejecutar('cobros'));

    expect($filas)->toHaveCount(8)
        ->and($filas->sum('subieron'))->toBeGreaterThan(0)
        ->and($filas->pluck('verificacion')->unique()->all())->toBe(['Cuadra'])
        ->and($filas->sum('sin_pago'))->toBe(0);
});

test('si alguien sube sin que quede su pago, la verificación de cobros lo marca', function () {
    $visita = VisitaViaje::whereHas('viaje', fn ($q) => $q->where('id_linea', Linea::where('codigo_linea', 'L1')->value('id_linea')))
        ->whereDate('hora_llegada', today())->firstOrFail();
    $visita->increment('subieron', 2);   // 2 personas contadas al subir, sin pago registrado

    $l1 = collect(app(Reportes::class)->ejecutar('cobros', ['linea' => 'L1']))->sole();
    expect($l1['sin_pago'])->toBe(2)->and($l1['verificacion'])->toBe('No cuadra');

    $this->actingAs($this->administrador())->get('/admin/reportes/cobros?linea=L1')->assertOk()
        ->assertSee('Verificación de cobros')->assertSee('No cuadra');
});

test('el menú de arriba tiene los reportes', function () {
    $this->actingAs($this->administrador())->get('/admin/lineas')->assertOk()
        ->assertSeeInOrder(['Reportes', 'Todos los reportes', 'Estaciones', 'Líneas', 'Buses asignados a cada línea', 'Verificación de cobros']);
    $this->actingAs($this->operador())->get('/operador')->assertOk()->assertDontSee('Todos los reportes');
});
