-- La reserva de lugares en una función (E2, modelo de objetos de funciones: Reserva). Se confirma
-- sin pago: el cobro con Mercado Pago queda como extra, y entonces se suma el estado de la reserva
-- que espera el pago.
--
-- funcion_id y usuario_id no tienen clave foránea: la función es de programación y el usuario de
-- cuentas, cada uno en su esquema, y funciones no tiene acceso a ninguno de los dos (E2, sección 4).
-- Que existan lo comprueba la aplicación por la API interna antes de guardar.
--
-- total es el precio de la función por la cantidad al momento de reservar: si el organizador
-- cambia el precio después, la reserva no cambia.
--
-- Que no se reserve más que el cupo lo controla la aplicación en una transacción: un CHECK no
-- puede sumar las otras filas, y el cupo vive en programación.
--
-- Las fechas las pone la aplicación, en UTC.
CREATE TABLE reserva (
    -- UUID generado por la aplicación: no deja adivinar cuántas reservas hay ni recorrerlas.
    id CHAR(36) CHARACTER SET ascii NOT NULL,
    funcion_id CHAR(36) CHARACTER SET ascii NOT NULL,
    usuario_id CHAR(36) CHARACTER SET ascii NOT NULL,
    -- Lo que el espectador muestra en la puerta y el organizador tipea para registrar la
    -- asistencia: corto para dictarlo, y aparte del id para no exponerlo.
    codigo CHAR(8) CHARACTER SET ascii NOT NULL,
    cantidad SMALLINT UNSIGNED NOT NULL,
    -- DECIMAL y no FLOAT, como el precio de la función. 0 es «a la gorra».
    total DECIMAL(10, 2) NOT NULL,
    -- confirmada ocupa lugares; cancelada los libera; asistio es una confirmada que ya pasó por la
    -- puerta, y los sigue ocupando.
    estado ENUM('confirmada', 'cancelada', 'asistio') NOT NULL DEFAULT 'confirmada',
    creada_en DATETIME NOT NULL,
    PRIMARY KEY (id),
    -- Lo controla la base, así dos reservas a la vez no salen con el mismo código.
    UNIQUE INDEX reserva_por_codigo (codigo),
    -- Los lugares ocupados de una función y la lista del organizador.
    INDEX reserva_por_funcion (funcion_id, estado),
    -- «Mis reservas», de la más nueva a la más vieja.
    INDEX reserva_por_usuario (usuario_id, creada_en),
    -- El panel del administrador, filtrado por estado.
    INDEX reserva_por_estado (estado, creada_en),
    -- La aplicación ya lo valida; esto cuida la tabla si alguien escribe por otro lado.
    CONSTRAINT reserva_cantidad_positiva CHECK (cantidad > 0),
    CONSTRAINT reserva_total_no_negativo CHECK (total >= 0)
) ENGINE = InnoDB;
