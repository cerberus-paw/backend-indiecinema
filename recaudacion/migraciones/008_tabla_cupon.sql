-- Los cupones de descuento que el espectador canjea con sus puntos (EF-17.1).
--
-- Los puntos no se guardan: se calculan. Cada reserva a la que asistió suma (funciones guarda la
-- asistencia) y cada cupón canjeado resta los puntos que costó. Así no hace falta una tarea que
-- los sume ni se pueden desfasar de las asistencias.
--
-- Si el cupón se usó lo sabe funciones, que guarda en cada reserva con qué cupón se pagó: un cupón
-- en una reserva cancelada vuelve a quedar disponible sin que nadie lo marque acá.
CREATE TABLE cupon (
    -- 8 letras y números, para tipearlo en la reserva. Sin 0, O, 1, I ni L, que se confunden.
    codigo CHAR(8) CHARACTER SET ascii NOT NULL,
    usuario_id CHAR(36) CHARACTER SET ascii NOT NULL,
    porcentaje TINYINT UNSIGNED NOT NULL,
    -- Lo que costó en puntos, que se restan del saldo.
    puntos SMALLINT UNSIGNED NOT NULL,
    creado_en DATETIME NOT NULL,
    vence_en DATETIME NOT NULL,
    PRIMARY KEY (codigo),
    -- «Mis puntos», del más nuevo al más viejo.
    INDEX cupon_por_usuario (usuario_id, creado_en),
    CONSTRAINT cupon_porcentaje_valido CHECK (porcentaje BETWEEN 1 AND 100)
) ENGINE = InnoDB;
