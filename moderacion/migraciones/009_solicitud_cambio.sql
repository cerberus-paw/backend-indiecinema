-- El pedido de cambio de dirección de una sala publicada (EF-07.6): una solicitud con el id de la
-- sala, marcada como cambio. Mientras se revisa, la sala sigue publicada con la dirección vieja;
-- al aprobarla, programación le pone los datos nuevos. Las de antes son de salas nuevas.
ALTER TABLE solicitud_sala ADD COLUMN cambio BOOLEAN NOT NULL DEFAULT FALSE;
