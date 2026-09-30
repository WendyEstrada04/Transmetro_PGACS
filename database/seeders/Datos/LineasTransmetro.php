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
 *  - Colores: los oficiales de la página "Información por línea" de muniguate.com.
 *  - L12 y L13: listas de estaciones de transmetroguatemala.com (sitio no oficial);
 *    horario de muniguate.com. Coordenadas aproximadas (pendiente confirmar con la página oficial).
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
    'L12' => [
        'nombre' => 'Centra Sur – Plaza Barrios',
        'color' => '#E9511D',
        'circular' => false,
        'tipo_servicio' => 'paradas_continuas',
        'tarifa' => 1.00,
        'horario' => ['lunes_viernes' => ['05:00', '20:00'], 'sabado' => ['05:00', '19:00'], 'domingo_feriado' => ['06:30', '19:00']],
        'buses' => 18,
        'capacidad' => 160,
        'tramos' => [[8, [14.5669, -90.5508], [14.6180, -90.5292]], [6, [14.6210, -90.5270], [14.6380, -90.5141]]],
        'estaciones' => array_map(fn ($n) => [$n, null, null, null], ['Centra Sur', 'Monte María', 'Javier', 'Las Charcas',
            'El Carmen', 'Reformita', 'Mariscal', 'Trébol', 'Santa Cecilia', 'Bolívar', 'Don Bosco', 'Plaza Municipal',
            'Plaza El Amate', 'Plaza Barrios']),
    ],
    'L13' => [
        'nombre' => 'Tipografía – Hangares',
        'color' => '#74BC1F',
        'circular' => true,
        'tipo_servicio' => 'paradas_continuas',
        'tarifa' => 1.00,
        'horario' => ['lunes_viernes' => ['05:00', '20:00'], 'sabado' => ['05:00', '19:00'], 'domingo_feriado' => ['06:30', '19:00']],
        'buses' => 24,
        'capacidad' => 120,
        'tramos' => [[11, [14.6268, -90.5178], [14.5885, -90.5318]], [11, [14.5895, -90.5262], [14.6262, -90.5148]]],
        'estaciones' => array_map(fn ($n) => [$n, null, null, null], ['Tipografía', 'El Calvario', '4 Grados Sur', 'Exposición',
            'Terminal', 'Industria', 'Tívoli', 'Montúfar', 'Acueducto', 'Fuerza Aérea', 'Hangares', 'Plaza Argentina',
            'Juan Pablo II', 'Plaza Berlín', 'Los Arcos', 'Plaza España', 'IGSS Zona 9', 'Seis 26', 'Torre del Reformador',
            'Plaza de la República', 'Cantón Exposición', 'Banco de Guatemala']),
    ],
];
