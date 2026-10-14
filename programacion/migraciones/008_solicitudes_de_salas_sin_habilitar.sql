-- Las salas que ya esperaban que el administrador las habilitara entran a la bandeja como
-- pendientes, mandadas por su organizador cuando las cargó. La solicitud lleva el id de su sala:
-- es un UUID v4 como los que genera la aplicación (UUID() de MySQL es v1), y no se repite.
INSERT INTO solicitud_sala (id, sala_id, usuario_id, enviada_en)
SELECT id, id, organizador_id, creada_en
FROM sala
WHERE habilitada = FALSE AND dada_de_baja_en IS NULL;
