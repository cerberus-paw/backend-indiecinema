-- EF-22.11: las denuncias que ya había toman la gravedad de su categoría, y las de sala, su sala.
-- Las de función quedan sin sala, porque la sabe programación: no cuentan para las reglas.
UPDATE denuncia SET
    gravedad = CASE
        WHEN categoria IN ('seguridad', 'ofensivo', 'no_se_hizo') THEN 'alta'
        WHEN categoria IN ('derechos', 'falsa', 'cambio_sin_aviso') THEN 'media'
        ELSE 'baja'
    END,
    sala_id = IF(tipo = 'sala', objeto_id, sala_id);
