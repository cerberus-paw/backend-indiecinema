-- Los pedidos se comparan por el título normalizado (en minúscula, sin tildes ni espacios de más)
-- y sin distinguir el año, que pasa a ser opcional y sólo como dato orientativo (EF-22.6).
ALTER TABLE pedido_titulo
    MODIFY COLUMN anio SMALLINT UNSIGNED NULL,
    DROP CHECK pedido_anio_valido,
    ADD CONSTRAINT pedido_anio_valido CHECK (anio IS NULL OR anio >= 1888),
    ADD COLUMN titulo_normalizado VARCHAR(150) NOT NULL DEFAULT '' AFTER titulo,
    ADD INDEX pedido_por_titulo_normalizado (titulo_normalizado);
