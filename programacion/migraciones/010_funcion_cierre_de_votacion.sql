-- Hasta cuándo se vota cada función en votación de títulos (EF-03.1). La propuesta dice que la
-- votación «cierra en la fecha límite definida (o manualmente)», y hasta acá sólo había el cierre
-- a mano: la fecha la pone el organizador al abrir la votación, y la tarea bin/cerrar-por-fecha.php
-- cierra las que la pasaron.
--
-- Nula en las programadas, que no tienen votación, y en las que se abrieron antes de esta columna:
-- ésas se siguen cerrando a mano, como hasta ahora. Queda guardada después de cerrar, para el
-- historial.
ALTER TABLE funcion
    ADD COLUMN votacion_cierra_en DATETIME NULL AFTER fecha_hora,
    -- Las votaciones vencidas que busca la tarea, cada quince minutos.
    ADD INDEX funcion_por_cierre_de_votacion (estado, votacion_cierra_en);
