-- La foto de una denuncia de sala o de función (EF-22.11), opcional: el nombre con el que se
-- guardó en el volumen de moderación, fuera del webroot, como las de las solicitudes. Sólo la ve
-- la moderación.
ALTER TABLE denuncia
    ADD COLUMN foto VARCHAR(40) CHARACTER SET ascii NULL AFTER detalle;
