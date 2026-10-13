-- Las solicitudes para ser organizador: lo que mandó el espectador y cómo la resolvió el
-- administrador. Los largos son los de FormularioDeSolicitud::REGLAS.
--
-- Un usuario tiene como mucho una pendiente: «pendiente_de» vale su id mientras la solicitud está
-- pendiente y NULL después, y el índice único no cuenta los NULL. Lo controla la base, así dos
-- envíos a la vez no dejan dos.
--
-- Las fechas las pone la aplicación, en UTC, como en sesion.
CREATE TABLE solicitud (
    id CHAR(36) CHARACTER SET ascii NOT NULL,
    usuario_id CHAR(36) CHARACTER SET ascii NOT NULL,
    sala VARCHAR(100) NOT NULL,
    localidad VARCHAR(100) NOT NULL,
    correo VARCHAR(254) NOT NULL,
    telefono VARCHAR(20) NULL,
    presentacion VARCHAR(2000) NOT NULL,
    estado ENUM('pendiente', 'aprobada', 'rechazada') NOT NULL DEFAULT 'pendiente',
    enviada_en DATETIME NOT NULL,
    -- La resolución: quién, cuándo y el motivo, que se le muestra al solicitante.
    resuelta_por CHAR(36) CHARACTER SET ascii NULL,
    resuelta_en DATETIME NULL,
    motivo VARCHAR(1000) NULL,
    pendiente_de CHAR(36) CHARACTER SET ascii AS (IF(estado = 'pendiente', usuario_id, NULL)) VIRTUAL,
    PRIMARY KEY (id),
    UNIQUE INDEX una_pendiente_por_usuario (pendiente_de),
    -- La bandeja del administrador: las pendientes, de la más vieja a la más nueva.
    INDEX solicitud_por_estado (estado, enviada_en),
    INDEX solicitud_por_usuario (usuario_id, enviada_en),
    -- Si el usuario se borra, sus solicitudes también; si se borra quien la resolvió, queda sin él.
    CONSTRAINT solicitud_de_usuario FOREIGN KEY (usuario_id) REFERENCES usuario (id) ON DELETE CASCADE,
    CONSTRAINT solicitud_resuelta_por FOREIGN KEY (resuelta_por) REFERENCES usuario (id) ON DELETE SET NULL,
    CONSTRAINT solicitud_resuelta CHECK ((estado = 'pendiente') = (resuelta_en IS NULL))
) ENGINE = InnoDB;
