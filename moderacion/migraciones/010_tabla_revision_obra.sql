-- Las resoluciones de la moderación sobre las obras (EF-07.7): la obra nueva queda pendiente en
-- programación hasta que un moderador la aprueba o la rechaza con un motivo, que le llega a su
-- dueño. El estado es de programación; acá queda el historial de decisiones: quién, cuándo y por
-- qué, con el título y el dueño al resolverla, para mostrarlo sin preguntarle a programación.
--
-- obra_id y dueno_id son de otros esquemas, sin clave foránea. Una obra puede tener varias filas:
-- la rechazada que se corrige y vuelve a pendiente, o la aprobada que se rechaza después.
CREATE TABLE revision_obra (
    id CHAR(36) CHARACTER SET ascii NOT NULL,
    obra_id CHAR(36) CHARACTER SET ascii NOT NULL,
    dueno_id CHAR(36) CHARACTER SET ascii NOT NULL,
    titulo VARCHAR(150) NOT NULL,
    resolucion ENUM('aprobada', 'rechazada') NOT NULL,
    motivo VARCHAR(1000) NOT NULL,
    moderador_id CHAR(36) CHARACTER SET ascii NOT NULL,
    -- En UTC, como las demás fechas de moderación.
    resuelta_en DATETIME NOT NULL,
    PRIMARY KEY (id),
    -- El historial, primero la última.
    INDEX revision_obra_por_fecha (resuelta_en),
    INDEX revision_obra_por_obra (obra_id, resuelta_en)
) ENGINE = InnoDB;
