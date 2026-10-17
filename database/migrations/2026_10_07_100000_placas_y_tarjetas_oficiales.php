<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Placas y Tarjeta Ciudadana como se manejan en Guatemala.
 *
 * PLACAS: los buses del Transmetro usan placa de Guatemala, p. ej. C387JGL
 * (letra del tipo de vehículo + 3 números + 3 letras). Se cambian las placas de prueba (TM-001…)
 * y se valida el formato con chk_buses_placa.
 *
 * TARJETA CIUDADANA (Ciudad Integrada), tres tipos:
 *  - vecino:        de 18 a 59 años, cuesta Q20. Se puede afiliar (perfil en la app/web de POCAE): así,
 *                   si se pierde o la roban, el saldo y los datos pasan a una tarjeta nueva. Sin afiliar
 *                   funciona al portador.
 *  - adulto_mayor:  de 60 años en adelante. La primera es gratis y la reposición cuesta Q20.
 *                   Tiene 4 viajes de cortesía diarios en Transmetro (no acumulables, todos los días).
 *  - discapacidad:  gratuita.
 * El número va impreso atrás con un código QR: 10 dígitos que se muestran como 00 0040 0001.
 *
 * Columnas nuevas en tarjetas: afiliada, fecha_nacimiento_titular, id_tarjeta_anterior (reposición).
 * Estado nuevo: 'reemplazada' (la tarjeta vieja después de una reposición).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
-- ---------------------------------------------------------------- PLACAS
-- C + 3 números + 3 consonantes, distinta para cada bus.
WITH n AS (SELECT id_bus, (row_number() OVER (ORDER BY placa) - 1)::int AS k FROM buses),
     c AS (SELECT 'BCDFGHJKLMNPQRSTVWXYZ'::text AS l)
UPDATE buses b
   SET placa = 'C' || lpad((100 + (n.k * 137) % 900)::text, 3, '0')
             || substr(c.l, ((n.k + 3000) / 441) % 21 + 1, 1)
             || substr(c.l, ((n.k + 3000) / 21) % 21 + 1, 1)
             || substr(c.l, (n.k + 3000) % 21 + 1, 1)
  FROM n, c
 WHERE b.id_bus = n.id_bus AND b.placa !~ '^(P|A|C|U|O|M|TC|TE)[0-9]{3}[A-Z]{3}$';

ALTER TABLE buses ADD CONSTRAINT chk_buses_placa CHECK (placa ~ '^(P|A|C|U|O|M|TC|TE)[0-9]{3}[A-Z]{3}$');

UPDATE users u SET name = 'Operador bus ' || b.placa
  FROM operadores o JOIN buses b ON b.id_bus = o.id_bus_asignado
 WHERE u.id_empleado = o.id_empleado AND u.rol = 'operador' AND u.name LIKE 'Operador bus %';

-- ---------------------------------------------------------------- TARJETAS
ALTER TABLE tarjetas DROP CONSTRAINT chk_tarjetas_tipo;
ALTER TABLE tarjetas DROP CONSTRAINT chk_tarjetas_titular;
ALTER TABLE tarjetas DROP CONSTRAINT tarjetas_estado_check;

UPDATE tarjetas SET tipo_tarjeta = 'vecino' WHERE tipo_tarjeta = 'ciudadana';
ALTER TABLE tarjetas ALTER COLUMN tipo_tarjeta SET DEFAULT 'vecino';

ALTER TABLE tarjetas ADD COLUMN afiliada BOOLEAN NOT NULL DEFAULT false;
ALTER TABLE tarjetas ADD COLUMN fecha_nacimiento_titular DATE NULL;
ALTER TABLE tarjetas ADD COLUMN id_tarjeta_anterior UUID NULL;
ALTER TABLE tarjetas ADD CONSTRAINT fk_tarjetas_anterior FOREIGN KEY (id_tarjeta_anterior)
    REFERENCES tarjetas(id_tarjeta) ON UPDATE CASCADE ON DELETE SET NULL;

-- Las tarjetas de adulto mayor y de discapacidad son personalizadas (con titular): quedan afiliadas.
UPDATE tarjetas SET afiliada = true WHERE tipo_tarjeta <> 'vecino';
-- Adulto mayor (la primera) y discapacidad son gratis; el adulto mayor usa sus 4 viajes de cortesía diarios.
UPDATE tarjetas SET costo_emision = 0, viajes_gratis_restantes = 0 WHERE tipo_tarjeta <> 'vecino';

-- Número de 10 dígitos (00 0040 0001, 00 0040 0002, …) en el orden en que se emitieron.
WITH n AS (SELECT id_tarjeta, row_number() OVER (ORDER BY fecha_emision, numero_tarjeta) AS k FROM tarjetas)
UPDATE tarjetas t SET numero_tarjeta = lpad((400000 + n.k)::text, 10, '0')
  FROM n WHERE t.id_tarjeta = n.id_tarjeta AND t.numero_tarjeta !~ '^[0-9]{10}$';

ALTER TABLE tarjetas ADD CONSTRAINT chk_tarjetas_tipo CHECK (tipo_tarjeta IN ('vecino', 'adulto_mayor', 'discapacidad'));
ALTER TABLE tarjetas ADD CONSTRAINT chk_tarjetas_titular
    CHECK ((tipo_tarjeta = 'vecino' AND NOT afiliada) OR (nombre_titular IS NOT NULL AND dpi_titular IS NOT NULL));
ALTER TABLE tarjetas ADD CONSTRAINT chk_tarjetas_afiliada CHECK (tipo_tarjeta = 'vecino' OR afiliada);
ALTER TABLE tarjetas ADD CONSTRAINT chk_tarjetas_numero CHECK (numero_tarjeta ~ '^[0-9]{10}$');
ALTER TABLE tarjetas ADD CONSTRAINT tarjetas_estado_check CHECK (estado IN ('activa', 'bloqueada', 'vencida', 'reemplazada'));
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE tarjetas DROP CONSTRAINT tarjetas_estado_check;
ALTER TABLE tarjetas DROP CONSTRAINT chk_tarjetas_numero;
ALTER TABLE tarjetas DROP CONSTRAINT chk_tarjetas_afiliada;
ALTER TABLE tarjetas DROP CONSTRAINT chk_tarjetas_titular;
ALTER TABLE tarjetas DROP CONSTRAINT chk_tarjetas_tipo;
UPDATE tarjetas SET estado = 'bloqueada' WHERE estado = 'reemplazada';
UPDATE tarjetas SET tipo_tarjeta = 'ciudadana' WHERE tipo_tarjeta = 'vecino';
ALTER TABLE tarjetas ALTER COLUMN tipo_tarjeta SET DEFAULT 'ciudadana';
ALTER TABLE tarjetas DROP CONSTRAINT fk_tarjetas_anterior;
ALTER TABLE tarjetas DROP COLUMN id_tarjeta_anterior, DROP COLUMN fecha_nacimiento_titular, DROP COLUMN afiliada;
ALTER TABLE tarjetas ADD CONSTRAINT chk_tarjetas_tipo CHECK (tipo_tarjeta IN ('ciudadana', 'adulto_mayor', 'discapacidad'));
ALTER TABLE tarjetas ADD CONSTRAINT chk_tarjetas_titular CHECK (tipo_tarjeta = 'ciudadana' OR (nombre_titular IS NOT NULL AND dpi_titular IS NOT NULL));
ALTER TABLE tarjetas ADD CONSTRAINT tarjetas_estado_check CHECK (estado IN ('activa', 'bloqueada', 'vencida'));
ALTER TABLE buses DROP CONSTRAINT chk_buses_placa;
SQL);
    }
};
