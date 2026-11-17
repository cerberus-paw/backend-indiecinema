-- Las cuentas anteriores a X-02 nacieron sin verificación por correo: quedan verificadas para no
-- bloquear a los usuarios existentes ni a las cuentas de prueba.
UPDATE usuario
SET correo_verificado_en = UTC_TIMESTAMP()
WHERE correo_verificado_en IS NULL;
