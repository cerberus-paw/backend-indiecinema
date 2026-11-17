-- La calificación de conducta que el organizador registra sobre una reserva con asistencia (EF-13.1).
-- A lo sumo una por reserva (PRIMARY KEY reserva_id); si es negativa lleva motivo obligatorio.
CREATE TABLE conducta (
    reserva_id CHAR(36) CHARACTER SET ascii NOT NULL,
    usuario_id CHAR(36) CHARACTER SET ascii NOT NULL,
    organizador_id CHAR(36) CHARACTER SET ascii NOT NULL,
    positiva BOOLEAN NOT NULL,
    motivo VARCHAR(500) NULL,
    creada_en DATETIME NOT NULL,
    PRIMARY KEY (reserva_id),
    INDEX conducta_por_usuario (usuario_id, creada_en),
    CONSTRAINT conducta_de_reserva FOREIGN KEY (reserva_id) REFERENCES reserva (id),
    CONSTRAINT conducta_motivo_en_negativa CHECK (positiva = TRUE OR (motivo IS NOT NULL AND TRIM(motivo) <> ''))
) ENGINE = InnoDB;
