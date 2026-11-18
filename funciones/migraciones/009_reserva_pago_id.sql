-- El pago de Mercado Pago que confirmó cada reserva (X-01.4). Único: un mismo pago no confirma dos
-- reservas, y si su aviso llega dos veces, la segunda encuentra la reserva ya confirmada con ese
-- pago y no hace nada. NULL en las que no se cobraron (a la gorra, o con el cobro apagado) y en las
-- pendientes; MySQL deja repetir NULL en un índice único.
ALTER TABLE reserva
    ADD COLUMN pago_id VARCHAR(32) CHARACTER SET ascii NULL AFTER enlace_de_pago,
    ADD UNIQUE INDEX reserva_por_pago (pago_id);
