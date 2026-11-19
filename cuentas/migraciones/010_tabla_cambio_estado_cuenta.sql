-- Quién suspendió o reactivó cada cuenta, cuándo y por qué (EF-14.3). Va en una tabla y no sólo
-- en el log, como los roles: el motivo se muestra en «Usuarios y roles», y al reactivar la cuenta
-- vuelve al estado que tenía (una pendiente sigue teniendo que verificar el correo).
--
-- El id es correlativo para saber cuál fue el último aunque dos caigan en el mismo segundo. autor_id
-- va sin clave foránea: si el administrador se da de baja, el registro queda.
CREATE TABLE cambio_estado_cuenta (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    usuario_id CHAR(36) CHARACTER SET ascii NOT NULL,
    estado_anterior ENUM('pendiente', 'activa', 'suspendida') NOT NULL,
    estado_nuevo ENUM('pendiente', 'activa', 'suspendida') NOT NULL,
    motivo VARCHAR(300) NULL,
    autor_id CHAR(36) CHARACTER SET ascii NOT NULL,
    creado_en DATETIME NOT NULL,
    INDEX cambio_estado_de_usuario (usuario_id, id),
    CONSTRAINT cambio_estado_cuenta_de_usuario FOREIGN KEY (usuario_id) REFERENCES usuario (id) ON DELETE CASCADE
) ENGINE = InnoDB;
