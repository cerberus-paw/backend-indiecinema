-- La reserva que espera el pago (X-01.2): la de una función paga nace pendiente_pago y se confirma
-- sólo con el aviso de Mercado Pago. Mientras tanto ocupa sus lugares, hasta vence_en.
--
-- vence_en es hasta cuándo puede pagar el espectador, en UTC como creada_en; las demás reservas no
-- vencen y lo tienen en NULL. preferencia_id y enlace_de_pago son la preferencia de Checkout Pro:
-- el comprobante muestra el enlace para pagar sin volver a crearla.
--
-- El índice por estado y vencimiento es para encontrar las pendientes que ya vencieron (X-01.5).
-- reserva_por_estado (estado, creada_en) queda para el panel del administrador.
ALTER TABLE reserva
    MODIFY estado ENUM('pendiente_pago', 'confirmada', 'cancelada', 'asistio') NOT NULL DEFAULT 'confirmada',
    ADD COLUMN vence_en DATETIME NULL AFTER creada_en,
    ADD COLUMN preferencia_id VARCHAR(64) CHARACTER SET ascii NULL AFTER vence_en,
    ADD COLUMN enlace_de_pago VARCHAR(255) CHARACTER SET ascii NULL AFTER preferencia_id,
    ADD INDEX reserva_por_vencimiento (estado, vence_en),
    -- Una pendiente sin vencimiento ocuparía sus lugares para siempre.
    ADD CONSTRAINT reserva_pendiente_con_vencimiento CHECK (estado <> 'pendiente_pago' OR vence_en IS NOT NULL);
