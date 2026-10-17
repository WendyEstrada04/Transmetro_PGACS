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

    /*
    | Estado inicial de la simulación cuando nadie la ha encendido ni pausado. En la versión publicada (Render)
    | arranca encendida; después, Iniciar y Pausar guardan la decisión en la base de datos y se respeta siempre.
    */
    'simulacion_encendida' => (bool) env('TRANSMETRO_SIMULACION_ENCENDIDA', false),

    /*
    | Motor en segundo plano (php artisan transmetro:motor): segundos entre ciclos cuando alguien tiene una
    | pantalla abierta y cuando nadie la está viendo. Sin nadie mirando la operación se sigue calculando,
    | pero más despacio, para no gastar la transferencia mensual de Supabase.
    */
    'motor_segundos_con_vista' => (int) env('TRANSMETRO_MOTOR_SEGUNDOS_CON_VISTA', 5),
    'motor_segundos_sin_vista' => (int) env('TRANSMETRO_MOTOR_SEGUNDOS_SIN_VISTA', 120),

    /*
    | Mantener despierto el servidor publicado: el motor visita la página /up cada 10 minutos para que el plan
    | gratis de Render no apague la aplicación. Solo en Render (RENDER_EXTERNAL_URL la pone Render).
    */
    'url_mantener_despierto' => env('TRANSMETRO_MANTENER_DESPIERTO', false) ? env('RENDER_EXTERNAL_URL', env('APP_URL')) : null,

    /*
    | Limpieza automática de la operación simulada (cada hora, desde el motor): se borran los pagos, bajadas,
    | viajes terminados y alertas cerradas con más de estas horas. Si aun así la base pasa del límite (Supabase
    | gratis permite 500 MB), se conservan solo las últimas 12 horas. 0 = no limpiar (laptop).
    */
    'retencion_horas' => (int) env('TRANSMETRO_RETENCION_HORAS', 0),
    'limite_base_mb' => (int) env('TRANSMETRO_LIMITE_BASE_MB', 350),
];
