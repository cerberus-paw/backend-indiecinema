-- Bloqueo de un espectador en una sala por su organizador (EF-12 / IC-579, EF-12.1 / IC-580).
-- Quien está bloqueado en una sala no puede votar títulos/franjas ni reservar lugares en sus
-- funciones; en las demás salas sigue operando normal y las reservas ya hechas no se cancelan.
CREATE TABLE bloqueo (
    sala_id CHAR(36) CHARACTER SET ascii NOT NULL,
    usuario_id CHAR(36) CHARACTER SET ascii NOT NULL,
    motivo VARCHAR(500) NOT NULL,
    bloqueado_por CHAR(36) CHARACTER SET ascii NOT NULL,
    bloqueado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (sala_id, usuario_id),
    INDEX bloqueo_por_sala (sala_id, bloqueado_en),
    CONSTRAINT bloqueo_de_sala FOREIGN KEY (sala_id) REFERENCES sala (id),
    CONSTRAINT bloqueo_motivo_no_vacio CHECK (TRIM(motivo) <> '')
) ENGINE = InnoDB;
