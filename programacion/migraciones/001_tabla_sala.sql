-- La sala indie de un organizador (E2, modelo de objetos de programación), con los parámetros
-- que usa la programación de funciones.
--
-- organizador_id es el id del usuario en cuentas, sin clave foránea: cada subsistema sólo ve su
-- esquema, así que la relación la garantiza la aplicación (el id llega de nginx, ya validado).
--
-- Hasta que exista moderación (E4), una sala se publica cuando el administrador pone habilitada
-- en verdadero. Las salas no se borran: la baja es lógica, porque las funciones que ya pasaron
-- siguen apuntando a la sala.
CREATE TABLE sala (
    -- UUID generado por la aplicación: no deja adivinar cuántas salas hay ni recorrerlas por número.
    id CHAR(36) CHARACTER SET ascii NOT NULL,
    organizador_id CHAR(36) CHARACTER SET ascii NOT NULL,
    nombre VARCHAR(100) NOT NULL,
    descripcion VARCHAR(2000) NOT NULL,
    direccion VARCHAR(150) NOT NULL,
    localidad VARCHAR(100) NOT NULL,
    capacidad SMALLINT UNSIGNED NOT NULL,
    -- Nombre al azar del archivo en el volumen de programación, nunca el que mandó el usuario.
    imagen VARCHAR(64) CHARACTER SET ascii NULL,
    peliculas_por_funcion TINYINT UNSIGNED NOT NULL,
    -- En minutos.
    duracion_funcion SMALLINT UNSIGNED NOT NULL,
    tiempo_entre_funciones SMALLINT UNSIGNED NOT NULL,
    habilitada BOOLEAN NOT NULL DEFAULT FALSE,
    creada_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizada_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    dada_de_baja_en DATETIME NULL,
    PRIMARY KEY (id),
    -- «Mis salas» del organizador y el listado público.
    INDEX sala_por_organizador (organizador_id),
    INDEX sala_publicada (habilitada, dada_de_baja_en),
    -- La aplicación ya lo valida; esto cuida la tabla si alguien escribe por otro lado.
    CONSTRAINT sala_capacidad_positiva CHECK (capacidad > 0),
    CONSTRAINT sala_peliculas_positivas CHECK (peliculas_por_funcion > 0),
    CONSTRAINT sala_duracion_positiva CHECK (duracion_funcion > 0)
) ENGINE = InnoDB;
