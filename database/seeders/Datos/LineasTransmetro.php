<?php

/**
 * Líneas del Transmetro usadas en los datos de prueba (php artisan db:seed).
 *
 * Fuente: mapas oficiales de la Municipalidad de Guatemala (Google My Maps publicados en
 * muniguate.com/movilidadurbana), descargados como KML en database/seeders/Datos/mapas/.
 * De cada mapa se toman el recorrido, las estaciones, sus direcciones y su ubicación exacta;
 * el orden de las estaciones sale del propio recorrido (ver MapasOficiales.php).
 *
 * Aquí solo se define lo que el mapa no trae:
 *  - nombre, color oficial (página "Información por línea"), tarifa y tipo de servicio;
 *  - horario oficial de cada línea (tabla "Horarios de servicio" de la Municipalidad): indica el inicio
 *    y el final del servicio en las estaciones extremo; se puede editar en la app;
 *  - cantidad de buses (entre n y 2n, n = número de estaciones) y su capacidad (datos de prueba);
 *  - la estación terminal donde empieza el recorrido ('inicio');
 *  - 'renombrar': nombres del mapa que se aclaran; 'orden': orden manual cuando el mapa tiene
 *    ramales dibujados como figuras separadas y el orden automático no es confiable.
 */
$horario = fn (array $lv, array $sab, array $dom) => ['lunes_viernes' => $lv, 'sabado' => $sab, 'domingo_feriado' => $dom];

return [
    'L1' => [
        'nombre' => 'San Sebastián – Centro Cívico',
        'color' => '#5F4090',
        'mapa' => 'linea-1.kml',
        'inicio' => 'San Sebastián',
        'tipo_servicio' => 'paradas_continuas',
        'tarifa' => 1.00,
        'horario' => $horario(['05:00', '20:00'], ['05:00', '19:00'], ['06:30', '19:00']),
        'buses' => 12,
        'capacidad' => 90,
    ],
    'L2' => [
        'nombre' => 'Hipódromo del Norte – San Sebastián',
        'color' => '#9F85BC',
        'mapa' => 'linea-2.kml',
        'inicio' => 'San Sebastián',
        'tipo_servicio' => 'paradas_continuas',
        'tarifa' => 1.00,
        'horario' => $horario(['05:30', '20:00'], ['05:30', '19:00'], ['06:30', '19:00']),
        'buses' => 8,
        'capacidad' => 90,
    ],
    'L6' => [
        'nombre' => 'Proyectos – FEGUA',
        'color' => '#FFE700',
        'mapa' => 'linea-6.kml',
        'inicio' => 'Proyectos',
        'tipo_servicio' => 'paradas_continuas',
        'tarifa' => 1.00,
        'horario' => $horario(['05:00', '21:00'], ['05:00', '20:00'], ['06:00', '20:00']),
        'buses' => 24,
        'capacidad' => 120,
    ],
    'L7' => [
        'nombre' => 'USAC Periférico – La Merced',
        'color' => '#B0AFAF',
        'mapa' => 'linea-7.kml',
        'inicio' => 'USAC Periférico',
        'tipo_servicio' => 'paradas_continuas',
        'tarifa' => 1.00,
        'horario' => $horario(['05:00', '21:00'], ['05:00', '20:00'], ['06:00', '20:00']),
        'buses' => 38,
        'capacidad' => 120,
    ],
    'L12' => [
        'nombre' => 'Centra Sur – Plaza Barrios',
        'color' => '#E9511D',
        'mapa' => 'linea-12.kml',
        // También tiene servicio EXPRESO Centra Sur / Trébol / Plaza El Amate: L-V 4:30–8:30 y 16:30–19:00, sáb 4:30–8:30 y 11:30–13:30.
        'inicio' => 'Centra Sur',
        'tipo_servicio' => 'paradas_continuas',
        'tarifa' => 1.00,
        'horario' => $horario(['04:30', '21:00'], ['04:30', '21:00'], ['04:30', '21:00']),
        'buses' => 24,
        'capacidad' => 160,
    ],
    'L13' => [
        'nombre' => 'Plaza Argentina – Tipografía',
        'color' => '#74BC1F',
        'mapa' => 'linea-13.kml',
        'inicio' => 'Tipografía',
        'tipo_servicio' => 'paradas_continuas',
        'tarifa' => 1.00,
        'horario' => $horario(['05:00', '21:00'], ['05:00', '20:00'], ['05:00', '20:00']),
        'buses' => 28,
        'capacidad' => 160,
    ],
    'L18' => [
        'nombre' => 'Atlántida – FEGUA',
        'color' => '#009FE3',
        'mapa' => 'linea-18.kml',
        'inicio' => 'Atlántida',
        'renombrar' => ['Atlántida - San Rafael - Paraíso' => 'Atlántida (dir. Paraíso)'],
        // Ramal Atlántida – San Rafael – Paraíso y circuito del centro (baja por la 10a. avenida,
        // sube por la 11 avenida, igual que la Línea 6).
        'orden' => ['Atlántida', 'Portales', 'Victorias', 'San Martín (dir. Plaza Barrios/FEGUA)', 'Capuchinas',
            'Plaza Barrios/FEGUA', 'Francos Monroy', 'Colón', 'Cerro del Carmen', 'San Martín (dir. Atlántida)',
            'Atlántida (dir. Paraíso)', 'San Rafael', 'Paraíso'],
        'tipo_servicio' => 'paradas_continuas',
        'tarifa' => 1.00,
        'horario' => $horario(['05:00', '21:00'], ['05:00', '20:00'], ['06:00', '20:00']),
        'buses' => 18,
        'capacidad' => 120,
    ],
    'R5' => [
        'nombre' => 'TuBus Ruta 5: Parque Colón – Puente de la Penitenciaría',
        'color' => '#160B8B',
        'mapa' => 'ruta-5.kml',
        'inicio' => 'Parque Colón',
        // En el mapa las estaciones por sentido se llaman "Sur" (hacia Puente de la Penitenciaría) y "Norte" (hacia Parque Colón).
        'renombrar' => [
            'Estacion Parque Colon' => 'Parque Colón',
            'Estacion Cipreses Norte' => 'Cipreses (dir. Parque Colón)',
            'Estacion Cipreses Sur' => 'Cipreses (dir. Puente de la Penitenciaría)',
            'Estación Jardines de la Asunción Norte' => 'Jardines de La Asunción (dir. Parque Colón)',
            'Estación Jardines de la Asunción Sur' => 'Jardines de La Asunción (dir. Puente de la Penitenciaría)',
            'Estación Palacio de los Deportes Norte' => 'Palacio de los Deportes (dir. Parque Colón)',
            'Estación Palacio de los Deportes Sur' => 'Palacio de los Deportes (dir. Puente de la Penitenciaría)',
            'Estacion Parque Navidad' => 'Parque Navidad',
            'Estacion Mercado La Palmita' => 'Mercado La Palmita',
        ],
        // El mapa de TuBus no trae direcciones: se toman de la lista oficial de estaciones.
        'direcciones' => [
            'Parque Colón' => '12 avenida y 9a. calle, zona 1',
            'Cipreses (dir. Parque Colón)' => 'Bulevar La Asunción y Arco 3, zona 5',
            'Cipreses (dir. Puente de la Penitenciaría)' => 'Bulevar La Asunción y 11 calle, zona 5',
            'Jardines de La Asunción (dir. Parque Colón)' => 'Bulevar La Asunción y Arco 5-6, zona 5',
            'Jardines de La Asunción (dir. Puente de la Penitenciaría)' => 'Bulevar La Asunción y 12 calle "B", zona 5',
            'Arrivillaga' => 'Bulevar La Asunción y 20 calle, zona 5',
            'Parque Navidad' => '23 calle y 28 avenida, zona 5',
            'La Palmita' => '27 calle y 20 avenida, zona 5',
            'Palacio de los Deportes (dir. Parque Colón)' => '10a. avenida y 24 calle, zona 5',
            'Palacio de los Deportes (dir. Puente de la Penitenciaría)' => '24 calle y 9a. avenida, zona 5',
            'Puente de la Penitenciaría' => 'Vía 1 y 7a. avenida, zona 4',
            'Mercado La Palmita' => '26 calle y 17 avenida "A", zona 5',
            'Vivibien' => 'Diagonal 14 y 23 calle, zona 5',
            'Matamoros' => '7a. calle y 16 avenida, zona 1',
        ],
        'tipo_servicio' => 'paradas_continuas',
        'tarifa' => 1.00,
        'horario' => $horario(['05:30', '19:00'], ['05:30', '19:00'], ['06:00', '18:00']),
        'buses' => 18,
        'capacidad' => 60,
    ],
];
