-- Lo que le cuesta a cada sala abrir para una función, como lo carga su organizador o el
-- administrador desde los reportes (EF-22.1): contra eso se ve si la sala gana o pierde plata. La
-- sala que no tiene fila usa [reportes] costo_funcion de config.ini.
--
-- Sin clave foránea a la sala: vive en programación. Una sala dada de baja deja su fila, que no
-- molesta y vuelve a servir si la sala vuelve.
CREATE TABLE costo_sala (
    sala_id CHAR(36) CHARACTER SET ascii NOT NULL PRIMARY KEY,
    costo_funcion DECIMAL(10, 2) NOT NULL,
    cargado_por CHAR(36) CHARACTER SET ascii NOT NULL,
    cargado_en DATETIME NOT NULL,
    CONSTRAINT costo_sala_no_negativo CHECK (costo_funcion >= 0)
) ENGINE = InnoDB;
