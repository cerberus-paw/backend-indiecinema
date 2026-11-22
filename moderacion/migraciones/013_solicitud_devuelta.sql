-- La solicitud devuelta (EF-22.10): si un documento no sirve, el moderador devuelve la solicitud
-- en lugar de rechazarla. Marca qué falta (devolucion_faltantes), con un motivo, y el solicitante
-- tiene hasta devolucion_vence (el último día, en Argentina) para completarla. Completarla la
-- vuelve a pendiente; si no lo hace, la tarea vencer-devueltas la cierra como vencida, que es
-- terminal como la aprobada y la rechazada, con resuelta_en, motivo y sin resuelta_por.
--
-- La devuelta sigue abierta: cuenta para la única abierta por sala. Los cinco campos de la
-- devolución van juntos y guardan la última: las anteriores quedan en el checklist y en los
-- documentos rechazados. Una pendiente que pasó por la revisión manual, la devolvieron y la
-- completaron conserva su revisión, así que el CHECK de la 008 se afloja para ella.
ALTER TABLE solicitud_sala
    DROP CHECK solicitud_sala_resuelta,
    DROP CHECK solicitud_sala_en_revision,
    MODIFY COLUMN estado ENUM('pendiente', 'en_revision_manual', 'devuelta', 'aprobada', 'rechazada', 'vencida') NOT NULL DEFAULT 'pendiente',
    MODIFY COLUMN pendiente_de CHAR(36) CHARACTER SET ascii
        AS (IF(estado IN ('pendiente', 'en_revision_manual', 'devuelta'), sala_id, NULL)) VIRTUAL,
    ADD COLUMN devolucion_motivo VARCHAR(1000) NULL,
    ADD COLUMN devuelta_por CHAR(36) CHARACTER SET ascii NULL,
    ADD COLUMN devuelta_en DATETIME NULL,
    ADD COLUMN devolucion_vence DATE NULL,
    ADD COLUMN devolucion_faltantes SET('seguro', 'habilitacion') NULL,
    ADD CONSTRAINT solicitud_sala_resuelta CHECK ((estado IN ('pendiente', 'en_revision_manual', 'devuelta')) = (resuelta_en IS NULL)),
    ADD CONSTRAINT solicitud_sala_en_revision CHECK (
        (estado <> 'en_revision_manual' OR revision_en IS NOT NULL)
        AND (estado <> 'pendiente' OR revision_en IS NULL OR devuelta_en IS NOT NULL)
    ),
    ADD CONSTRAINT solicitud_sala_devolucion_completa CHECK (
        (devolucion_motivo IS NULL) = (devuelta_por IS NULL) AND (devuelta_por IS NULL) = (devuelta_en IS NULL)
        AND (devuelta_en IS NULL) = (devolucion_vence IS NULL) AND (devolucion_vence IS NULL) = (devolucion_faltantes IS NULL)
        AND (devolucion_faltantes IS NULL OR devolucion_faltantes <> '')
    ),
    ADD CONSTRAINT solicitud_sala_devuelta CHECK (estado NOT IN ('devuelta', 'vencida') OR devuelta_en IS NOT NULL);
