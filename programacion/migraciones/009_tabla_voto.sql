-- Los votos de los espectadores en las funciones en votación de títulos (E2 / E3-12.2).
--
-- Cada espectador tiene como máximo un voto por función: la clave única (usuario_id, funcion_id)
-- impide votar dos veces la misma función. El voto se puede cambiar mientras la votación siga
-- abierta, actualizando la obra elegida.
--
-- Al borrar una función se borran sus votos en cascada (ON DELETE CASCADE). Las obras no se borran
-- (baja lógica): una obra con votos no se puede borrar por error.
CREATE TABLE voto (
    funcion_id CHAR(36) CHARACTER SET ascii NOT NULL,
    usuario_id CHAR(36) CHARACTER SET ascii NOT NULL,
    obra_id CHAR(36) CHARACTER SET ascii NOT NULL,
    votado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (funcion_id, usuario_id),
    -- Clave única por usuario y función (E3-12.2): no se puede votar dos veces la misma función.
    UNIQUE INDEX un_voto_por_usuario_y_funcion (usuario_id, funcion_id),
    -- Los votos que recibió una obra.
    INDEX voto_por_obra (obra_id),
    CONSTRAINT voto_de_funcion FOREIGN KEY (funcion_id) REFERENCES funcion (id) ON DELETE CASCADE,
    CONSTRAINT voto_de_obra FOREIGN KEY (obra_id) REFERENCES obra (id)
) ENGINE = InnoDB;
