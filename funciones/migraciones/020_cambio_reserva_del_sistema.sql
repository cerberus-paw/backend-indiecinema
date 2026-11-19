-- Los cambios que hace el sistema solo quedan como suyos en el historial (EF-18.6): la reserva que
-- vence sin pagar (la tarea programada liberar-vencidas), la que queda ausente cuando programación
-- avisa que la función terminó, y la que se cancela porque el pago llegó cuando ya no había lugar.
-- Hasta acá quedaban a nombre del espectador o del organizador, como si los hubieran hecho ellos.
--
-- Un cambio del sistema no tiene usuario: autor_id puede ser nulo. Los que ya están pasan al
-- sistema en 021, y 022 hace que la base no acepte uno sin el otro. Tres archivos porque cada
-- migración es una sola sentencia.
ALTER TABLE cambio_reserva
    MODIFY autor_id CHAR(36) CHARACTER SET ascii NULL,
    MODIFY autor_rol ENUM('espectador', 'organizador', 'administrador', 'sistema') NOT NULL;
