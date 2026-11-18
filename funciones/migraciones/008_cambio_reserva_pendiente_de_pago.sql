-- El historial también anota los cambios desde y hacia pendiente_pago (X-01.2): el pago aprobado
-- que la confirma, la reserva que vence sin pagar o la que cancela el espectador antes de pagar.
-- Los mismos valores que reserva.estado (007).
ALTER TABLE cambio_reserva
    MODIFY estado_anterior ENUM('pendiente_pago', 'confirmada', 'cancelada', 'asistio') NOT NULL,
    MODIFY estado_nuevo ENUM('pendiente_pago', 'confirmada', 'cancelada', 'asistio') NOT NULL;
