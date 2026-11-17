-- Tokens de un solo uso para verificar el correo (24 h) y recuperar la contraseña (1 h).
--
-- El valor real viaja únicamente en el enlace del correo; acá se guarda sólo su SHA-256,
-- igual que las sesiones. Las fechas las escribe la aplicación en UTC.
CREATE TABLE token_de_cuenta (
    huella CHAR(64) CHARACTER SET ascii NOT NULL,
    usuario_id CHAR(36) CHARACTER SET ascii NOT NULL,
    proposito ENUM('verificar', 'recuperar') NOT NULL,
    creado_en DATETIME NOT NULL,
    vence_en DATETIME NOT NULL,
    usado_en DATETIME NULL,
    PRIMARY KEY (huella),
    -- Para invalidar o consultar los tokens pendientes de una cuenta según su propósito.
    INDEX token_por_usuario (usuario_id, proposito),
    CONSTRAINT token_de_usuario FOREIGN KEY (usuario_id) REFERENCES usuario (id) ON DELETE CASCADE
) ENGINE = InnoDB;
