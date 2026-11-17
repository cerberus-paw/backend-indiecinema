-- Cada parte de un reparto (EF-08.2): cuánto le toca a la sala, a cada dueño de las obras que se
-- proyectaron y a la plataforma. Las partes de un reparto suman exactamente lo cobrado, y el
-- redondeo va a la de la plataforma.
--
-- beneficiario_id es el organizador de la sala o el dueño de la obra, los dos de programación, y
-- queda nulo en la parte de la plataforma, que es el sitio y no una cuenta a la que transferirle.
-- Por eso no hay clave foránea: los usuarios no están en el esquema de recaudación.
--
-- Una sola parte por rol y beneficiario: si una función proyectó dos obras del mismo dueño, las
-- dos mitades se suman en una, que es lo que se le va a liquidar (EF-08.3). El índice único no
-- alcanza para la parte de la plataforma, porque en MySQL dos NULL no se repiten entre sí; de que
-- sea una sola se encarga la aplicación, que guarda el reparto entero de una vez.
CREATE TABLE reparto_parte (
    id CHAR(36) CHARACTER SET ascii NOT NULL,
    reparto_id CHAR(36) CHARACTER SET ascii NOT NULL,
    rol ENUM('sala', 'dueno', 'plataforma') NOT NULL,
    beneficiario_id CHAR(36) CHARACTER SET ascii NULL,
    monto DECIMAL(10, 2) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE INDEX reparto_parte_una_por_beneficiario (reparto_id, rol, beneficiario_id),
    -- Lo que le toca a alguien en un período, para las liquidaciones (EF-08.3).
    INDEX reparto_parte_por_beneficiario (beneficiario_id),
    -- Las partes son del reparto: si se borrara uno, no quedan partes sueltas.
    CONSTRAINT reparto_parte_de_reparto FOREIGN KEY (reparto_id) REFERENCES reparto (id) ON DELETE CASCADE,
    CONSTRAINT reparto_parte_monto_no_negativo CHECK (monto >= 0),
    -- La plataforma es el sitio y no tiene a quién transferirle; las otras dos, sí.
    CONSTRAINT reparto_parte_con_beneficiario CHECK ((rol = 'plataforma') = (beneficiario_id IS NULL))
) ENGINE = InnoDB;
