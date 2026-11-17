-- El reparto de lo cobrado de una función finalizada (EF-08.2): una fila por función, con lo que
-- se repartió, con qué parámetro (EF-08.1) y cuándo lo calculó la tarea. Las partes van aparte
-- (reparto_parte), porque son varias y una función puede tener varios dueños.
--
-- funcion_id es único: la tarea corre cada hora y una función se reparte una sola vez. Es lo que
-- hace que correrla de más no duplique nada, sin tener que mirar antes si ya estaba.
--
-- Las funciones a la gorra (precio_base en 0) también quedan registradas, con cobrado en 0 y sin
-- partes: no reparten, pero así la tarea no las vuelve a mirar en cada pasada y su finalizada_en
-- hace avanzar el cursor igual.
CREATE TABLE reparto (
    id CHAR(36) CHARACTER SET ascii NOT NULL,
    funcion_id CHAR(36) CHARACTER SET ascii NOT NULL,
    -- Lo cobrado neto de reembolsos, como lo cuenta funciones (GET /interno/cobros): la plata que
    -- se reparte. DECIMAL y no FLOAT, como el precio de la función.
    cobrado DECIMAL(10, 2) NOT NULL,
    -- Con qué porcentajes se repartió: el que regía a la hora de la función, no el de hoy.
    parametro_id CHAR(36) CHARACTER SET ascii NULL,
    -- Cuándo programación la pasó a finalizada (funcion.finalizada_en): el cursor de la tarea, que
    -- pide las finalizadas desde la última que repartió.
    finalizada_en DATETIME NOT NULL,
    calculado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE INDEX reparto_una_vez_por_funcion (funcion_id),
    -- Por donde sigue la tarea: el último reparto por fecha de finalización.
    INDEX reparto_por_finalizacion (finalizada_en),
    -- El parámetro no se borra nunca (la pantalla muestra el historial); si alguna vez se borrara,
    -- el reparto ya hecho se queda, con sus partes, y sin decir con qué porcentajes fue.
    CONSTRAINT reparto_con_parametro FOREIGN KEY (parametro_id) REFERENCES parametro_reparto (id) ON DELETE SET NULL,
    CONSTRAINT reparto_cobrado_no_negativo CHECK (cobrado >= 0)
) ENGINE = InnoDB;
