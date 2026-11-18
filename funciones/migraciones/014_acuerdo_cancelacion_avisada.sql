-- Cuándo se les avisó a los que marcaron «Me sirve» que la función se canceló porque el acuerdo
-- venció sin llegar al mínimo (EF-03.2). Lo pide la tarea de programación, que corre cada quince
-- minutos y reintenta si algo falla: con esta marca, repetir el pedido no repite los correos.
ALTER TABLE acuerdo_fecha
    ADD COLUMN cancelacion_avisada_en DATETIME NULL AFTER cerrado_en;
