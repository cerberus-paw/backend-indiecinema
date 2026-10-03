-- La cuenta de un usuario de IndieCinema (E2, modelo de clases de cuentas: Usuario).
--
-- El nombre está acá y no en el perfil porque nginx lo manda en X-Usuario-Nombre en cada pedido:
-- así sale de la misma fila que valida la sesión. El resto del perfil (avatar, biografía, salas
-- seguidas) va en su propia tabla cuando llegue esa historia.
CREATE TABLE usuario (
    -- UUID generado por la aplicación: no deja adivinar cuántos usuarios hay ni recorrerlos.
    id CHAR(36) CHARACTER SET ascii NOT NULL,
    nombre VARCHAR(100) NOT NULL,
    -- ASCII, como lo acepta la validación (FILTER_VALIDATE_EMAIL sin la opción de Unicode), y
    -- sin distinguir mayúsculas: Ana@ejemplo.com y ana@ejemplo.com son la misma cuenta. 254 es
    -- el largo máximo de una dirección (RFC 5321).
    correo VARCHAR(254) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
    -- password_hash() con Argon2id (unos 100 caracteres); 255 es lo que pide PHP para que un
    -- cambio de algoritmo o de parámetros no obligue a otra migración.
    contrasena_hash VARCHAR(255) CHARACTER SET ascii NOT NULL,
    -- EstadoCuenta. Hasta que exista el correo de verificación, la cuenta nace activa; después
    -- nace pendiente y pasa a activa al verificar. Suspendida la usa moderación (E4).
    estado ENUM('pendiente', 'activa', 'suspendida') NOT NULL,
    -- Cuándo se verificó el correo; nulo mientras no se verifique.
    correo_verificado_en DATETIME NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- La aplicación avisa si el correo ya está registrado, pero entre la consulta y el INSERT
    -- puede colarse otro registro: la que decide es la base.
    UNIQUE KEY usuario_correo_unico (correo)
) ENGINE = InnoDB;
