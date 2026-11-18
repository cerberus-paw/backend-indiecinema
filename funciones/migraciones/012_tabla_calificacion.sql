-- La calificación de una función (EF-04.2): la deja el espectador que asistió, una vez por
-- función, de 1 a 5 y con un comentario opcional.
--
-- sala_id y organizador_id son una copia de lo que dice programación de la función al calificarla
-- (/interno/funciones/{id}): así los promedios por sala y por organizador salen de esta tabla, sin
-- pedirle a programación todas las funciones. Las obras van aparte (calificacion_obra), porque son
-- varias. Como en reserva, ninguno de los ids de otros subsistemas tiene clave foránea.
--
-- Las fechas las pone la aplicación, en UTC.
CREATE TABLE calificacion (
    id CHAR(36) CHARACTER SET ascii NOT NULL,
    funcion_id CHAR(36) CHARACTER SET ascii NOT NULL,
    usuario_id CHAR(36) CHARACTER SET ascii NOT NULL,
    -- La reserva con la que asistió: la que muestra el botón en «Mis reservas».
    reserva_id CHAR(36) CHARACTER SET ascii NOT NULL,
    puntaje TINYINT UNSIGNED NOT NULL,
    comentario VARCHAR(500) NULL,
    sala_id CHAR(36) CHARACTER SET ascii NOT NULL,
    organizador_id CHAR(36) CHARACTER SET ascii NOT NULL,
    creada_en DATETIME NOT NULL,
    PRIMARY KEY (id),
    -- Una por espectador y función, aunque haya ido con dos reservas: lo controla la base, así dos
    -- envíos a la vez no dejan dos.
    UNIQUE INDEX calificacion_una_por_funcion (funcion_id, usuario_id),
    -- Los promedios y las últimas de una sala y de un organizador.
    INDEX calificacion_por_sala (sala_id, creada_en),
    INDEX calificacion_por_organizador (organizador_id),
    CONSTRAINT calificacion_de_una_reserva FOREIGN KEY (reserva_id) REFERENCES reserva (id),
    -- La aplicación ya lo valida; esto cuida la tabla si alguien escribe por otro lado.
    CONSTRAINT calificacion_puntaje_de_1_a_5 CHECK (puntaje BETWEEN 1 AND 5)
) ENGINE = InnoDB;
