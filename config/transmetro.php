<?php

return [
    /*
    | Los buses circulan solo dentro del horario de su línea (Línea::enServicio): fuera de horario no salen
    | nuevos recorridos, los buses que terminan se retiran y las estaciones se vacían.
    | Las pruebas automáticas lo desactivan para no depender de la hora del reloj.
    */
    'respetar_horarios' => (bool) env('TRANSMETRO_RESPETAR_HORARIOS', true),

    /*
    | Tráfico en la simulación: en hora pico (lunes a viernes 6:00–8:30 y 17:00–19:30) los tramos tardan
    | un 40 % más, y cada tramo varía entre −10 % y +30 % (semáforos, cruces). Se apaga con false.
    */
    'trafico' => (bool) env('TRANSMETRO_TRAFICO', true),
];
