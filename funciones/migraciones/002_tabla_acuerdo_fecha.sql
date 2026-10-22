-- El acuerdo de fecha con los espectadores (E2, modelo de objetos de funciones: AcuerdoFecha).
--
-- funcion_id no tiene clave foránea: la función es de programación, en su propio esquema,
-- y funciones no tiene acceso al esquema de programación (E2, sección 4).
-- Que exista lo comprueba la aplicación por la API interna antes de guardar.
--
-- El organizador propone las franjas horarias y un mínimo de asistentes.
-- Si la franja ganadora alcanza el mínimo de asistentes, la función pasa a programada con esa fecha.
-- Si no lo alcanza, el acuerdo queda sin mínimo y el organizador decide qué hacer.
CREATE TABLE acuerdo_fecha (
    id CHAR(36) CHARACTER SET ascii NOT NULL,
    funcion_id CHAR(36) CHARACTER SET ascii NOT NULL,
    minimo_asistentes SMALLINT UNSIGNED NOT NULL,
    estado ENUM('abierto', 'confirmado', 'sin_minimo', 'cancelado') NOT NULL DEFAULT 'abierto',
    franja_ganadora_id CHAR(36) CHARACTER SET ascii NULL,
    creado_en DATETIME NOT NULL,
    cerrado_en DATETIME NULL,
    PRIMARY KEY (id),
    INDEX acuerdo_por_funcion (funcion_id, estado),
    INDEX acuerdo_por_estado (estado, creado_en),
    CONSTRAINT acuerdo_minimo_asistentes_positivo CHECK (minimo_asistentes > 0)
) ENGINE = InnoDB;
