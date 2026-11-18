-- Lo que sigue cada espectador: salas y organizadores (EF-15.4). El seguimiento es de cuentas,
-- porque es del usuario y no de lo seguido: así una sala dada de baja no deja filas colgadas en
-- programación, y la lista de «Mi cuenta» se arma sin preguntarle a nadie más que los nombres.
--
-- Sin clave foránea a lo seguido: las salas viven en otro esquema. Una sala que ya no existe
-- simplemente no vuelve en la lista de nombres y no se muestra.
CREATE TABLE seguimiento (
    usuario_id CHAR(36) CHARACTER SET ascii NOT NULL,
    tipo ENUM('sala', 'organizador') NOT NULL,
    objeto_id CHAR(36) CHARACTER SET ascii NOT NULL,
    creado_en DATETIME NOT NULL,
    -- Seguir dos veces lo mismo no suma una fila: la clave es la terna.
    PRIMARY KEY (usuario_id, tipo, objeto_id),
    CONSTRAINT seguimiento_de_usuario FOREIGN KEY (usuario_id) REFERENCES usuario (id) ON DELETE CASCADE
) ENGINE = InnoDB;
