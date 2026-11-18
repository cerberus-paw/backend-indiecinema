-- La revisión manual (EF-07.6): el moderador pasa una solicitud pendiente a «en revisión manual»,
-- con un motivo que ve quien la mandó (por ejemplo, para coordinar una visita al espacio), y
-- después la aprueba o la rechaza como una pendiente. Sigue abierta: cuenta para la única abierta
-- por sala y no tiene resolución.
--
-- El motivo, quién y cuándo de la revisión van en sus columnas, aparte de los de la resolución,
-- así no se pierde lo que se dijo antes. Van los tres juntos, y sólo en una que pasó por la
-- revisión: nunca en una pendiente.
ALTER TABLE solicitud_sala
    DROP CHECK solicitud_sala_resuelta,
    MODIFY COLUMN estado ENUM('pendiente', 'en_revision_manual', 'aprobada', 'rechazada') NOT NULL DEFAULT 'pendiente',
    MODIFY COLUMN pendiente_de CHAR(36) CHARACTER SET ascii
        AS (IF(estado IN ('pendiente', 'en_revision_manual'), sala_id, NULL)) VIRTUAL,
    ADD COLUMN revision_motivo VARCHAR(1000) NULL,
    ADD COLUMN revision_por CHAR(36) CHARACTER SET ascii NULL,
    ADD COLUMN revision_en DATETIME NULL,
    ADD CONSTRAINT solicitud_sala_resuelta CHECK ((estado IN ('pendiente', 'en_revision_manual')) = (resuelta_en IS NULL)),
    ADD CONSTRAINT solicitud_sala_revision_completa CHECK (
        (revision_motivo IS NULL) = (revision_por IS NULL) AND (revision_por IS NULL) = (revision_en IS NULL)
    ),
    ADD CONSTRAINT solicitud_sala_en_revision CHECK (
        (estado <> 'en_revision_manual' OR revision_en IS NOT NULL) AND (estado <> 'pendiente' OR revision_en IS NULL)
    );
