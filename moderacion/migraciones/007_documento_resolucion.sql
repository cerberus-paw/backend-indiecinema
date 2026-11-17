-- Quién resolvió un documento suelto y por qué (EF-07.5): las renovaciones que sube el organizador
-- desde la documentación de su sala no tienen solicitud, así que la moderación las acepta o las
-- rechaza una por una, con un motivo que le llega al organizador. Las de una solicitud se resuelven
-- con ella y no lo usan. Una renovación que reemplaza a otra pendiente del mismo tipo la deja
-- rechazada con el motivo «Lo reemplazó otro.» y sin quién.
ALTER TABLE documento
    ADD COLUMN motivo VARCHAR(1000) NULL,
    ADD COLUMN resuelto_por CHAR(36) CHARACTER SET ascii NULL,
    ADD COLUMN resuelto_en DATETIME NULL;
