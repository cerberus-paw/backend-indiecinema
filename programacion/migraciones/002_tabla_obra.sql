-- La obra cinematográfica del catálogo (E2, modelo de clases de programación: Obra), con los
-- datos que se muestran en el catálogo y la cartelera y se usan para programar funciones.
--
-- tipo sigue la enumeración TipoObra de la E2 (película o cortometraje).
-- duracion es en minutos. anio es el año de estreno.
-- afiche es el nombre al azar del archivo en el volumen de programación (archivos-programacion),
-- igual que la imagen de la sala. Las obras no se borran físicamente: la baja es lógica
-- (dada_de_baja_en) para preservar el historial de funciones y votaciones pasadas.
CREATE TABLE obra (
    -- UUID generado por la aplicación: no deja adivinar cuántas obras hay ni recorrerlas por número.
    id CHAR(36) CHARACTER SET ascii NOT NULL,
    titulo VARCHAR(150) NOT NULL,
    tipo ENUM('pelicula', 'cortometraje') NOT NULL,
    genero VARCHAR(50) NOT NULL,
    -- En minutos.
    duracion SMALLINT UNSIGNED NOT NULL,
    -- Año de estreno de la obra.
    anio SMALLINT UNSIGNED NOT NULL,
    sinopsis VARCHAR(2000) NOT NULL,
    -- Nombre al azar del archivo en el volumen de programación, nunca el que mandó el usuario.
    afiche VARCHAR(64) CHARACTER SET ascii NULL,
    creada_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizada_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    dada_de_baja_en DATETIME NULL,
    PRIMARY KEY (id),
    INDEX obra_por_genero (genero),
    INDEX obra_por_tipo (tipo),
    INDEX obra_activa (dada_de_baja_en),
    -- La aplicación ya lo valida; esto cuida la tabla si alguien escribe por otro lado.
    CONSTRAINT obra_duracion_positiva CHECK (duracion > 0),
    CONSTRAINT obra_anio_valido CHECK (anio >= 1888)
) ENGINE = InnoDB;
