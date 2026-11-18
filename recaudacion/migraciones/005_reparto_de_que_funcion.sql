-- De qué función fue cada reparto, como era en ese momento (EF-08.3): la fecha, la sala y los
-- títulos que se proyectaron. Es lo que muestra el detalle de la liquidación, y por eso se guarda
-- en vez de pedírselo a programación cada vez: una liquidación es el comprobante de lo que se pagó
-- y tiene que seguir diciendo de qué función fue aunque la sala se dé de baja o la obra cambie de
-- título. Además, así la pantalla no se cae si programación no responde.
--
-- Nulas para los repartos hechos antes de esta migración, que no tienen de dónde sacarlas.
ALTER TABLE reparto
    ADD COLUMN funcion_fecha_hora DATETIME NULL,
    ADD COLUMN sala_nombre VARCHAR(100) NULL,
    ADD COLUMN titulos VARCHAR(500) NULL;
