-- Los géneros de cada obra (E2, modelo de clases de programación: Obra «*» -- «1..*» GeneroObra).
--
-- Que toda obra tenga al menos uno lo garantiza la aplicación, que guarda la obra y sus géneros
-- en la misma transacción.
CREATE TABLE obra_genero (
    obra_id CHAR(36) CHARACTER SET ascii NOT NULL,
    genero_id CHAR(36) CHARACTER SET ascii NOT NULL,
    -- El mismo género no se asigna dos veces a una obra.
    PRIMARY KEY (obra_id, genero_id),
    -- Las obras de un género, para filtrar el catálogo.
    INDEX obra_genero_por_genero (genero_id),
    CONSTRAINT obra_genero_de_obra FOREIGN KEY (obra_id) REFERENCES obra (id) ON DELETE CASCADE,
    -- Un género que alguna obra usa no se puede borrar.
    CONSTRAINT obra_genero_de_genero FOREIGN KEY (genero_id) REFERENCES genero (id)
) ENGINE = InnoDB;
