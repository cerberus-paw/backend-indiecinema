-- Las obras de cada función (E2, modelo de objetos de programación: Funcion «*» -- «1..*» Obra).
--
-- La función nace con varias obras candidatas, que votan los espectadores; al cerrar la votación,
-- las que se proyectan pasan a elegidas. Que toda función tenga al menos una obra lo garantiza la
-- aplicación, que guarda la función y sus obras en la misma transacción.
CREATE TABLE funcion_obra (
    funcion_id CHAR(36) CHARACTER SET ascii NOT NULL,
    obra_id CHAR(36) CHARACTER SET ascii NOT NULL,
    estado ENUM('candidata', 'elegida') NOT NULL DEFAULT 'candidata',
    -- La misma obra no está dos veces en una función.
    PRIMARY KEY (funcion_id, obra_id),
    -- Las funciones de una obra.
    INDEX funcion_obra_por_obra (obra_id),
    CONSTRAINT funcion_obra_de_funcion FOREIGN KEY (funcion_id) REFERENCES funcion (id) ON DELETE CASCADE,
    -- Sin ON DELETE CASCADE: las obras no se borran (la baja es lógica), y una obra que estuvo en
    -- una función no se puede borrar por error.
    CONSTRAINT funcion_obra_de_obra FOREIGN KEY (obra_id) REFERENCES obra (id)
) ENGINE = InnoDB;
