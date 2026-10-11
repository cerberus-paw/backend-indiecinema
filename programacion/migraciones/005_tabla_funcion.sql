-- La función de una sala (E2, modelo de objetos de programación: Funcion), con el precio base de
-- la entrada, el cupo y en qué parte de su ciclo de vida está. Las obras van aparte
-- (funcion_obra), porque son varias.
--
-- estado es el valor del enum EstadoFuncion de la aplicación. fecha_hora queda nula mientras la
-- función está en votación de títulos o en acuerdo de fecha: se completa al programarla.
--
-- Que el cupo no supere la capacidad de la sala y que la fecha sea futura lo garantiza la
-- aplicación (Sala::crearFuncion() y Funcion::fijarFechaHora()): un CHECK no puede leer otra
-- tabla ni usar NOW().
CREATE TABLE funcion (
    -- UUID generado por la aplicación: no deja adivinar cuántas funciones hay ni recorrerlas por número.
    id CHAR(36) CHARACTER SET ascii NOT NULL,
    sala_id CHAR(36) CHARACTER SET ascii NOT NULL,
    estado ENUM('en_votacion_titulos', 'en_acuerdo_fecha', 'programada', 'cancelada', 'finalizada') NOT NULL DEFAULT 'en_votacion_titulos',
    fecha_hora DATETIME NULL,
    -- DECIMAL y no FLOAT, para no arrastrar redondeos a los cobros. 0 es «a la gorra».
    precio_base DECIMAL(10, 2) NOT NULL,
    cupo SMALLINT UNSIGNED NOT NULL,
    creada_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizada_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- Las funciones de una sala.
    INDEX funcion_por_sala (sala_id),
    -- La cartelera: las programadas, por fecha.
    INDEX funcion_por_estado (estado, fecha_hora),
    -- Sin ON DELETE CASCADE: las salas no se borran (la baja es lógica), y una sala con funciones
    -- no se puede borrar por error.
    CONSTRAINT funcion_de_sala FOREIGN KEY (sala_id) REFERENCES sala (id),
    -- La aplicación ya lo valida; esto cuida la tabla si alguien escribe por otro lado.
    CONSTRAINT funcion_cupo_positivo CHECK (cupo > 0),
    CONSTRAINT funcion_precio_no_negativo CHECK (precio_base >= 0),
    -- Una función programada tiene fecha, y una finalizada también, porque sólo se llega desde
    -- programada.
    CONSTRAINT funcion_programada_con_fecha CHECK (estado NOT IN ('programada', 'finalizada') OR fecha_hora IS NOT NULL)
) ENGINE = InnoDB;
