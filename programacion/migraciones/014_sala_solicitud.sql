-- La última solicitud de moderación que se le aplicó a la sala (EF-07.6). Un cambio de dirección
-- aprobado llega por POST /interno/salas con su solicitud: si es la misma que ya se aplicó, es un
-- reintento y no se toca la sala; si es otra, se le ponen los datos nuevos. Es de otro esquema, así
-- que va sin clave foránea. NULL en las que se crearon sin solicitud (las de antes, y las de los
-- datos de prueba).
ALTER TABLE sala ADD COLUMN solicitud_id CHAR(36) CHARACTER SET ascii NULL;
