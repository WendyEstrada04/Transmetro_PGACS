-- =====================================================================
-- PROYECTO ACS - SISTEMA DE CONTROL TRANSMETRO
-- Script de creacion de estructuras de base de datos (DDL)
-- Motor: PostgreSQL 15+ (Supabase)
-- Autora: Wendy Celeste Nathaly Estrada Gonzalez
-- Fecha: 2026-10-13
-- Generado desde la base de datos resultante de las migraciones de Laravel.
-- Requiere la tabla users de Laravel (se crea con php artisan migrate).
-- =====================================================================

-- ---------------------------------------------------------------------
-- 1. MUNICIPIOS
--    Punto 11: cada linea y estacion pertenece a una municipalidad.
-- ---------------------------------------------------------------------
CREATE TABLE municipios (
    id_municipio  UUID NOT NULL DEFAULT gen_random_uuid(),
    codigo_iso    VARCHAR(10) NOT NULL,
    nombre        VARCHAR(150) NOT NULL,
    activo        BOOLEAN NOT NULL DEFAULT true,
    CONSTRAINT municipios_pkey PRIMARY KEY (id_municipio),
    CONSTRAINT uq_municipios_codigo_iso UNIQUE (codigo_iso)
);

-- ---------------------------------------------------------------------
-- 2. LINEAS
--    Puntos 11 y 13. distancia_total_km la calcula trg_linea_estacion_distancia. Tarifa, color, ruta circular y trazo (JSONB) del mapa oficial.
-- ---------------------------------------------------------------------
CREATE TABLE lineas (
    id_linea            UUID NOT NULL DEFAULT gen_random_uuid(),
    id_municipio        UUID NOT NULL,
    codigo_linea        VARCHAR(20) NOT NULL,
    distancia_total_km  DECIMAL(6,2) NOT NULL DEFAULT '0'::numeric,
    estado              VARCHAR(255) NOT NULL DEFAULT 'activa',
    nombre              VARCHAR(100),
    tipo_servicio       VARCHAR(20) NOT NULL DEFAULT 'paradas_continuas',
    tarifa              DECIMAL(5,2) NOT NULL DEFAULT 1.00,
    color               VARCHAR(7) NOT NULL DEFAULT '#155240',
    ruta_circular       BOOLEAN NOT NULL DEFAULT false,
    trazo               JSONB,
    CONSTRAINT lineas_pkey PRIMARY KEY (id_linea),
    CONSTRAINT uq_lineas_codigo UNIQUE (codigo_linea),
    CONSTRAINT fk_lineas_municipio FOREIGN KEY (id_municipio) REFERENCES municipios(id_municipio) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT chk_lineas_color CHECK (((color) ~ '^#[0-9A-Fa-f]{6}$')),
    CONSTRAINT chk_lineas_distancia CHECK ((distancia_total_km >= (0))),
    CONSTRAINT chk_lineas_tarifa CHECK ((tarifa >= (0))),
    CONSTRAINT chk_lineas_tipo_servicio CHECK (tipo_servicio IN ('paradas_continuas', 'directo')),
    CONSTRAINT chk_lineas_trazo CHECK (((trazo IS NULL) OR (jsonb_typeof(trazo) = 'array'))),
    CONSTRAINT lineas_estado_check CHECK (estado IN ('activa', 'inactiva', 'mantenimiento'))
);

-- ---------------------------------------------------------------------
-- 3. HORARIOS_LINEA
--    RF-22: horario oficial por tipo de dia (lunes a viernes, sabado, domingo y feriados).
-- ---------------------------------------------------------------------
CREATE TABLE horarios_linea (
    id_horario   UUID NOT NULL DEFAULT gen_random_uuid(),
    id_linea     UUID NOT NULL,
    tipo_dia     VARCHAR(20) NOT NULL,
    hora_inicio  TIME WITHOUT TIME ZONE NOT NULL,
    hora_fin     TIME WITHOUT TIME ZONE NOT NULL,
    CONSTRAINT horarios_linea_pkey PRIMARY KEY (id_horario),
    CONSTRAINT uq_horarios_linea_dia UNIQUE (id_linea, tipo_dia),
    CONSTRAINT fk_horarios_linea FOREIGN KEY (id_linea) REFERENCES lineas(id_linea) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT chk_horarios_rango CHECK ((hora_fin > hora_inicio)),
    CONSTRAINT chk_horarios_tipo_dia CHECK (tipo_dia IN ('lunes_viernes', 'sabado', 'domingo_feriado'))
);

-- ---------------------------------------------------------------------
-- 4. ESTACIONES
--    Puntos 11 y 14: aforo maximo y actual; direccion y coordenadas para el mapa.
-- ---------------------------------------------------------------------
CREATE TABLE estaciones (
    id_estacion   UUID NOT NULL DEFAULT gen_random_uuid(),
    id_municipio  UUID NOT NULL,
    nombre        VARCHAR(150) NOT NULL,
    aforo_maximo  INTEGER NOT NULL,
    aforo_actual  INTEGER NOT NULL DEFAULT 0,
    latitud       DECIMAL(9,6) NOT NULL,
    longitud      DECIMAL(9,6) NOT NULL,
    direccion     VARCHAR(200),
    CONSTRAINT estaciones_pkey PRIMARY KEY (id_estacion),
    CONSTRAINT fk_estaciones_municipio FOREIGN KEY (id_municipio) REFERENCES municipios(id_municipio) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT chk_estaciones_aforo CHECK (((aforo_maximo > 0) AND (aforo_actual >= 0))),
    CONSTRAINT chk_estaciones_coordenadas CHECK ((((latitud >= ('-90'::integer)) AND (latitud <= (90))) AND ((longitud >= ('-180'::integer)) AND (longitud <= (180)))))
);

-- ---------------------------------------------------------------------
-- 5. LINEA_ESTACION
--    Puntos 1, 2 y 12: orden de las estaciones de cada linea y distancia desde la parada anterior.
-- ---------------------------------------------------------------------
CREATE TABLE linea_estacion (
    id_linea             UUID NOT NULL,
    id_estacion          UUID NOT NULL,
    orden_parada         INTEGER NOT NULL,
    distancia_previa_km  DECIMAL(6,2) NOT NULL DEFAULT '0'::numeric,
    CONSTRAINT linea_estacion_pkey PRIMARY KEY (id_linea, id_estacion),
    CONSTRAINT uq_linea_orden UNIQUE (id_linea, orden_parada),
    CONSTRAINT fk_linea_estacion_estacion FOREIGN KEY (id_estacion) REFERENCES estaciones(id_estacion) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_linea_estacion_linea FOREIGN KEY (id_linea) REFERENCES lineas(id_linea) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT chk_linea_estacion_distancia CHECK ((distancia_previa_km >= (0))),
    CONSTRAINT chk_linea_estacion_orden CHECK ((orden_parada >= 1))
);
CREATE INDEX idx_linea_estacion_estacion ON linea_estacion (id_estacion);

-- ---------------------------------------------------------------------
-- 6. BUSES
--    Puntos 4, 14 y 15: un bus en una sola linea o ninguna (de n a 2n por linea), capacidad, pasajeros a bordo y tipo de unidad.
-- ---------------------------------------------------------------------
CREATE TABLE buses (
    id_bus                UUID NOT NULL DEFAULT gen_random_uuid(),
    placa                 VARCHAR(15) NOT NULL,
    capacidad_maxima      INTEGER NOT NULL,
    id_linea_asignada     UUID,
    ocupacion_actual_pct  DECIMAL(5,2) NOT NULL DEFAULT '0'::numeric,
    estado_logico         VARCHAR(255) NOT NULL DEFAULT 'fuera_de_servicio',
    pasajeros_a_bordo     INTEGER NOT NULL DEFAULT 0,
    tipo_unidad           VARCHAR(20) NOT NULL DEFAULT 'articulado',
    CONSTRAINT buses_pkey PRIMARY KEY (id_bus),
    CONSTRAINT uq_buses_placa UNIQUE (placa),
    CONSTRAINT fk_buses_linea FOREIGN KEY (id_linea_asignada) REFERENCES lineas(id_linea) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT buses_estado_logico_check CHECK (estado_logico IN ('en_ruta', 'en_espera', 'fuera_de_servicio', 'en_mantenimiento')),
    CONSTRAINT chk_buses_a_bordo CHECK (((pasajeros_a_bordo >= 0) AND (pasajeros_a_bordo <= capacidad_maxima))),
    CONSTRAINT chk_buses_capacidad CHECK ((capacidad_maxima > 0)),
    CONSTRAINT chk_buses_ocupacion CHECK (((ocupacion_actual_pct >= (0)) AND (ocupacion_actual_pct <= (100)))),
    CONSTRAINT chk_buses_placa CHECK (((placa) ~ '^(P|A|C|U|O|M|TC|TE)[0-9]{3}[A-Z]{3}$')),
    CONSTRAINT chk_buses_tipo_unidad CHECK (tipo_unidad IN ('biarticulado', 'articulado', 'convencional', 'electrico'))
);
CREATE INDEX idx_buses_linea ON buses (id_linea_asignada);

-- ---------------------------------------------------------------------
-- 7. ACCESOS
--    Punto 3: varios accesos por estacion (minimo 2); un acceso pertenece a una sola estacion.
-- ---------------------------------------------------------------------
CREATE TABLE accesos (
    id_acceso         UUID NOT NULL DEFAULT gen_random_uuid(),
    id_estacion       UUID NOT NULL,
    ubicacion_fisica  VARCHAR(100) NOT NULL,
    CONSTRAINT accesos_pkey PRIMARY KEY (id_acceso),
    CONSTRAINT fk_accesos_estacion FOREIGN KEY (id_estacion) REFERENCES estaciones(id_estacion) ON UPDATE CASCADE ON DELETE RESTRICT
);
CREATE INDEX idx_accesos_estacion ON accesos (id_estacion);

-- ---------------------------------------------------------------------
-- 8. MOLINETES
--    Molinete de cada acceso de la estacion y molinetes de entrada y salida de cada bus (cobro y conteo).
-- ---------------------------------------------------------------------
CREATE TABLE molinetes (
    id_molinete       UUID NOT NULL DEFAULT gen_random_uuid(),
    id_acceso         UUID,
    ip_address        VARCHAR(45) NOT NULL,
    estado_operativo  VARCHAR(255) NOT NULL DEFAULT 'activo',
    id_bus            UUID,
    tipo              VARCHAR(20) NOT NULL DEFAULT 'estacion',
    CONSTRAINT molinetes_pkey PRIMARY KEY (id_molinete),
    CONSTRAINT uq_molinetes_acceso UNIQUE (id_acceso),
    CONSTRAINT uq_molinetes_bus_tipo UNIQUE (id_bus, tipo),
    CONSTRAINT fk_molinetes_acceso FOREIGN KEY (id_acceso) REFERENCES accesos(id_acceso) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_molinetes_bus FOREIGN KEY (id_bus) REFERENCES buses(id_bus) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT chk_molinetes_tipo CHECK (tipo IN ('estacion', 'entrada_bus', 'salida_bus')),
    CONSTRAINT chk_molinetes_ubicacion CHECK (((((tipo) = 'estacion') AND (id_acceso IS NOT NULL) AND (id_bus IS NULL)) OR (((tipo) = ANY ((ARRAY['entrada_bus', 'salida_bus']))) AND (id_bus IS NOT NULL) AND (id_acceso IS NULL)))),
    CONSTRAINT molinetes_estado_operativo_check CHECK (estado_operativo IN ('activo', 'inactivo', 'mantenimiento'))
);

-- ---------------------------------------------------------------------
-- 9. PARQUEOS
--    Puntos 6 y 7: cada bus ocupa un parqueo; lo cambia con sp_cambiar_parqueo_bus sin quedarse sin el.
-- ---------------------------------------------------------------------
CREATE TABLE parqueos (
    id_parqueo       UUID NOT NULL DEFAULT gen_random_uuid(),
    id_estacion      UUID NOT NULL,
    codigo_bahia     VARCHAR(20) NOT NULL,
    id_bus_asignado  UUID,
    CONSTRAINT parqueos_pkey PRIMARY KEY (id_parqueo),
    CONSTRAINT uq_parqueos_bus UNIQUE (id_bus_asignado),
    CONSTRAINT uq_parqueos_estacion_bahia UNIQUE (id_estacion, codigo_bahia),
    CONSTRAINT fk_parqueos_bus FOREIGN KEY (id_bus_asignado) REFERENCES buses(id_bus) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_parqueos_estacion FOREIGN KEY (id_estacion) REFERENCES estaciones(id_estacion) ON UPDATE CASCADE ON DELETE RESTRICT
);

-- ---------------------------------------------------------------------
-- 10. EMPLEADOS
--    Punto 9: datos generales del personal (contacto y direccion cifrados por la aplicacion).
-- ---------------------------------------------------------------------
CREATE TABLE empleados (
    id_empleado           UUID NOT NULL DEFAULT gen_random_uuid(),
    dpi                   VARCHAR(20) NOT NULL,
    nombre_completo       VARCHAR(200) NOT NULL,
    fecha_nacimiento      DATE NOT NULL,
    rol                   VARCHAR(255) NOT NULL,
    contacto_encriptado   TEXT NOT NULL,
    direccion_encriptada  TEXT NOT NULL,
    activo                BOOLEAN NOT NULL DEFAULT true,
    CONSTRAINT empleados_pkey PRIMARY KEY (id_empleado),
    CONSTRAINT uq_empleados_dpi UNIQUE (dpi),
    CONSTRAINT chk_empleados_dpi CHECK (((dpi) ~ '^[0-9]{13}$')),
    CONSTRAINT empleados_rol_check CHECK (rol IN ('piloto', 'seguridad', 'operador'))
);

-- ---------------------------------------------------------------------
-- 11. PILOTOS
--    Punto 9: licencia, experiencia, historial educativo (cifrado) y bus asignado.
-- ---------------------------------------------------------------------
CREATE TABLE pilotos (
    id_empleado                     UUID NOT NULL,
    numero_licencia                 VARCHAR(30) NOT NULL,
    tipo_licencia                   VARCHAR(10) NOT NULL,
    anios_experiencia               INTEGER NOT NULL DEFAULT 0,
    nivel_educativo                 VARCHAR(50) NOT NULL,
    historial_educativo_encriptado  TEXT,
    id_bus_asignado                 UUID,
    CONSTRAINT pilotos_pkey PRIMARY KEY (id_empleado),
    CONSTRAINT uq_pilotos_bus UNIQUE (id_bus_asignado),
    CONSTRAINT uq_pilotos_licencia UNIQUE (numero_licencia),
    CONSTRAINT fk_pilotos_bus FOREIGN KEY (id_bus_asignado) REFERENCES buses(id_bus) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_pilotos_empleado FOREIGN KEY (id_empleado) REFERENCES empleados(id_empleado) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT chk_pilotos_experiencia CHECK ((anios_experiencia >= 0))
);

-- ---------------------------------------------------------------------
-- 12. SEGURIDAD
--    Punto 10: guardia asignado a un acceso; minimo uno por acceso (trg_seguridad_min_uno).
-- ---------------------------------------------------------------------
CREATE TABLE seguridad (
    id_empleado                      UUID NOT NULL,
    permisos_vigentes                VARCHAR(100) NOT NULL,
    constancia_antecedentes_penales  VARCHAR(100) NOT NULL,
    numero_placa_asignada            VARCHAR(15),
    id_acceso_asignado               UUID,
    CONSTRAINT seguridad_pkey PRIMARY KEY (id_empleado),
    CONSTRAINT fk_seguridad_acceso FOREIGN KEY (id_acceso_asignado) REFERENCES accesos(id_acceso) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_seguridad_empleado FOREIGN KEY (id_empleado) REFERENCES empleados(id_empleado) ON UPDATE CASCADE ON DELETE CASCADE
);
CREATE INDEX idx_seguridad_acceso ON seguridad (id_acceso_asignado);

-- ---------------------------------------------------------------------
-- 13. OPERADORES
--    Punto 16: un operador por estacion, a cargo de la PC de trabajo.
-- ---------------------------------------------------------------------
CREATE TABLE operadores (
    id_empleado                  UUID NOT NULL,
    nivel_estudios               VARCHAR(50) NOT NULL,
    anios_experiencia_monitoreo  INTEGER NOT NULL DEFAULT 0,
    id_estacion_asignada         UUID,
    CONSTRAINT operadores_pkey PRIMARY KEY (id_empleado),
    CONSTRAINT uq_operadores_estacion UNIQUE (id_estacion_asignada),
    CONSTRAINT fk_operadores_empleado FOREIGN KEY (id_empleado) REFERENCES empleados(id_empleado) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_operadores_estacion FOREIGN KEY (id_estacion_asignada) REFERENCES estaciones(id_estacion) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT chk_operadores_experiencia CHECK ((anios_experiencia_monitoreo >= 0))
);

-- ---------------------------------------------------------------------
-- 14. TARJETAS
--    Punto 18: Tarjeta Ciudadana (vecino, adulto mayor, discapacidad), saldo y viajes gratis.
-- ---------------------------------------------------------------------
CREATE TABLE tarjetas (
    id_tarjeta                UUID NOT NULL DEFAULT gen_random_uuid(),
    numero_tarjeta            VARCHAR(20) NOT NULL,
    saldo                     DECIMAL(8,2) NOT NULL DEFAULT '0'::numeric,
    estado                    VARCHAR(255) NOT NULL DEFAULT 'activa',
    fecha_emision             TIMESTAMP(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    tipo_tarjeta              VARCHAR(20) NOT NULL DEFAULT 'vecino',
    nombre_titular            VARCHAR(200),
    dpi_titular               VARCHAR(13),
    costo_emision             DECIMAL(6,2) NOT NULL DEFAULT 20.00,
    viajes_gratis_restantes   INTEGER NOT NULL DEFAULT 0,
    afiliada                  BOOLEAN NOT NULL DEFAULT false,
    fecha_nacimiento_titular  DATE,
    id_tarjeta_anterior       UUID,
    motivo_estado             VARCHAR(200),
    fecha_estado              TIMESTAMP(0),
    CONSTRAINT tarjetas_pkey PRIMARY KEY (id_tarjeta),
    CONSTRAINT uq_tarjetas_numero UNIQUE (numero_tarjeta),
    CONSTRAINT fk_tarjetas_anterior FOREIGN KEY (id_tarjeta_anterior) REFERENCES tarjetas(id_tarjeta) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT chk_tarjetas_afiliada CHECK ((((tipo_tarjeta) = 'vecino') OR afiliada)),
    CONSTRAINT chk_tarjetas_costo CHECK ((costo_emision >= (0))),
    CONSTRAINT chk_tarjetas_dpi CHECK (((dpi_titular IS NULL) OR ((dpi_titular) ~ '^[0-9]{13}$'))),
    CONSTRAINT chk_tarjetas_numero CHECK (((numero_tarjeta) ~ '^[0-9]{10}$')),
    CONSTRAINT chk_tarjetas_saldo CHECK ((saldo >= (0))),
    CONSTRAINT chk_tarjetas_tipo CHECK (tipo_tarjeta IN ('vecino', 'adulto_mayor', 'discapacidad')),
    CONSTRAINT chk_tarjetas_titular CHECK (((((tipo_tarjeta) = 'vecino') AND (NOT afiliada)) OR ((nombre_titular IS NOT NULL) AND (dpi_titular IS NOT NULL)))),
    CONSTRAINT chk_tarjetas_viajes_gratis CHECK ((viajes_gratis_restantes >= 0)),
    CONSTRAINT tarjetas_estado_check CHECK (estado IN ('activa', 'bloqueada', 'vencida', 'reemplazada', 'baja'))
);

-- ---------------------------------------------------------------------
-- 15. RECARGAS
--    Punto 18: recargas de Q5 a Q200 en el punto de recarga.
-- ---------------------------------------------------------------------
CREATE TABLE recargas (
    id_recarga   UUID NOT NULL DEFAULT gen_random_uuid(),
    id_tarjeta   UUID NOT NULL,
    monto        DECIMAL(8,2) NOT NULL,
    metodo_pago  VARCHAR(255) NOT NULL,
    fecha_hora   TIMESTAMP(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT recargas_pkey PRIMARY KEY (id_recarga),
    CONSTRAINT fk_recargas_tarjeta FOREIGN KEY (id_tarjeta) REFERENCES tarjetas(id_tarjeta) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT chk_recargas_monto CHECK (((monto >= (5)) AND (monto <= (200)))),
    CONSTRAINT recargas_metodo_pago_check CHECK (metodo_pago IN ('efectivo', 'debito', 'credito', 'en_linea'))
);
CREATE INDEX idx_recargas_tarjeta ON recargas (id_tarjeta);

-- ---------------------------------------------------------------------
-- 16. VIAJES
--    Punto 1: recorridos reales de cada bus en su linea.
-- ---------------------------------------------------------------------
CREATE TABLE viajes (
    id_viaje           UUID NOT NULL DEFAULT gen_random_uuid(),
    id_bus             UUID NOT NULL,
    id_linea           UUID NOT NULL,
    fecha_hora_inicio  TIMESTAMP(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_hora_fin     TIMESTAMP(0),
    estado             VARCHAR(255) NOT NULL DEFAULT 'en_curso',
    CONSTRAINT viajes_pkey PRIMARY KEY (id_viaje),
    CONSTRAINT fk_viajes_bus FOREIGN KEY (id_bus) REFERENCES buses(id_bus) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_viajes_linea FOREIGN KEY (id_linea) REFERENCES lineas(id_linea) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT chk_viajes_fechas CHECK (((fecha_hora_fin IS NULL) OR (fecha_hora_fin >= fecha_hora_inicio))),
    CONSTRAINT viajes_estado_check CHECK (estado IN ('en_curso', 'finalizado', 'cancelado'))
);
CREATE INDEX idx_viajes_bus ON viajes (id_bus);
CREATE INDEX idx_viajes_linea ON viajes (id_linea);

-- ---------------------------------------------------------------------
-- 17. VISITAS_VIAJE
--    Puntos 1 y 15: paradas visitadas con hora de llegada y salida, ocupacion y espera extra.
-- ---------------------------------------------------------------------
CREATE TABLE visitas_viaje (
    id_visita                UUID NOT NULL DEFAULT gen_random_uuid(),
    id_viaje                 UUID NOT NULL,
    id_estacion              UUID NOT NULL,
    orden_visita             INTEGER NOT NULL,
    hora_llegada             TIMESTAMP(0),
    hora_salida              TIMESTAMP(0),
    ocupacion_al_llegar_pct  DECIMAL(5,2),
    subieron                 INTEGER NOT NULL DEFAULT 0,
    bajaron                  INTEGER NOT NULL DEFAULT 0,
    CONSTRAINT visitas_viaje_pkey PRIMARY KEY (id_visita),
    CONSTRAINT uq_visitas_viaje_orden UNIQUE (id_viaje, orden_visita),
    CONSTRAINT fk_visitas_estacion FOREIGN KEY (id_estacion) REFERENCES estaciones(id_estacion) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_visitas_viaje FOREIGN KEY (id_viaje) REFERENCES viajes(id_viaje) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT chk_visitas_horas CHECK (((hora_salida IS NULL) OR (hora_llegada IS NULL) OR (hora_salida >= hora_llegada))),
    CONSTRAINT chk_visitas_ocupacion CHECK (((ocupacion_al_llegar_pct IS NULL) OR ((ocupacion_al_llegar_pct >= (0)) AND (ocupacion_al_llegar_pct <= (100))))),
    CONSTRAINT chk_visitas_orden CHECK ((orden_visita >= 1)),
    CONSTRAINT chk_visitas_pasajeros CHECK (((subieron >= 0) AND (bajaron >= 0)))
);
CREATE INDEX idx_visitas_estacion ON visitas_viaje (id_estacion, hora_llegada);

-- ---------------------------------------------------------------------
-- 18. ALERTAS_SISTEMA
--    Punto 14: alertas de refuerzo (150 % del aforo) y de bus lleno.
-- ---------------------------------------------------------------------
CREATE TABLE alertas_sistema (
    id_alerta       UUID NOT NULL DEFAULT gen_random_uuid(),
    id_estacion     UUID NOT NULL,
    tipo_alerta     VARCHAR(255) NOT NULL,
    descripcion     TEXT NOT NULL,
    estado          VARCHAR(255) NOT NULL DEFAULT 'abierta',
    fecha_creacion  TIMESTAMP(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_cierre    TIMESTAMP(0),
    id_bus          UUID,
    CONSTRAINT alertas_sistema_pkey PRIMARY KEY (id_alerta),
    CONSTRAINT fk_alertas_bus FOREIGN KEY (id_bus) REFERENCES buses(id_bus) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_alertas_estacion FOREIGN KEY (id_estacion) REFERENCES estaciones(id_estacion) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT alertas_sistema_estado_check CHECK (estado IN ('abierta', 'atendida', 'cerrada')),
    CONSTRAINT alertas_sistema_tipo_alerta_check CHECK (tipo_alerta IN ('refuerzo_bus', 'bus_lleno', 'bahia_ocupada', 'espera_minima', 'otro'))
);
CREATE INDEX idx_alertas_estado ON alertas_sistema (estado);

-- ---------------------------------------------------------------------
-- 19. TRANSACCIONES_PAGO
--    Punto 18: pago de cada pasajero en el molinete de entrada del bus (sin efectivo).
-- ---------------------------------------------------------------------
CREATE TABLE transacciones_pago (
    id_transaccion  UUID NOT NULL DEFAULT gen_random_uuid(),
    id_molinete     UUID NOT NULL,
    id_tarjeta      UUID,
    monto           DECIMAL(8,2) NOT NULL,
    metodo_pago     VARCHAR(255) NOT NULL,
    fecha_hora      TIMESTAMP(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    id_linea        UUID,
    viaje_gratis    BOOLEAN NOT NULL DEFAULT false,
    id_bus          UUID,
    id_estacion     UUID,
    id_visita       UUID,
    CONSTRAINT transacciones_pago_pkey PRIMARY KEY (id_transaccion),
    CONSTRAINT fk_transacciones_bus FOREIGN KEY (id_bus) REFERENCES buses(id_bus) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_transacciones_estacion FOREIGN KEY (id_estacion) REFERENCES estaciones(id_estacion) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_transacciones_linea FOREIGN KEY (id_linea) REFERENCES lineas(id_linea) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_transacciones_molinete FOREIGN KEY (id_molinete) REFERENCES molinetes(id_molinete) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_transacciones_tarjeta FOREIGN KEY (id_tarjeta) REFERENCES tarjetas(id_tarjeta) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_transacciones_visita FOREIGN KEY (id_visita) REFERENCES visitas_viaje(id_visita) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT chk_transacciones_monto CHECK (((monto >= (0)) AND (viaje_gratis OR (monto > (0))))),
    CONSTRAINT chk_transacciones_tarjeta CHECK ((((metodo_pago) <> 'tarjeta_ciudadana') OR (id_tarjeta IS NOT NULL))),
    CONSTRAINT transacciones_pago_metodo_pago_check CHECK (metodo_pago IN ('tarjeta_ciudadana', 'tarjeta_bancaria'))
);
CREATE INDEX idx_transacciones_bus ON transacciones_pago (id_bus, fecha_hora);
CREATE INDEX idx_transacciones_estacion ON transacciones_pago (id_estacion, fecha_hora);
CREATE INDEX idx_transacciones_fecha ON transacciones_pago (fecha_hora);
CREATE INDEX idx_transacciones_linea ON transacciones_pago (id_linea);
CREATE INDEX idx_transacciones_linea_fecha ON transacciones_pago (id_linea, fecha_hora);
CREATE INDEX idx_transacciones_tarjeta ON transacciones_pago (id_tarjeta);
CREATE INDEX idx_transacciones_visita ON transacciones_pago (id_visita);

-- ---------------------------------------------------------------------
-- 20. BAJADAS_BUS
--    Conteo de las personas que bajan por el molinete de salida del bus.
-- ---------------------------------------------------------------------
CREATE TABLE bajadas_bus (
    id_bajada    UUID NOT NULL DEFAULT gen_random_uuid(),
    id_molinete  UUID NOT NULL,
    id_bus       UUID,
    id_estacion  UUID,
    id_visita    UUID,
    cantidad     INTEGER NOT NULL DEFAULT 1,
    fecha_hora   TIMESTAMP(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT bajadas_bus_pkey PRIMARY KEY (id_bajada),
    CONSTRAINT fk_bajadas_bus FOREIGN KEY (id_bus) REFERENCES buses(id_bus) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_bajadas_estacion FOREIGN KEY (id_estacion) REFERENCES estaciones(id_estacion) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_bajadas_molinete FOREIGN KEY (id_molinete) REFERENCES molinetes(id_molinete) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_bajadas_visita FOREIGN KEY (id_visita) REFERENCES visitas_viaje(id_visita) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT chk_bajadas_cantidad CHECK ((cantidad >= 1))
);
CREATE INDEX idx_bajadas_bus ON bajadas_bus (id_bus, fecha_hora);
CREATE INDEX idx_bajadas_estacion ON bajadas_bus (id_estacion, fecha_hora);
CREATE INDEX idx_bajadas_visita ON bajadas_bus (id_visita);

-- ---------------------------------------------------------------------
-- 21. MANTENIMIENTOS_BUS
--    Fallas reportadas por el operador y mantenimientos del taller.
-- ---------------------------------------------------------------------
CREATE TABLE mantenimientos_bus (
    id_mantenimiento     UUID NOT NULL DEFAULT gen_random_uuid(),
    id_bus               UUID NOT NULL,
    id_empleado_reporta  UUID,
    tipo                 VARCHAR(255) NOT NULL,
    categoria            VARCHAR(30) NOT NULL,
    descripcion          TEXT NOT NULL,
    prioridad            VARCHAR(255) NOT NULL DEFAULT 'media',
    estado               VARCHAR(255) NOT NULL DEFAULT 'reportado',
    kilometraje          INTEGER,
    fecha_reporte        TIMESTAMP(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_cierre         TIMESTAMP(0),
    CONSTRAINT mantenimientos_bus_pkey PRIMARY KEY (id_mantenimiento),
    CONSTRAINT fk_mantenimientos_bus FOREIGN KEY (id_bus) REFERENCES buses(id_bus) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_mantenimientos_empleado FOREIGN KEY (id_empleado_reporta) REFERENCES empleados(id_empleado) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT chk_mantenimientos_cierre CHECK ((((estado) = 'resuelto') = (fecha_cierre IS NOT NULL))),
    CONSTRAINT chk_mantenimientos_km CHECK (((kilometraje IS NULL) OR (kilometraje >= 0))),
    CONSTRAINT mantenimientos_bus_estado_check CHECK (estado IN ('reportado', 'en_proceso', 'resuelto')),
    CONSTRAINT mantenimientos_bus_prioridad_check CHECK (prioridad IN ('baja', 'media', 'alta')),
    CONSTRAINT mantenimientos_bus_tipo_check CHECK (tipo IN ('falla', 'preventivo', 'correctivo'))
);
CREATE INDEX idx_mantenimientos_bus ON mantenimientos_bus (id_bus, fecha_reporte);

-- ---------------------------------------------------------------------
-- 22. BITACORA_AUDITORIA
--    Auditoria: usuario, tabla, registro, accion, datos anteriores y posteriores.
-- ---------------------------------------------------------------------
CREATE TABLE bitacora_auditoria (
    id_bitacora           UUID NOT NULL DEFAULT gen_random_uuid(),
    id_usuario            BIGINT,
    id_empleado_usuario   UUID,
    tabla_afectada        VARCHAR(100) NOT NULL,
    id_registro_afectado  VARCHAR(36),
    accion                VARCHAR(255) NOT NULL,
    datos_previos         JSONB,
    datos_nuevos          JSONB,
    fecha_registro        TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL DEFAULT clock_timestamp(),
    CONSTRAINT bitacora_auditoria_pkey PRIMARY KEY (id_bitacora),
    CONSTRAINT fk_bitacora_empleado FOREIGN KEY (id_empleado_usuario) REFERENCES empleados(id_empleado) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_bitacora_usuario FOREIGN KEY (id_usuario) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT bitacora_auditoria_accion_check CHECK (accion IN ('INSERT', 'UPDATE', 'DELETE'))
);
CREATE INDEX idx_bitacora_fecha ON bitacora_auditoria (fecha_registro DESC);
CREATE INDEX idx_bitacora_tabla ON bitacora_auditoria (tabla_afectada, id_registro_afectado);

-- =====================================================================
-- USUARIOS DEL SISTEMA (tabla users de Laravel): rol y empleado vinculado
-- =====================================================================
ALTER TABLE users
    ADD COLUMN rol VARCHAR(255) NOT NULL DEFAULT 'operador',
    ADD COLUMN id_empleado UUID NULL,
    ADD CONSTRAINT fk_users_empleado FOREIGN KEY (id_empleado) REFERENCES empleados(id_empleado) ON UPDATE CASCADE ON DELETE SET NULL,
    ADD CONSTRAINT uq_users_empleado UNIQUE (id_empleado),
    ADD CONSTRAINT users_rol_check CHECK (rol IN ('administrador', 'operador'));

-- =====================================================================
-- REGLAS DE NEGOCIO: funciones, triggers y procedimiento almacenado
-- =====================================================================
CREATE OR REPLACE PROCEDURE sp_cambiar_parqueo_bus(IN p_id_bus uuid, IN p_id_parqueo_nuevo uuid)
 LANGUAGE plpgsql
AS $procedure$
DECLARE
    v_parqueo_actual UUID;
    v_ocupado UUID;
BEGIN
    SELECT id_bus_asignado INTO v_ocupado
      FROM parqueos WHERE id_parqueo = p_id_parqueo_nuevo
       FOR UPDATE;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'El parqueo destino no existe.';
    END IF;
    IF v_ocupado = p_id_bus THEN
        RETURN;
    END IF;
    IF v_ocupado IS NOT NULL THEN
        RAISE EXCEPTION 'El parqueo destino ya esta ocupado por otro bus.';
    END IF;

    SELECT id_parqueo INTO v_parqueo_actual
      FROM parqueos WHERE id_bus_asignado = p_id_bus
       FOR UPDATE;
    IF v_parqueo_actual IS NOT NULL THEN
        UPDATE parqueos SET id_bus_asignado = NULL WHERE id_parqueo = v_parqueo_actual;
    END IF;
    UPDATE parqueos SET id_bus_asignado = p_id_bus WHERE id_parqueo = p_id_parqueo_nuevo;
END;
$procedure$;

CREATE OR REPLACE FUNCTION fn_accesos_min_dos()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$
DECLARE
    v_conteo INT;
BEGIN
    SELECT COUNT(*) INTO v_conteo FROM accesos WHERE id_estacion = OLD.id_estacion;
    IF v_conteo <= 2 THEN
        RAISE EXCEPTION 'No se puede eliminar: la estacion debe conservar al menos 2 accesos.';
    END IF;
    RETURN OLD;
END;
$function$;

CREATE OR REPLACE FUNCTION fn_bajadas_en_bus()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$
DECLARE
    v_tipo VARCHAR;
    v_bus UUID;
    v_actual RECORD;
BEGIN
    SELECT tipo, id_bus INTO v_tipo, v_bus FROM molinetes WHERE id_molinete = NEW.id_molinete;
    IF v_tipo IS DISTINCT FROM 'salida_bus' THEN
        RAISE EXCEPTION 'Las bajadas se cuentan en el molinete de salida del bus.';
    END IF;
    NEW.id_bus := v_bus;
    -- Si no se indica la estación (lo normal en el molinete real), es donde está detenido el bus.
    IF NEW.id_visita IS NULL AND NEW.id_estacion IS NULL THEN
        SELECT * INTO v_actual FROM fn_visita_actual_bus(v_bus);
        IF v_actual.id_visita IS NULL THEN
            RAISE EXCEPTION 'El bus no esta detenido en una estacion: no tiene un viaje en curso.';
        END IF;
        NEW.id_visita := v_actual.id_visita;
        NEW.id_estacion := COALESCE(NEW.id_estacion, v_actual.id_estacion);
    END IF;
    RETURN NEW;
END;
$function$;

CREATE OR REPLACE FUNCTION fn_bajadas_restan()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$
DECLARE
    v_placa VARCHAR;
BEGIN
    SELECT b.placa INTO v_placa
      FROM (SELECT id_bus, SUM(cantidad) AS n FROM nuevas GROUP BY id_bus) s
      JOIN buses b ON b.id_bus = s.id_bus
     WHERE s.n > b.pasajeros_a_bordo
     LIMIT 1;
    IF v_placa IS NOT NULL THEN
        RAISE EXCEPTION 'En el bus % el contador de salida marca mas bajadas que pasajeros a bordo.', v_placa;
    END IF;

    UPDATE buses b SET pasajeros_a_bordo = b.pasajeros_a_bordo - s.n
      FROM (SELECT id_bus, SUM(cantidad) AS n FROM nuevas GROUP BY id_bus) s
     WHERE b.id_bus = s.id_bus;
    UPDATE visitas_viaje vv SET bajaron = vv.bajaron + s.n
      FROM (SELECT id_visita, SUM(cantidad) AS n FROM nuevas WHERE id_visita IS NOT NULL GROUP BY id_visita) s
     WHERE vv.id_visita = s.id_visita;
    RETURN NULL;
END;
$function$;

CREATE OR REPLACE FUNCTION fn_bitacora_inmutable()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$
    BEGIN
        IF TG_OP = 'DELETE' THEN
            RAISE EXCEPTION 'La bitácora de auditoría no se puede eliminar.' USING ERRCODE = 'P0001';
        END IF;
        IF NEW.id_bitacora IS DISTINCT FROM OLD.id_bitacora
           OR NEW.tabla_afectada IS DISTINCT FROM OLD.tabla_afectada
           OR NEW.id_registro_afectado IS DISTINCT FROM OLD.id_registro_afectado
           OR NEW.accion IS DISTINCT FROM OLD.accion
           OR NEW.datos_previos IS DISTINCT FROM OLD.datos_previos
           OR NEW.datos_nuevos IS DISTINCT FROM OLD.datos_nuevos
           OR NEW.fecha_registro IS DISTINCT FROM OLD.fecha_registro
           OR (NEW.id_usuario IS DISTINCT FROM OLD.id_usuario AND NEW.id_usuario IS NOT NULL)
           OR (NEW.id_empleado_usuario IS DISTINCT FROM OLD.id_empleado_usuario AND NEW.id_empleado_usuario IS NOT NULL) THEN
            RAISE EXCEPTION 'La bitácora de auditoría no se puede modificar.' USING ERRCODE = 'P0001';
        END IF;
        RETURN NEW;
    END;
    $function$;

CREATE OR REPLACE FUNCTION fn_buses_max_flota()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$
DECLARE
    v_buses_actuales INT;
    v_paradas INT;
BEGIN
    IF NEW.id_linea_asignada IS NOT NULL
       AND (TG_OP = 'INSERT' OR NEW.id_linea_asignada IS DISTINCT FROM OLD.id_linea_asignada) THEN
        SELECT COUNT(*) INTO v_buses_actuales FROM buses WHERE id_linea_asignada = NEW.id_linea_asignada;
        SELECT COUNT(*) INTO v_paradas FROM linea_estacion WHERE id_linea = NEW.id_linea_asignada;
        IF v_buses_actuales >= 2 * v_paradas THEN
            RAISE EXCEPTION 'No se puede asignar el bus: se alcanzo el maximo permitido (2x el numero de paradas) para esta linea.';
        END IF;
    END IF;
    RETURN NEW;
END;
$function$;

CREATE OR REPLACE FUNCTION fn_buses_molinetes()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$
DECLARE
    v_n INT;
BEGIN
    SELECT COUNT(*) INTO v_n FROM buses;
    INSERT INTO molinetes (id_bus, tipo, ip_address, estado_operativo) VALUES
        (NEW.id_bus, 'entrada_bus', '10.20.' || v_n || '.11', 'activo'),
        (NEW.id_bus, 'salida_bus',  '10.20.' || v_n || '.12', 'activo')
    ON CONFLICT (id_bus, tipo) DO NOTHING;
    RETURN NULL;
END;
$function$;

CREATE OR REPLACE FUNCTION fn_buses_ocupacion()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$
BEGIN
    NEW.ocupacion_actual_pct := ROUND(NEW.pasajeros_a_bordo * 100.0 / GREATEST(NEW.capacidad_maxima, 1), 2);
    RETURN NEW;
END;
$function$;

CREATE OR REPLACE FUNCTION fn_buses_ruta_con_piloto()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$
BEGIN
    IF NEW.estado_logico = 'en_ruta'
       AND (TG_OP = 'INSERT' OR OLD.estado_logico IS DISTINCT FROM 'en_ruta')
       AND NOT EXISTS (SELECT 1 FROM pilotos WHERE id_bus_asignado = NEW.id_bus) THEN
        RAISE EXCEPTION 'El bus % no tiene piloto asignado: no puede salir a ruta.', NEW.placa;
    END IF;
    RETURN NEW;
END;
$function$;

CREATE OR REPLACE FUNCTION fn_estaciones_alerta_aforo()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$
BEGIN
    IF NEW.aforo_actual >= 1.5 * NEW.aforo_maximo
       AND OLD.aforo_actual < 1.5 * OLD.aforo_maximo THEN
        INSERT INTO alertas_sistema (id_estacion, tipo_alerta, descripcion, estado)
        VALUES (
            NEW.id_estacion,
            'refuerzo_bus',
            'La estacion supero el 150% de su aforo maximo (' || NEW.aforo_actual || '/' || NEW.aforo_maximo
                || '). Se requiere unidad de refuerzo.',
            'abierta'
        );
    END IF;
    RETURN NEW;
END;
$function$;

CREATE OR REPLACE FUNCTION fn_linea_estacion_distancia()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$
BEGIN
    IF TG_OP IN ('UPDATE', 'DELETE') THEN
        UPDATE lineas
           SET distancia_total_km = COALESCE((SELECT SUM(distancia_previa_km) FROM linea_estacion WHERE id_linea = OLD.id_linea), 0)
         WHERE id_linea = OLD.id_linea;
    END IF;
    IF TG_OP IN ('INSERT', 'UPDATE') THEN
        UPDATE lineas
           SET distancia_total_km = COALESCE((SELECT SUM(distancia_previa_km) FROM linea_estacion WHERE id_linea = NEW.id_linea), 0)
         WHERE id_linea = NEW.id_linea;
    END IF;
    RETURN NULL;
END;
$function$;

CREATE OR REPLACE FUNCTION fn_pilotos_deja_bus()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$
BEGIN
    IF OLD.id_bus_asignado IS NOT NULL
       AND (TG_OP = 'DELETE' OR NEW.id_bus_asignado IS DISTINCT FROM OLD.id_bus_asignado)
       AND NOT EXISTS (SELECT 1 FROM pilotos WHERE id_bus_asignado = OLD.id_bus_asignado) THEN
        UPDATE viajes SET estado = 'finalizado', fecha_hora_fin = (now() AT TIME ZONE 'America/Guatemala')::timestamp(0)
         WHERE id_bus = OLD.id_bus_asignado AND estado = 'en_curso';
        UPDATE buses SET estado_logico = 'en_espera', pasajeros_a_bordo = 0
         WHERE id_bus = OLD.id_bus_asignado AND estado_logico = 'en_ruta';
    END IF;
    RETURN NULL;
END;
$function$;

CREATE OR REPLACE FUNCTION fn_seguridad_min_uno()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$
DECLARE
    v_conteo INT;
BEGIN
    IF OLD.id_acceso_asignado IS NOT NULL
       AND (TG_OP = 'DELETE' OR NEW.id_acceso_asignado IS DISTINCT FROM OLD.id_acceso_asignado)
       AND EXISTS (SELECT 1 FROM accesos WHERE id_acceso = OLD.id_acceso_asignado) THEN
        SELECT COUNT(*) INTO v_conteo FROM seguridad WHERE id_acceso_asignado = OLD.id_acceso_asignado;
        IF v_conteo <= 1 THEN
            RAISE EXCEPTION 'No se puede reasignar ni dar de baja: el acceso quedaria sin personal de seguridad.';
        END IF;
    END IF;
    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;
    RETURN NEW;
END;
$function$;

CREATE OR REPLACE FUNCTION fn_tarjeta_activa()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$
DECLARE
    v_estado VARCHAR;
    v_numero VARCHAR;
BEGIN
    IF NEW.id_tarjeta IS NULL THEN
        RETURN NEW;
    END IF;
    SELECT estado, numero_tarjeta INTO v_estado, v_numero FROM tarjetas WHERE id_tarjeta = NEW.id_tarjeta;
    IF v_estado IS DISTINCT FROM 'activa' THEN
        RAISE EXCEPTION 'La tarjeta % no está activa (%): no se puede usar.', v_numero, v_estado;
    END IF;
    RETURN NEW;
END;
$function$;

CREATE OR REPLACE FUNCTION fn_tarjetas_estado_final()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$
BEGIN
    IF NEW.estado IS DISTINCT FROM OLD.estado THEN
        IF OLD.estado IN ('baja', 'reemplazada') THEN
            RAISE EXCEPTION 'La tarjeta % está %: su estado ya no se puede cambiar.', OLD.numero_tarjeta,
                CASE OLD.estado WHEN 'baja' THEN 'dada de baja' ELSE 'reemplazada' END;
        END IF;
        IF NEW.fecha_estado IS NOT DISTINCT FROM OLD.fecha_estado THEN
            NEW.fecha_estado := now()::timestamp(0);
        END IF;
    END IF;
    RETURN NEW;
END;
$function$;

CREATE OR REPLACE FUNCTION fn_transacciones_en_bus()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$
DECLARE
    v_tipo VARCHAR;
    v_bus UUID;
    v_actual RECORD;
BEGIN
    SELECT tipo, id_bus INTO v_tipo, v_bus FROM molinetes WHERE id_molinete = NEW.id_molinete;
    IF v_tipo IS DISTINCT FROM 'entrada_bus' THEN
        RAISE EXCEPTION 'El pasaje se cobra en el molinete de entrada del bus.';
    END IF;
    NEW.id_bus := v_bus;
    -- Si no se indica la estación (lo normal en el molinete real), es donde está detenido el bus.
    IF NEW.id_visita IS NULL AND NEW.id_estacion IS NULL THEN
        SELECT * INTO v_actual FROM fn_visita_actual_bus(v_bus);
        IF v_actual.id_visita IS NULL THEN
            RAISE EXCEPTION 'El bus no esta detenido en una estacion: no tiene un viaje en curso.';
        END IF;
        NEW.id_visita := v_actual.id_visita;
        NEW.id_estacion := COALESCE(NEW.id_estacion, v_actual.id_estacion);
        NEW.id_linea := COALESCE(NEW.id_linea, v_actual.id_linea);
    END IF;
    RETURN NEW;
END;
$function$;

CREATE OR REPLACE FUNCTION fn_transacciones_suben()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$
DECLARE
    v_placa VARCHAR;
BEGIN
    SELECT b.placa INTO v_placa
      FROM (SELECT id_bus, COUNT(*) AS n FROM nuevos WHERE id_bus IS NOT NULL GROUP BY id_bus) s
      JOIN buses b ON b.id_bus = s.id_bus
     WHERE b.pasajeros_a_bordo + s.n > b.capacidad_maxima
     LIMIT 1;
    IF v_placa IS NOT NULL THEN
        RAISE EXCEPTION 'El bus % esta lleno: el molinete de entrada no deja subir mas pasajeros.', v_placa;
    END IF;

    UPDATE estaciones e SET aforo_actual = GREATEST(0, e.aforo_actual - s.n)
      FROM (SELECT id_estacion, COUNT(*) AS n FROM nuevos WHERE id_bus IS NOT NULL AND id_estacion IS NOT NULL GROUP BY id_estacion) s
     WHERE e.id_estacion = s.id_estacion;
    UPDATE buses b SET pasajeros_a_bordo = b.pasajeros_a_bordo + s.n
      FROM (SELECT id_bus, COUNT(*) AS n FROM nuevos WHERE id_bus IS NOT NULL GROUP BY id_bus) s
     WHERE b.id_bus = s.id_bus;
    UPDATE visitas_viaje vv SET subieron = vv.subieron + s.n
      FROM (SELECT id_visita, COUNT(*) AS n FROM nuevos WHERE id_visita IS NOT NULL GROUP BY id_visita) s
     WHERE vv.id_visita = s.id_visita;
    RETURN NULL;
END;
$function$;

CREATE OR REPLACE FUNCTION fn_visita_actual_bus(p_id_bus uuid, OUT id_visita uuid, OUT id_estacion uuid, OUT id_linea uuid)
 RETURNS record
 LANGUAGE sql
 STABLE
AS $function$
    SELECT vv.id_visita, vv.id_estacion, v.id_linea
      FROM viajes v
      JOIN visitas_viaje vv ON vv.id_viaje = v.id_viaje
     WHERE v.id_bus = p_id_bus AND v.estado = 'en_curso'
     ORDER BY vv.orden_visita DESC
     LIMIT 1
$function$;

CREATE TRIGGER trg_accesos_min_dos_before_delete BEFORE DELETE ON accesos FOR EACH ROW EXECUTE FUNCTION fn_accesos_min_dos();
CREATE TRIGGER trg_bajadas_en_bus BEFORE INSERT ON bajadas_bus FOR EACH ROW EXECUTE FUNCTION fn_bajadas_en_bus();
CREATE TRIGGER trg_bajadas_restan AFTER INSERT ON bajadas_bus REFERENCING NEW TABLE AS nuevas FOR EACH STATEMENT EXECUTE FUNCTION fn_bajadas_restan();
CREATE TRIGGER trg_bitacora_inmutable BEFORE DELETE OR UPDATE ON bitacora_auditoria FOR EACH ROW EXECUTE FUNCTION fn_bitacora_inmutable();
CREATE TRIGGER trg_buses_max_flota BEFORE INSERT OR UPDATE OF id_linea_asignada ON buses FOR EACH ROW EXECUTE FUNCTION fn_buses_max_flota();
CREATE TRIGGER trg_buses_molinetes AFTER INSERT ON buses FOR EACH ROW EXECUTE FUNCTION fn_buses_molinetes();
CREATE TRIGGER trg_buses_ocupacion BEFORE INSERT OR UPDATE ON buses FOR EACH ROW EXECUTE FUNCTION fn_buses_ocupacion();
CREATE TRIGGER trg_buses_ruta_con_piloto BEFORE INSERT OR UPDATE OF estado_logico ON buses FOR EACH ROW EXECUTE FUNCTION fn_buses_ruta_con_piloto();
CREATE TRIGGER trg_estaciones_alerta_aforo AFTER UPDATE OF aforo_actual, aforo_maximo ON estaciones FOR EACH ROW EXECUTE FUNCTION fn_estaciones_alerta_aforo();
CREATE TRIGGER trg_linea_estacion_distancia AFTER INSERT OR DELETE OR UPDATE ON linea_estacion FOR EACH ROW EXECUTE FUNCTION fn_linea_estacion_distancia();
CREATE TRIGGER trg_pilotos_deja_bus AFTER DELETE OR UPDATE OF id_bus_asignado ON pilotos FOR EACH ROW EXECUTE FUNCTION fn_pilotos_deja_bus();
CREATE TRIGGER trg_recargas_tarjeta_activa BEFORE INSERT ON recargas FOR EACH ROW EXECUTE FUNCTION fn_tarjeta_activa();
CREATE TRIGGER trg_seguridad_min_uno BEFORE DELETE OR UPDATE ON seguridad FOR EACH ROW EXECUTE FUNCTION fn_seguridad_min_uno();
CREATE TRIGGER trg_tarjetas_estado_final BEFORE UPDATE OF estado ON tarjetas FOR EACH ROW EXECUTE FUNCTION fn_tarjetas_estado_final();
CREATE TRIGGER trg_transacciones_en_bus BEFORE INSERT ON transacciones_pago FOR EACH ROW EXECUTE FUNCTION fn_transacciones_en_bus();
CREATE TRIGGER trg_transacciones_suben AFTER INSERT ON transacciones_pago REFERENCING NEW TABLE AS nuevos FOR EACH STATEMENT EXECUTE FUNCTION fn_transacciones_suben();
CREATE TRIGGER trg_transacciones_tarjeta_activa BEFORE INSERT ON transacciones_pago FOR EACH ROW EXECUTE FUNCTION fn_tarjeta_activa();

-- =====================================================================
-- SEGURIDAD: Row Level Security en todas las tablas del sistema
-- =====================================================================
ALTER TABLE municipios ENABLE ROW LEVEL SECURITY;
ALTER TABLE lineas ENABLE ROW LEVEL SECURITY;
ALTER TABLE horarios_linea ENABLE ROW LEVEL SECURITY;
ALTER TABLE estaciones ENABLE ROW LEVEL SECURITY;
ALTER TABLE linea_estacion ENABLE ROW LEVEL SECURITY;
ALTER TABLE buses ENABLE ROW LEVEL SECURITY;
ALTER TABLE accesos ENABLE ROW LEVEL SECURITY;
ALTER TABLE molinetes ENABLE ROW LEVEL SECURITY;
ALTER TABLE parqueos ENABLE ROW LEVEL SECURITY;
ALTER TABLE empleados ENABLE ROW LEVEL SECURITY;
ALTER TABLE pilotos ENABLE ROW LEVEL SECURITY;
ALTER TABLE seguridad ENABLE ROW LEVEL SECURITY;
ALTER TABLE operadores ENABLE ROW LEVEL SECURITY;
ALTER TABLE tarjetas ENABLE ROW LEVEL SECURITY;
ALTER TABLE recargas ENABLE ROW LEVEL SECURITY;
ALTER TABLE viajes ENABLE ROW LEVEL SECURITY;
ALTER TABLE visitas_viaje ENABLE ROW LEVEL SECURITY;
ALTER TABLE alertas_sistema ENABLE ROW LEVEL SECURITY;
ALTER TABLE transacciones_pago ENABLE ROW LEVEL SECURITY;
ALTER TABLE bajadas_bus ENABLE ROW LEVEL SECURITY;
ALTER TABLE mantenimientos_bus ENABLE ROW LEVEL SECURITY;
ALTER TABLE bitacora_auditoria ENABLE ROW LEVEL SECURITY;
ALTER TABLE users ENABLE ROW LEVEL SECURITY;
