-- El reembolso del pago cuando la reserva se cancela (EF-06.4): lo devuelve el espectador al
-- cancelar, el administrador al anular, o la cancelación de la función. Sólo tiene reembolso la
-- que se había pagado (pago_id): una a la gorra, una confirmada con el cobro apagado o una que
-- venció sin pagar no tienen nada que devolver.
--
-- reembolso_id es el id del reembolso en Mercado Pago, y es la marca de que ya se devolvió: el
-- servicio devuelve sólo las que lo tienen en NULL, así cancelar dos veces —o reintentar un
-- reembolso que falló— no devuelve la plata dos veces. Único, como pago_id: un mismo reembolso no
-- es de dos reservas.
--
-- No es un estado de la reserva: una reembolsada está cancelada, y el reembolso es lo que pasó con
-- su plata. Tampoco deja una línea en cambio_reserva, que anota cambios de estado y tiene el CHECK
-- cambio_reserva_cambia_el_estado; el reembolso queda acá, en la reserva, y en el log.
--
-- Una cancelada con pago_id y sin reembolso_id es un reembolso pendiente: Mercado Pago no respondió
-- o lo rechazó. El panel del administrador las marca así para reintentarlas.
--
-- En UTC, como creada_en.
ALTER TABLE reserva
    ADD COLUMN reembolso_id VARCHAR(32) CHARACTER SET ascii NULL AFTER pago_id,
    ADD COLUMN reembolsada_en DATETIME NULL AFTER reembolso_id,
    ADD UNIQUE INDEX reserva_por_reembolso (reembolso_id),
    -- Las dos van juntas: no hay reembolso sin fecha ni fecha sin reembolso.
    ADD CONSTRAINT reserva_reembolso_con_fecha CHECK ((reembolso_id IS NULL) = (reembolsada_en IS NULL));
