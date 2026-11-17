-- Las que ya estaban finalizadas antes de la columna se dan por finalizadas a su hora: es lo más
-- cerca que se puede estar de cuándo fue, y así la tarea del reparto (EF-08.2) las encuentra, en
-- vez de saltearlas para siempre por tener la fecha de finalización nula.
UPDATE funcion SET finalizada_en = fecha_hora WHERE estado = 'finalizada' AND finalizada_en IS NULL;
