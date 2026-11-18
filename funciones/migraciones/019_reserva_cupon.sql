-- El cupón de descuento con el que se reservó (EF-17.1). Los cupones los da recaudación al
-- canjear puntos; acá queda cuál se usó en cada reserva.
--
-- Un cupón se usa una sola vez, pero vuelve a quedar disponible si la reserva se cancela, venga de
-- donde venga la cancelación (el espectador, el administrador, el vencimiento del pago o la función
-- cancelada). En lugar de liberarlo en cada uno de esos caminos, lo calcula la base: cupon_en_uso es
-- el cupón mientras la reserva no esté cancelada, y NULL cuando lo está. El índice único sobre esa
-- columna impide que dos reservas en pie tengan el mismo cupón, también si llegan a la vez, y deja
-- pasar las canceladas (en un índice único, NULL se puede repetir).
ALTER TABLE reserva
    ADD COLUMN cupon CHAR(8) CHARACTER SET ascii NULL AFTER total,
    ADD COLUMN cupon_en_uso CHAR(8) CHARACTER SET ascii AS (IF(estado = 'cancelada', NULL, cupon)) STORED,
    ADD UNIQUE INDEX reserva_cupon_en_uso (cupon_en_uso);
