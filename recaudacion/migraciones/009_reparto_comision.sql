-- Lo que se quedó Mercado Pago de lo cobrado de la función (EF-22.1): se descuenta antes de
-- repartir, así las partes suman lo cobrado menos la comisión. Los repartos anteriores quedan con
-- 0: se hicieron sin descontarla y sus liquidaciones ya están armadas con eso.
ALTER TABLE reparto ADD COLUMN comision DECIMAL(10, 2) NOT NULL DEFAULT 0.00 AFTER cobrado;
