-- El historial también anota el paso a ausente y, si el organizador registra tarde la asistencia,
-- el de ausente a asistio (EF-04.1). Los mismos valores que reserva.estado (010).
ALTER TABLE cambio_reserva
    MODIFY estado_anterior ENUM('pendiente_pago', 'confirmada', 'cancelada', 'asistio', 'ausente') NOT NULL,
    MODIFY estado_nuevo ENUM('pendiente_pago', 'confirmada', 'cancelada', 'asistio', 'ausente') NOT NULL;
