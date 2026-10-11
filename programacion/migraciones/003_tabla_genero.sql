-- Los géneros con los que se clasifican las obras (E2, modelo de clases de programación:
-- GeneroObra). Son una lista aparte para que el catálogo filtre por género sin depender de cómo
-- lo escribió cada dueño.
CREATE TABLE genero (
    id CHAR(36) CHARACTER SET ascii NOT NULL,
    nombre VARCHAR(50) NOT NULL,
    descripcion VARCHAR(500) NOT NULL,
    PRIMARY KEY (id),
    -- Con la intercalación por defecto, «Drama» y «drama» o «Acción» y «Accion» son el mismo.
    UNIQUE INDEX genero_nombre_unico (nombre)
) ENGINE = InnoDB;
