-- Las obras que se proyectaron en la función calificada (EF-04.2), copiadas de programación al
-- calificar: el promedio de una obra sale de las calificaciones de las funciones donde se dio.
CREATE TABLE calificacion_obra (
    calificacion_id CHAR(36) CHARACTER SET ascii NOT NULL,
    obra_id CHAR(36) CHARACTER SET ascii NOT NULL,
    PRIMARY KEY (calificacion_id, obra_id),
    -- El promedio y las últimas de una obra.
    INDEX calificacion_obra_por_obra (obra_id),
    CONSTRAINT calificacion_obra_de_una_calificacion FOREIGN KEY (calificacion_id) REFERENCES calificacion (id)
) ENGINE = InnoDB;
