-- Los cambios automáticos que ya están en el historial pasan al sistema (EF-18.6). Se reconocen
-- por el motivo, que escribía siempre el mismo código (LiberacionDeVencidas::MOTIVO,
-- ServicioDePagos::MOTIVO_SIN_LUGAR y ServicioDePanelDeReservas::MOTIVO_AUSENTE).
UPDATE cambio_reserva
SET autor_rol = 'sistema', autor_id = NULL
WHERE motivo IN (
    'Venció el plazo para pagar.',
    'Venció el plazo para pagar y el pago llegó cuando ya no quedaban lugares.',
    'La función terminó sin la asistencia registrada.'
);
