<?php

use App\Models\Bus;
use App\Models\Estacion;
use App\Models\HorarioLinea;
use App\Models\Linea;

/*
 * Reglas de negocio calculadas en los modelos (no necesitan base de datos).
 */

test('punto 15: un bus con menos del 25 % de ocupación espera 5 minutos más', function (float $ocupacion, int $espera) {
    $bus = new Bus(['ocupacion_actual_pct' => $ocupacion]);

    expect($bus->minutosEsperaExtra())->toBe($espera);
})->with([
    'vacío' => [0, 5],
    'casi 25 %' => [24.99, 5],
    'justo 25 %' => [25, 0],
    'lleno' => [100, 0],
]);

test('punto 14: la estación requiere refuerzo desde el 150 % de su aforo', function (int $actual, bool $refuerzo) {
    $estacion = new Estacion(['aforo_maximo' => 200, 'aforo_actual' => $actual]);

    expect($estacion->requiereRefuerzo())->toBe($refuerzo);
})->with([[299, false], [300, true], [450, true]]);

test('el porcentaje de aforo se redondea a un decimal', function () {
    $estacion = new Estacion(['aforo_maximo' => 300, 'aforo_actual' => 100]);

    expect($estacion->porcentajeAforo())->toBe(33.3);
});

test('el tipo de día sale de la fecha', function (string $fecha, string $tipo) {
    expect(HorarioLinea::tipoDiaDe(new DateTimeImmutable($fecha)))->toBe($tipo);
})->with([
    'lunes' => ['2026-09-28', 'lunes_viernes'],
    'viernes' => ['2026-10-02', 'lunes_viernes'],
    'sábado' => ['2026-10-03', 'sabado'],
    'domingo' => ['2026-10-04', 'domingo_feriado'],
]);

test('la línea está en servicio solo dentro de su horario', function (string $momento, bool $enServicio) {
    $linea = new Linea(['estado' => 'activa']);
    $linea->setRelation('horarios', collect([
        new HorarioLinea(['tipo_dia' => 'lunes_viernes', 'hora_inicio' => '05:00:00', 'hora_fin' => '20:00:00']),
        new HorarioLinea(['tipo_dia' => 'domingo_feriado', 'hora_inicio' => '06:30:00', 'hora_fin' => '19:00:00']),
    ]));

    expect($linea->enServicio(new DateTimeImmutable($momento)))->toBe($enServicio);
})->with([
    'lunes 04:59' => ['2026-09-28 04:59', false],
    'lunes 05:00' => ['2026-09-28 05:00', true],
    'lunes 20:00' => ['2026-09-28 20:00', true],
    'lunes 20:01' => ['2026-09-28 20:01', false],
    'domingo 06:00' => ['2026-10-04 06:00', false],
    'domingo 07:00' => ['2026-10-04 07:00', true],
    'sábado sin horario' => ['2026-10-03 10:00', false],
]);

test('una línea inactiva nunca está en servicio', function () {
    $linea = new Linea(['estado' => 'inactiva']);
    $linea->setRelation('horarios', collect([
        new HorarioLinea(['tipo_dia' => 'lunes_viernes', 'hora_inicio' => '05:00:00', 'hora_fin' => '20:00:00']),
    ]));

    expect($linea->enServicio(new DateTimeImmutable('2026-09-28 10:00')))->toBeFalse();
});
