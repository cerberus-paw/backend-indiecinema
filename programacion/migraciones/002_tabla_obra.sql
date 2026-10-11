-- La obra del catálogo (E2, modelo de clases de programación: Obra), una película o un
-- cortometraje que se puede proyectar en una función.
--
-- dueno_id es el id en cuentas del usuario dueño de la copia, sin clave foránea: cada subsistema
-- sólo ve su esquema, así que la relación la garantiza la aplicación, igual que el organizador de
-- la sala. Es quien cobra su parte en el reparto de lo recaudado.
--
-- tipo y origen_copia son los valores de los enums TipoObra y OrigenCopia de la aplicación. Los
-- géneros van aparte (obra_genero), porque una obra puede tener varios.
--
-- Las obras no se borran: la baja es lógica (dada_de_baja_en), porque las funciones que ya
-- pasaron siguen apuntando a la obra.
CREATE TABLE obra (
    -- UUID generado por la aplicación: no deja adivinar cuántas obras hay ni recorrerlas por número.
    id CHAR(36) CHARACTER SET ascii NOT NULL,
    dueno_id CHAR(36) CHARACTER SET ascii NOT NULL,
    titulo VARCHAR(150) NOT NULL,
    tipo ENUM('pelicula', 'cortometraje') NOT NULL,
    sinopsis VARCHAR(2000) NOT NULL,
    -- Año de estreno.
    anio SMALLINT UNSIGNED NOT NULL,
    director VARCHAR(100) NOT NULL,
    -- En minutos.
    duracion SMALLINT UNSIGNED NOT NULL,
    origen_copia ENUM('obra_propia', 'dominio_publico', 'licencia_adquirida') NOT NULL,
    -- Nombre al azar del archivo en el volumen de programación, nunca el que mandó el usuario.
    afiche VARCHAR(64) CHARACTER SET ascii NULL,
    creada_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizada_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    dada_de_baja_en DATETIME NULL,
    PRIMARY KEY (id),
    -- Las obras de un dueño.
    INDEX obra_por_dueno (dueno_id),
    -- El catálogo, que muestra sólo las que no se dieron de baja.
    INDEX obra_activa (dada_de_baja_en),
    -- La aplicación ya lo valida; esto cuida la tabla si alguien escribe por otro lado. El tope
    -- del año (el actual) no puede ir en un CHECK, que no admite funciones como CURDATE().
    CONSTRAINT obra_duracion_positiva CHECK (duracion > 0),
    -- La película más antigua que se conserva es de 1888.
    CONSTRAINT obra_anio_valido CHECK (anio >= 1888)
) ENGINE = InnoDB;
