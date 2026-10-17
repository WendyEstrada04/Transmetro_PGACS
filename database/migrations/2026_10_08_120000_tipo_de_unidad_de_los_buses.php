<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tipo de unidad de cada bus y su capacidad real.
 *  - biarticulado: 270 pasajeros (Línea 12, eje troncal con más demanda)
 *  - articulado:   160 pasajeros (Líneas 6, 7, 12, 13 y 18)
 *  - convencional: 119 pasajeros (Líneas 1 y 2, calles angostas del Centro Histórico)
 *  - electrico:     80 pasajeros (Ruta 5, buses eléctricos de TuBus)
 * Los buses que ya existen toman el tipo de su línea (en la L12, uno de cada tres es biarticulado).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE buses ADD COLUMN tipo_unidad VARCHAR(20) NOT NULL DEFAULT 'articulado';
ALTER TABLE buses ADD CONSTRAINT chk_buses_tipo_unidad CHECK (tipo_unidad IN ('biarticulado', 'articulado', 'convencional', 'electrico'));

WITH t AS (
    SELECT b.id_bus,
           CASE
               WHEN l.codigo_linea IN ('L1', 'L2') THEN 'convencional'
               WHEN l.codigo_linea = 'R5' THEN 'electrico'
               WHEN l.codigo_linea = 'L12' AND row_number() OVER (PARTITION BY l.id_linea ORDER BY b.placa) % 3 = 1 THEN 'biarticulado'
               ELSE 'articulado'
           END AS tipo
      FROM buses b LEFT JOIN lineas l ON l.id_linea = b.id_linea_asignada
),
c AS (SELECT t.id_bus, t.tipo,
             CASE t.tipo WHEN 'biarticulado' THEN 270 WHEN 'convencional' THEN 119 WHEN 'electrico' THEN 80 ELSE 160 END AS capacidad
        FROM t)
UPDATE buses b
   SET tipo_unidad = c.tipo,
       capacidad_maxima = c.capacidad,
       pasajeros_a_bordo = LEAST(b.pasajeros_a_bordo, c.capacidad)
  FROM c
 WHERE b.id_bus = c.id_bus;
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE buses DROP CONSTRAINT IF EXISTS chk_buses_tipo_unidad;
ALTER TABLE buses DROP COLUMN IF EXISTS tipo_unidad;
SQL);
    }
};
