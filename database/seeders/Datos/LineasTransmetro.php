<?php

/**
 * Líneas del Transmetro usadas en los datos de prueba.
 *
 * Para agregar o corregir una línea basta con editar este archivo y ejecutar `php artisan db:seed`.
 * Cada estación: [nombre, dirección, latitud, longitud]. Si la latitud/longitud es null, la
 * posición se aproxima con los "tramos" de la línea.
 *
 * Fuentes:
 *  - L1: página oficial muniguate.com/movilidadurbana/linea-1 (estaciones, direcciones y horario).
 *    Coordenadas aproximadas a partir de las direcciones (cuadrícula de la zona 1).
 *  - L2: página oficial muniguate.com/movilidadurbana/linea-2 y su mapa de Google My Maps
 *    (estaciones, orden, direcciones, transbordo y horario). Coordenadas aproximadas medidas sobre ese mapa.
 *    Recorrido: sube por la 7a. avenida y la Av. Simeón Cañas; baja por la Av. Simeón Cañas y la 6a. avenida.
 *  - L6: página oficial muniguate.com/movilidadurbana/linea-6 y su mapa de Google My Maps (estaciones,
 *    orden, direcciones y horario). Coordenadas aproximadas: ajustarlas con "Mover estaciones" en el editor del trazo.
 *  - Colores: los oficiales de la página "Información por línea" de muniguate.com.
 */
return [
    'L1' => [
        'nombre' => 'San Sebastián – Centro Cívico',
        'color' => '#5F4090',
        'circular' => true,
        'tipo_servicio' => 'paradas_continuas',
        'tarifa' => 1.00,
        'horario' => ['lunes_viernes' => ['05:00', '20:00'], 'sabado' => ['05:00', '19:00'], 'domingo_feriado' => ['06:30', '19:00']],
        'buses' => 12,
        'capacidad' => 90,
        'estaciones' => [
            ['San Sebastián', '3a. calle y 6a. avenida, zona 1', 14.64580, -90.51350],
            ['Mercado Central', '8a. avenida y 6a. calle, zona 1', 14.64300, -90.51180],
            ['Correos', '8a. avenida y 12 calle, zona 1', 14.63730, -90.51180],
            ['Beatas de Belén', '8a. avenida y 15 calle, zona 1', 14.63450, -90.51180],
            ['Paseo de las Letras', '8a. avenida y 19 calle, zona 1', 14.63070, -90.51180],
            ['Centro Cívico', '21 calle y 6a. avenida "A", zona 1', 14.62880, -90.51310],
            ['Sur 2', '5a. avenida y 18 calle, zona 1', 14.63160, -90.51440],
            ['Gómez Carrillo', '5a. avenida y 14 calle, zona 1', 14.63540, -90.51440],
            ['San Agustín', '5a. avenida y 11 calle, zona 1', 14.63820, -90.51440],
            ['Parque Centenario', '5a. avenida y 8a. calle, zona 1', 14.64110, -90.51440],
        ],
    ],
    'L2' => [
        'nombre' => 'Hipódromo del Norte – San Sebastián',
        'color' => '#9F85BC',
        'circular' => true,
        'tipo_servicio' => 'paradas_continuas',
        'tarifa' => 1.00,
        'horario' => ['lunes_viernes' => ['05:00', '20:00'], 'sabado' => ['05:00', '19:00'], 'domingo_feriado' => ['06:30', '19:00']],
        'buses' => 8,
        'capacidad' => 90,
        // Recorrido circular: San Sebastián → Simeón Cañas → Hipódromo del Norte → San José de la Montaña → Jocotenango → San Sebastián.
        // San Sebastián es estación de transbordo con la Línea 1 (misma estación).
        'estaciones' => [
            ['San Sebastián', '3a. calle y 6a. avenida, zona 1', 14.64580, -90.51350],
            ['Simeón Cañas', 'Avenida Simeón Cañas y 7a. calle, zona 2', 14.65680, -90.51160],
            ['Hipódromo del Norte', 'Avenida Simeón Cañas y 11 calle, zona 2', 14.66270, -90.50930],
            ['San José de la Montaña', 'Avenida Simeón Cañas y 6a. calle, zona 2', 14.65610, -90.51200],
            ['Jocotenango', '6a. avenida entre 3a. y 4a. calle, zona 2', 14.65220, -90.51330],
        ],
    ],
    'L6' => [
        'nombre' => 'Proyectos – FEGUA',
        'color' => '#FFE700',
        // Recorrido en circuito: tronco común Proyectos – Proyectos 4-4 – Cipresales (doble abordaje),
        // ida a FEGUA por la 15 avenida z6 y la 10a avenida z1, y regreso por la 11 avenida z1 y la 16 avenida z6.
        // Cerro del Carmen sirve a los dos sentidos: se registra una sola vez (orden de la ida).
        'circular' => true,
        'tipo_servicio' => 'paradas_continuas',
        'tarifa' => 1.00,
        'horario' => ['lunes_viernes' => ['05:00', '21:00'], 'sabado' => ['05:00', '20:00'], 'domingo_feriado' => ['06:00', '20:00']],
        'buses' => 24,
        'capacidad' => 120,
        'estaciones' => [
            ['Proyectos', 'Avenida La Pedrera y 25 calle, zona 6', 14.66960, -90.49390],
            ['Proyectos 4-4', 'Avenida La Pedrera y 20 calle, zona 6', 14.66535, -90.49615],
            ['Cipresales', 'Avenida La Pedrera y 18 calle, zona 6', 14.66365, -90.49705],
            ['Quintanal', '15 avenida y 13 calle, zona 6', 14.66000, -90.50050],
            ['Corpus Christi', '15 avenida y 10a. calle, zona 6', 14.65745, -90.50185],
            ['José Martí', '14 avenida y 7a. calle, zona 6', 14.65530, -90.50400],
            ['Cerro del Carmen', '1a. calle y 13 avenida, entre zonas 1 y 2', 14.64780, -90.50750],
            ['Santa Teresa', '10a. avenida y 4a. calle, zona 1', 14.64490, -90.51010],
            ['Capuchinas', '10a. avenida y 10a. calle, zona 1', 14.63920, -90.51010],
            ['Plaza Barrios/FEGUA', '18 calle entre 9a. y 10a. avenida, zona 1', 14.63160, -90.51050],
            ['Francos y Monroy', '11 avenida y 15 calle, zona 1', 14.63450, -90.50920],
            ['Colón', '11 avenida y 8a. calle, zona 1', 14.64110, -90.50920],
            ['Parroquia', '15 avenida y 3a. calle, zona 6', 14.65150, -90.50500],
            ['IGSS Zona 6', '16 avenida y 6a. calle, zona 6', 14.65365, -90.50285],
            ['Centro Zona 6', '16 avenida y 10a. calle, zona 6', 14.65705, -90.50105],
            ['Academia', '16 avenida y 15 calle, zona 6', 14.66130, -90.49880],
        ],
    ],
];
