-- El checklist de la revisión de una solicitud de sala (EF-07.9): en cada resolución (aprobarla,
-- rechazarla o pasarla a revisión manual) el moderador tilda los requisitos que controló, y queda
-- registrado qué tildó cada uno. Una solicitud que pasa por revisión manual y después se aprueba
-- tiene dos filas, cada una con su moderador.
--
-- Los requisitos son fijos (RequisitoDeSala): una columna por cada uno. Aprobar exige los cuatro, y
-- la base lo cuida aunque alguien escriba por otro lado; rechazar o pasar a revisión guarda los que
-- se tildaron, que es justamente lo que no cumplía.
--
-- Las solicitudes resueltas antes de esta tarea no tienen filas. revisado_en la pone la
-- aplicación, en UTC. Va al segundo: dos resoluciones en el mismo segundo se ordenan por el id, que
-- crece con cada una.
CREATE TABLE revision_requisitos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    solicitud_id CHAR(36) CHARACTER SET ascii NOT NULL,
    moderador_id CHAR(36) CHARACTER SET ascii NOT NULL,
    resolucion ENUM('aprobada', 'rechazada', 'en_revision_manual') NOT NULL,
    revisado_en DATETIME NOT NULL,
    seguro_vigente BOOLEAN NOT NULL,
    habilitacion BOOLEAN NOT NULL,
    capacidad BOOLEAN NOT NULL,
    condiciones BOOLEAN NOT NULL,
    PRIMARY KEY (id),
    -- Los de una solicitud, del más viejo al más nuevo (por el id), para el detalle de la bandeja.
    INDEX revision_requisitos_por_solicitud (solicitud_id),
    CONSTRAINT revision_requisitos_de_la_solicitud FOREIGN KEY (solicitud_id) REFERENCES solicitud_sala (id),
    CONSTRAINT revision_requisitos_aprobada_completa CHECK (
        resolucion <> 'aprobada' OR (seguro_vigente AND habilitacion AND capacidad AND condiciones)
    )
) ENGINE = InnoDB;
