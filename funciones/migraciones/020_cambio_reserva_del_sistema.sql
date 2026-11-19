-- Los cambios que hace el sistema solo quedan como suyos en el historial (EF-18.6): la reserva que
-- vence sin pagar (la tarea programada liberar-vencidas), la que queda ausente cuando programación
-- avisa que la función terminó, y la que se cancela porque el pago llegó cuando ya no había lugar.
-- Hasta acá quedaban a nombre del espectador o del organizador, como si los hubieran hecho ellos.
--
-- Un cambio del sistema no tiene usuario: autor_id es NULL si y sólo si autor_rol es 'sistema'.
ALTER TABLE cambio_reserva
    MODIFY autor_id CHAR(36) CHARACTER SET ascii NULL,
    MODIFY autor_rol ENUM('espectador', 'organizador', 'administrador', 'sistema') NOT NULL;

-- Los que ya están: se reconocen por el motivo, que escribía siempre el mismo código
-- (LiberacionDeVencidas::MOTIVO, ServicioDePagos::MOTIVO_SIN_LUGAR y marcarAusentes()).
UPDATE cambio_reserva
SET autor_rol = 'sistema', autor_id = NULL
WHERE motivo IN (
    'Venció el plazo para pagar.',
    'Venció el plazo para pagar y el pago llegó cuando ya no quedaban lugares.',
    'La función terminó sin la asistencia registrada.'
);

ALTER TABLE cambio_reserva
    ADD CONSTRAINT cambio_reserva_autor_del_sistema CHECK ((autor_rol = 'sistema') = (autor_id IS NULL));
