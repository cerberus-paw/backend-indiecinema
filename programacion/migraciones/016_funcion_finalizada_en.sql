-- Cuándo pasó a finalizada cada función (EF-08.2). La tarea del reparto de recaudación pide las
-- que se finalizaron desde su última pasada, y para eso la fecha de la función no alcanza: una que
-- se dio el martes y se finalizó el jueves, porque el cron estuvo caído, tiene que entrar en la
-- pasada del jueves y no quedar afuera por su fecha.
--
-- Queda nula mientras la función no esté finalizada. La llena FinalizacionDeFunciones (EF-04.1) al
-- finalizarla, una sola vez: RepositorioDeFunciones::guardar() no la mueve si ya tiene fecha.
ALTER TABLE funcion
    ADD COLUMN finalizada_en DATETIME NULL,
    -- El cursor de la tarea del reparto: las finalizadas desde un momento, en ese mismo orden.
    ADD INDEX funcion_por_finalizacion (finalizada_en);
