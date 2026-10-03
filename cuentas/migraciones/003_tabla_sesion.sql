-- Las sesiones abiertas: una fila por cada inicio de sesión, que dura hasta su vencimiento o
-- hasta que se borra (cerrar sesión, cambio de rol, suspensión).
--
-- El identificador es aleatorio (random_bytes) y viaja en la cookie; acá se guarda sólo su
-- SHA-256, así quien lea la tabla no puede usar las sesiones. Alcanza con SHA-256 y no hace falta
-- un hash lento como el de las contraseñas: son 32 bytes al azar, no hay diccionario que probar.
--
-- Las fechas las pone la aplicación, en UTC: así se comparan con la misma hora con la que se
-- escribieron, sin depender de la zona horaria de MySQL.
CREATE TABLE sesion (
    huella CHAR(64) CHARACTER SET ascii NOT NULL,
    usuario_id CHAR(36) CHARACTER SET ascii NOT NULL,
    creada_en DATETIME NOT NULL,
    usada_en DATETIME NOT NULL,
    vence_en DATETIME NOT NULL,
    PRIMARY KEY (huella),
    -- Para borrar todas las de un usuario.
    INDEX sesion_por_usuario (usuario_id),
    -- Si el usuario se borra, sus sesiones también.
    CONSTRAINT sesion_de_usuario FOREIGN KEY (usuario_id) REFERENCES usuario (id) ON DELETE CASCADE
) ENGINE = InnoDB;
