-- Por qué está apagada una sala (EF-07.5): programación sólo tiene el booleano habilitada, y una
-- sala se puede apagar por más de una causa a la vez. Cada causa es una fila: la retiró un
-- moderador desde la bandeja (retiro), se le venció o le falta un documento (vencimiento, con el
-- tipo), o la suspendieron por una denuncia (EF-07.8). La sala se apaga en programación con la
-- primera activa y vuelve a publicarse cuando se levanta la última (grilling de EF-07, Q2).
--
-- abierta_por es el moderador, o NULL si la abrió la tarea que suspende las vencidas. Una sola
-- activa por sala, causa y tipo: «activa_de» vale esa clave mientras no se levantó y NULL
-- después, y el índice único no cuenta los NULL, como la pendiente de una solicitud.
--
-- Las fechas las pone la aplicación, en UTC.
CREATE TABLE suspension (
    id CHAR(36) CHARACTER SET ascii NOT NULL,
    sala_id CHAR(36) CHARACTER SET ascii NOT NULL,
    causa ENUM('retiro', 'vencimiento', 'denuncia') NOT NULL,
    tipo_documento ENUM('seguro', 'habilitacion') NULL,
    motivo VARCHAR(1000) NOT NULL,
    abierta_por CHAR(36) CHARACTER SET ascii NULL,
    desde DATETIME NOT NULL,
    levantada_en DATETIME NULL,
    levantada_por CHAR(36) CHARACTER SET ascii NULL,
    activa_de VARCHAR(64) CHARACTER SET ascii AS (IF(levantada_en IS NULL, CONCAT(sala_id, ':', causa, ':', COALESCE(tipo_documento, '')), NULL)) VIRTUAL,
    PRIMARY KEY (id),
    UNIQUE INDEX una_activa_por_causa (activa_de),
    -- Las de una sala, para saber si queda alguna activa.
    INDEX suspension_por_sala (sala_id, levantada_en),
    -- Sólo una suspensión por vencimiento dice de qué documento.
    CONSTRAINT suspension_tipo_de_vencimiento CHECK ((causa = 'vencimiento') = (tipo_documento IS NOT NULL))
) ENGINE = InnoDB;
