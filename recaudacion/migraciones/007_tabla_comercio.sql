-- Los comercios asociados y su beneficio para los espectadores (EF-17.3): un bar al lado de la
-- sala que hace un descuento mostrando la entrada, una librería, un estacionamiento. Los carga el
-- administrador y se ven en /recaudacion/beneficios.
--
-- La baja no borra la fila: deja el comercio inactivo, que no se muestra, y se puede reactivar sin
-- volver a escribirlo.
CREATE TABLE comercio (
    id CHAR(36) CHARACTER SET ascii NOT NULL,
    nombre VARCHAR(120) NOT NULL,
    beneficio VARCHAR(300) NOT NULL,
    direccion VARCHAR(160) NOT NULL,
    localidad VARCHAR(80) NOT NULL,
    activo BOOLEAN NOT NULL DEFAULT TRUE,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- La página pública los muestra por localidad y nombre.
    INDEX comercio_activos (activo, localidad, nombre)
) ENGINE = InnoDB;
