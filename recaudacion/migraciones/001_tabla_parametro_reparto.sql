-- Los porcentajes con los que se reparte lo cobrado de una función paga entre la sala, el dueño de
-- la obra y la plataforma (EF-08.1 / IC-559). La propuesta dice que la recaudación se reparte
-- entre los tres, y acá está con cuánto.
--
-- No se corrige una fila: cada cambio es una fila nueva con su fecha de vigencia, y las anteriores
-- quedan como historial. Para cada función vale el parámetro vigente en su fecha_hora, así un
-- cambio no mueve el reparto de las funciones que ya pasaron (EF-08.2 lo usa al repartir).
CREATE TABLE parametro_reparto (
    -- UUID v4 generado por la aplicación, como en el resto del sitio.
    id CHAR(36) CHARACTER SET ascii NOT NULL,
    -- Porcentajes, no montos: DECIMAL y no FLOAT, porque la suma tiene que dar 100 exacto. Con dos
    -- decimales entran repartos como 33,33 / 33,33 / 33,34.
    sala DECIMAL(5,2) NOT NULL,
    dueno DECIMAL(5,2) NOT NULL,
    plataforma DECIMAL(5,2) NOT NULL,
    -- Desde cuándo rige. Puede ser futura: el administrador anuncia el cambio antes de que valga.
    vigente_desde DATETIME NOT NULL,
    -- El administrador que lo cargó; nulo en el que carga la migración, que no lo cargó nadie.
    cargado_por CHAR(36) CHARACTER SET ascii NULL,
    cargado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- Dos parámetros con la misma vigencia dejarían sin respuesta «cuál rige en esta fecha»; y es
    -- el índice con el que se busca el vigente en la fecha de una función.
    UNIQUE INDEX parametro_reparto_vigencia_unica (vigente_desde),
    -- La aplicación avisa antes, pero la que decide es la base: un reparto que no suma 100 dejaría
    -- plata sin dueño o repartiría más de lo cobrado.
    CONSTRAINT parametro_reparto_suma_cien CHECK (sala + dueno + plataforma = 100.00),
    CONSTRAINT parametro_reparto_sin_negativos CHECK (sala >= 0 AND dueno >= 0 AND plataforma >= 0)
) ENGINE = InnoDB;
