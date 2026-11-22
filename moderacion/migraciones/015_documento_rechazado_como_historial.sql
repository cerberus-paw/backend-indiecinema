-- EF-22.10: los documentos que marcó el moderador quedan rechazados, como historial, y el solicitante sube
-- otros a la misma solicitud: una solicitud lleva un documento de cada tipo sin contar los
-- rechazados. tipo_vigente vale «solicitud:tipo» sólo en esos, y el índice único no cuenta los
-- NULL. La clave foránea necesita un índice que empiece por solicitud_id: va antes de sacar el
-- viejo.
ALTER TABLE documento
    ADD COLUMN tipo_vigente VARCHAR(50) CHARACTER SET ascii
        AS (IF(solicitud_id IS NOT NULL AND estado <> 'rechazado', CONCAT(solicitud_id, ':', tipo), NULL)) VIRTUAL,
    ADD INDEX documento_por_solicitud (solicitud_id),
    ADD UNIQUE INDEX un_tipo_por_solicitud_sin_rechazar (tipo_vigente),
    DROP INDEX un_tipo_por_solicitud;
