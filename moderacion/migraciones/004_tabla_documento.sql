-- La documentación de una sala (EF-07.4): el seguro y la habilitación del espacio, cada uno un PDF
-- con su fecha de vencimiento. Llega con la solicitud de sala y, cuando venza, como renovación
-- desde «Mis salas» (EF-07.5), que no tiene solicitud: por eso cuelga de la sala (sala_id, sin
-- clave foránea porque la sala es de otro esquema) y la solicitud es opcional.
--
-- archivo es el nombre al azar del PDF en el volumen de moderación. Una solicitud corregida sin
-- un PDF nuevo apunta al mismo archivo que la anterior: no se borran, son el historial.
--
-- estado: aprobar la solicitud acepta sus documentos y rechazarla los rechaza (grilling de
-- EF-07, Q6).
--
-- Las fechas de subida las pone la aplicación, en UTC; el vencimiento es un día, el que dice el
-- papel.
CREATE TABLE documento (
    id CHAR(36) CHARACTER SET ascii NOT NULL,
    sala_id CHAR(36) CHARACTER SET ascii NOT NULL,
    solicitud_id CHAR(36) CHARACTER SET ascii NULL,
    tipo ENUM('seguro', 'habilitacion') NOT NULL,
    archivo VARCHAR(64) CHARACTER SET ascii NOT NULL,
    vence_en DATE NOT NULL,
    subido_en DATETIME NOT NULL,
    estado ENUM('pendiente', 'aceptado', 'rechazado') NOT NULL DEFAULT 'pendiente',
    PRIMARY KEY (id),
    -- Una solicitud lleva un documento de cada tipo; las renovaciones (sin solicitud) no cuentan.
    UNIQUE INDEX un_tipo_por_solicitud (solicitud_id, tipo),
    -- Los de una sala, por tipo y vencimiento: la tarea que suspende las vencidas (EF-07.5).
    INDEX documento_por_sala (sala_id, tipo, vence_en),
    CONSTRAINT documento_de_la_solicitud FOREIGN KEY (solicitud_id) REFERENCES solicitud_sala (id)
) ENGINE = InnoDB;
