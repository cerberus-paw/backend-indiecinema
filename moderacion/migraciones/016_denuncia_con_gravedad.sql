-- Las denuncias con gravedad (EF-22.11). La categoría es el motivo que elige quien denuncia, y
-- suma los que hablan de lo que pasa en una sala. Cada motivo da una gravedad, que se guarda al
-- crearla: cambiar la tabla de motivos no reescribe el historial.
--
-- sala_id es la sala denunciada o la de la función, para contar por sala las denuncias de las
-- reglas: nula en obras y comentarios.
--
-- La gravedad entra nula: la migración que sigue se la da a las que ya había y la otra la vuelve
-- obligatoria (cada archivo, una sentencia).
ALTER TABLE denuncia
    MODIFY COLUMN categoria ENUM('ofensivo', 'derechos', 'falsa', 'seguridad', 'otro', 'no_se_hizo', 'cambio_sin_aviso', 'instalaciones') NOT NULL,
    -- En este orden: gravedad + 0 ordena de menos a más grave.
    ADD COLUMN gravedad ENUM('baja', 'media', 'alta') NULL AFTER categoria,
    ADD COLUMN sala_id CHAR(36) CHARACTER SET ascii NULL AFTER objeto_id;
