-- Lo que se le liquida a cada beneficiario por mes (EF-08.3): la suma de sus partes de los
-- repartos de ese mes. Una fila por beneficiario y período, armada por la tarea del reparto cada
-- vez que reparte algo del mes.
--
-- El período es el mes en que se finalizaron las funciones, en «AAAA-MM»: es el mismo criterio con
-- el que la tarea recorre los repartos, así que una función que se finalizó tarde se liquida en el
-- mes en que se finalizó y no queda afuera de todos.
--
-- La transferencia se hace fuera del sitio: «transferida» es la marca del administrador, con la
-- fecha y una nota (el comprobante, el banco, lo que quiera dejar escrito). Una liquidación ya
-- transferida no se vuelve a armar, para que el monto no cambie después de pagada.
CREATE TABLE liquidacion (
    id CHAR(36) CHARACTER SET ascii NOT NULL,
    -- El organizador de la sala o el dueño de la obra, los dos de programación: no hay clave
    -- foránea porque los usuarios no están en el esquema de recaudación.
    beneficiario_id CHAR(36) CHARACTER SET ascii NOT NULL,
    periodo CHAR(7) CHARACTER SET ascii NOT NULL,
    monto DECIMAL(10, 2) NOT NULL,
    estado ENUM('pendiente', 'transferida') NOT NULL DEFAULT 'pendiente',
    transferida_en DATETIME NULL,
    nota VARCHAR(500) NULL,
    armada_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- Una sola por beneficiario y mes: la tarea la rearma en vez de agregar otra.
    UNIQUE INDEX liquidacion_una_por_beneficiario_y_periodo (beneficiario_id, periodo),
    -- Las del mes que mira el administrador, primero las que faltan transferir.
    INDEX liquidacion_por_periodo (periodo, estado),
    CONSTRAINT liquidacion_monto_no_negativo CHECK (monto >= 0),
    -- Una transferida tiene fecha, y una pendiente no: el estado y la fecha no se pueden contradecir.
    CONSTRAINT liquidacion_transferida_con_fecha CHECK ((estado = 'transferida') = (transferida_en IS NOT NULL))
) ENGINE = InnoDB;
