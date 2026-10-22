-- Las franjas horarias propuestas por el organizador para un acuerdo de fecha.
--
-- acuerdo_id tiene clave foránea con borrado en cascada: si se borra el acuerdo se borran
-- sus franjas.
CREATE TABLE acuerdo_franja (
    id CHAR(36) CHARACTER SET ascii NOT NULL,
    acuerdo_id CHAR(36) CHARACTER SET ascii NOT NULL,
    fecha_hora_inicio DATETIME NOT NULL,
    fecha_hora_fin DATETIME NULL,
    creada_en DATETIME NOT NULL,
    PRIMARY KEY (id),
    INDEX franja_por_acuerdo (acuerdo_id),
    CONSTRAINT fk_franja_acuerdo FOREIGN KEY (acuerdo_id) REFERENCES acuerdo_fecha (id) ON DELETE CASCADE
) ENGINE = InnoDB;
