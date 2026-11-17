-- Fecha del último reenvío del correo de verificación, para permitir uno cada 5 minutos por
-- cuenta sin impedir un primer reenvío después del registro si el envío inicial falló (X-02.4).
ALTER TABLE usuario
    ADD COLUMN verificacion_reenviada_en DATETIME NULL AFTER correo_verificado_en;
