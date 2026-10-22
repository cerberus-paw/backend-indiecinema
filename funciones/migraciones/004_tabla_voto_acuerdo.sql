-- Los votos de los espectadores por una franja horaria («Me sirve»).
--
-- Clave única por usuario y función (E3-13.1 / IC-153): garantiza que cada espectador
-- tenga como máximo un voto por función. El usuario puede cambiar su voto mientras el acuerdo
-- esté abierto (modificando franja_id).
--
-- Se asocia por clave foránea al acuerdo y a la franja con borrado en cascada.
CREATE TABLE voto_acuerdo (
    id CHAR(36) CHARACTER SET ascii NOT NULL,
    acuerdo_id CHAR(36) CHARACTER SET ascii NOT NULL,
    funcion_id CHAR(36) CHARACTER SET ascii NOT NULL,
    franja_id CHAR(36) CHARACTER SET ascii NOT NULL,
    usuario_id CHAR(36) CHARACTER SET ascii NOT NULL,
    creado_en DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE INDEX voto_acuerdo_por_usuario_y_funcion (funcion_id, usuario_id),
    UNIQUE INDEX voto_acuerdo_por_usuario_y_acuerdo (acuerdo_id, usuario_id),
    INDEX voto_por_franja (franja_id),
    CONSTRAINT fk_voto_acuerdo FOREIGN KEY (acuerdo_id) REFERENCES acuerdo_fecha (id) ON DELETE CASCADE,
    CONSTRAINT fk_voto_franja FOREIGN KEY (franja_id) REFERENCES acuerdo_franja (id) ON DELETE CASCADE
) ENGINE = InnoDB;
