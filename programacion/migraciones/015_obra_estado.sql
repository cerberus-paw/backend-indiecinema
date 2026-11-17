-- La revisión de las obras (EF-07.7): la que carga un organizador queda pendiente hasta que la
-- aprueba la moderación, y sólo las aprobadas se ven en el sitio y se proyectan. motivo es el de
-- la última resolución, que el dueño ve en «Mis obras». La aplicación carga cada obra con su
-- estado; el valor por defecto deja aprobadas las que ya estaban.
ALTER TABLE obra
    ADD COLUMN estado ENUM('pendiente', 'aprobada', 'rechazada') NOT NULL DEFAULT 'aprobada',
    ADD COLUMN motivo VARCHAR(1000) NULL,
    -- Las pendientes de la bandeja de moderación, primero la más vieja.
    ADD INDEX obra_por_estado (estado, creada_en);
