-- Cuándo salió el recordatorio de la función, el día antes (EF-03.3). La tarea
-- bin/recordar-funciones.php corre cada hora: con esta marca, cada reserva recibe uno solo aunque
-- la función siga dentro de las próximas 24 horas en varias pasadas. En UTC, como creada_en.
ALTER TABLE reserva
    ADD COLUMN recordada_en DATETIME NULL;
