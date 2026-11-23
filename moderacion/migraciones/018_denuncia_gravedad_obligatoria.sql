-- EF-22.11: con la gravedad de las que ya había, pasa a obligatoria. El índice es el de las
-- reglas: las de una sala por estado y gravedad, dentro del período.
ALTER TABLE denuncia
    MODIFY COLUMN gravedad ENUM('baja', 'media', 'alta') NOT NULL,
    ADD INDEX denuncia_por_sala (sala_id, estado, gravedad, creada_en);
