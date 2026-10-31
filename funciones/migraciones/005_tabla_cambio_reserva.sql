-- El historial de cada reserva (E4-03): cada cambio de estado, quién lo hizo y con qué rol. La
-- reserva guarda sólo el estado de ahora; acá queda que el espectador la canceló, que el
-- organizador registró la asistencia en la puerta o que el administrador la anuló.
--
-- autor_id no tiene clave foránea: el usuario es de cuentas, en otro esquema (como en reserva).
-- Cuando se cancela una función, el autor es el organizador que la canceló, y el motivo lo dice.
--
-- Las fechas las pone la aplicación, en UTC.
CREATE TABLE cambio_reserva (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    reserva_id CHAR(36) CHARACTER SET ascii NOT NULL,
    estado_anterior ENUM('confirmada', 'cancelada', 'asistio') NOT NULL,
    estado_nuevo ENUM('confirmada', 'cancelada', 'asistio') NOT NULL,
    autor_id CHAR(36) CHARACTER SET ascii NOT NULL,
    -- Con qué rol lo hizo: el mismo usuario puede ser espectador de una función y organizador de otra.
    autor_rol ENUM('espectador', 'organizador', 'administrador') NOT NULL,
    motivo VARCHAR(200) NULL,
    hecho_en DATETIME NOT NULL,
    PRIMARY KEY (id),
    -- El historial de una reserva, en orden.
    INDEX cambio_reserva_por_reserva (reserva_id, hecho_en),
    CONSTRAINT cambio_reserva_de_una_reserva FOREIGN KEY (reserva_id) REFERENCES reserva (id),
    CONSTRAINT cambio_reserva_cambia_el_estado CHECK (estado_anterior <> estado_nuevo)
) ENGINE = InnoDB;
