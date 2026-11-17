-- Las denuncias de lo publicado (EF-07.8): una sala, una función, una obra o el comentario de una
-- calificación. Lo denunciado es de otro subsistema (programación o funciones): objeto_id va sin
-- clave foránea, y titulo guarda cómo se llamaba al denunciarlo, para el historial.
--
-- La moderación las cierra todas juntas por objeto: desestimadas, o resueltas con la acción que
-- tomó (suspender la sala, rechazar la obra u ocultar el comentario). En una función, la acción
-- puede ser sobre una de sus obras: obra_rechazada_id dice cuál.
CREATE TABLE denuncia (
    id CHAR(36) CHARACTER SET ascii NOT NULL,
    tipo ENUM('sala', 'funcion', 'obra', 'calificacion') NOT NULL,
    objeto_id CHAR(36) CHARACTER SET ascii NOT NULL,
    titulo VARCHAR(200) NOT NULL,
    usuario_id CHAR(36) CHARACTER SET ascii NOT NULL,
    categoria ENUM('ofensivo', 'derechos', 'falsa', 'seguridad', 'otro') NOT NULL,
    detalle VARCHAR(1000) NULL,
    -- En UTC, como las demás fechas de moderación.
    creada_en DATETIME NOT NULL,
    estado ENUM('abierta', 'desestimada', 'resuelta') NOT NULL DEFAULT 'abierta',
    accion ENUM('suspender', 'rechazar', 'ocultar') NULL,
    obra_rechazada_id CHAR(36) CHARACTER SET ascii NULL,
    motivo VARCHAR(1000) NULL,
    resuelta_por CHAR(36) CHARACTER SET ascii NULL,
    resuelta_en DATETIME NULL,
    -- Una abierta por usuario y objeto: dos envíos a la vez no dejan dos.
    abierta_de VARCHAR(90) CHARACTER SET ascii AS (IF(estado = 'abierta', CONCAT(tipo, ':', objeto_id, ':', usuario_id), NULL)) VIRTUAL,
    PRIMARY KEY (id),
    UNIQUE INDEX una_abierta_por_usuario (abierta_de),
    -- La bandeja: las abiertas de cada objeto, primero la más vieja.
    INDEX denuncia_por_objeto (estado, tipo, objeto_id, creada_en),
    -- El historial, primero la última.
    INDEX denuncia_por_resolucion (estado, resuelta_en),
    -- Resuelta con una acción, o desestimada sin ella; cerrada, con quién, cuándo y por qué.
    CONSTRAINT denuncia_accion_si_resuelta CHECK ((estado = 'resuelta') = (accion IS NOT NULL)),
    CONSTRAINT denuncia_cerrada_completa CHECK ((estado = 'abierta') = (motivo IS NULL AND resuelta_por IS NULL AND resuelta_en IS NULL))
) ENGINE = InnoDB;
