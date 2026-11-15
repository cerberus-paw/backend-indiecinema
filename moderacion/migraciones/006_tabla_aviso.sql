-- Los correos que la tarea de vencimientos ya le mandó al organizador de cada sala (EF-07.5): la
-- tarea corre todos los días, y cada aviso tiene que salir una sola vez. La clave dice cuál:
-- «vence-7:<documento>», «suspendida:<suspensión>», «plazo-inicio», «plazo-7». Se guarda antes
-- de mandarlo, y si el correo falla se borra para reintentar en la próxima vuelta.
--
-- La fecha la pone la aplicación, en UTC.
CREATE TABLE aviso (
    sala_id CHAR(36) CHARACTER SET ascii NOT NULL,
    clave VARCHAR(80) CHARACTER SET ascii NOT NULL,
    enviado_en DATETIME NOT NULL,
    PRIMARY KEY (sala_id, clave)
) ENGINE = InnoDB;
