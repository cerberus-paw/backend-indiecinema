-- La reserva de quien no fue (EF-04.1): al finalizar la función, las confirmadas sin asistencia
-- pasan a ausente. Sólo quien tiene la asistencia registrada califica la función.
-- Los mismos valores que EstadoReserva.
ALTER TABLE reserva
    MODIFY estado ENUM('pendiente_pago', 'confirmada', 'cancelada', 'asistio', 'ausente') NOT NULL DEFAULT 'confirmada';
