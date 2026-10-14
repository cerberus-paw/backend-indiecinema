-- La solicitud de sala (E2, modelo de objetos de moderación: SolicitudSala): el trámite con el
-- que un usuario pide abrir una sala. Aprobarla habilita la sala y, si quien la mandó todavía no
-- es organizador, programación le pide el rol a cuentas por la API interna: pedir ser organizador
-- es pedir una sala, como en la E2. Rechazarla exige un motivo, que el solicitante ve para
-- corregir la sala y volver a mandarla: cada envío es una solicitud nueva de la misma sala.
--
-- La resuelve el administrador mientras no haya moderación (E2, «qué se posterga»).
--
-- solicitante_nombre es el nombre al mandarla, que llega de nginx con la sesión: la lista y el
-- buscador de la bandeja lo usan sin pedirle a cuentas uno por uno. Es nulo en las que se
-- cargaron por esta migración.
--
-- Una sala tiene como mucho una pendiente: «pendiente_de» vale su id mientras la solicitud está
-- pendiente y NULL después, y el índice único no cuenta los NULL. Lo controla la base, así dos
-- envíos a la vez no dejan dos.
--
-- Las fechas las pone la aplicación, en UTC.
CREATE TABLE solicitud_sala (
    id CHAR(36) CHARACTER SET ascii NOT NULL,
    sala_id CHAR(36) CHARACTER SET ascii NOT NULL,
    usuario_id CHAR(36) CHARACTER SET ascii NOT NULL,
    solicitante_nombre VARCHAR(100) NULL,
    estado ENUM('pendiente', 'aprobada', 'rechazada') NOT NULL DEFAULT 'pendiente',
    enviada_en DATETIME NOT NULL,
    -- La resolución: quién, cuándo y el motivo, que se le muestra al solicitante.
    resuelta_por CHAR(36) CHARACTER SET ascii NULL,
    resuelta_en DATETIME NULL,
    motivo VARCHAR(1000) NULL,
    pendiente_de CHAR(36) CHARACTER SET ascii AS (IF(estado = 'pendiente', sala_id, NULL)) VIRTUAL,
    PRIMARY KEY (id),
    UNIQUE INDEX una_pendiente_por_sala (pendiente_de),
    -- La bandeja: las pendientes, de la más vieja a la más nueva, y el historial.
    INDEX solicitud_por_estado (estado, enviada_en),
    -- La última de un usuario, para mostrarle en qué quedó.
    INDEX solicitud_por_usuario (usuario_id, enviada_en),
    INDEX solicitud_por_sala (sala_id, enviada_en),
    -- Las salas no se borran (la baja es lógica): sin CASCADE.
    CONSTRAINT solicitud_sala_de_sala FOREIGN KEY (sala_id) REFERENCES sala (id),
    -- Resuelta es con fecha y con motivo; pendiente, sin fecha.
    CONSTRAINT solicitud_sala_resuelta CHECK ((estado = 'pendiente') = (resuelta_en IS NULL)),
    CONSTRAINT solicitud_sala_con_motivo CHECK (resuelta_en IS NULL OR motivo IS NOT NULL)
) ENGINE = InnoDB;
