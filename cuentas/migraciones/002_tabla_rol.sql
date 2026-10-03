-- Los roles de cada usuario (E2, modelo de clases de cuentas: Usuario «1» *-- «1..*» Rol).
--
-- Un usuario puede tener varios: al organizador se le suma el rol, no se le cambia el de
-- espectador. Los valores son los de IndieCinema\Nucleo\Seguridad\Rol, salvo visitante, que es
-- quien no tiene cuenta. Que todo usuario tenga al menos uno (nace espectador) lo garantiza el
-- registro, que inserta el usuario y su rol en la misma transacción.
CREATE TABLE rol (
    usuario_id CHAR(36) CHARACTER SET ascii NOT NULL,
    tipo ENUM('espectador', 'organizador', 'moderador', 'administrador') NOT NULL,
    asignado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- El mismo rol no se asigna dos veces.
    PRIMARY KEY (usuario_id, tipo),
    -- Es composición: el rol no existe sin su usuario.
    CONSTRAINT rol_de_usuario FOREIGN KEY (usuario_id) REFERENCES usuario (id) ON DELETE CASCADE
) ENGINE = InnoDB;
