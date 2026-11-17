-- El reparto inicial de la propuesta: 70 % para la sala, 15 % para el dueño de la obra y 15 % para
-- la plataforma (EF-08.1 / IC-559). Queda cargado desde el principio para que toda función
-- finalizada tenga un parámetro vigente en su fecha_hora, incluidas las que ya pasaron: de ahí la
-- vigencia en 2000, muy anterior a cualquier función del sitio.
--
-- El id es un UUID v4 fijo, como los que genera la aplicación (UUID() de MySQL es v1): así la fila
-- es la misma en todas las instalaciones. cargado_por queda nulo: no lo cargó un administrador.
INSERT INTO parametro_reparto (id, sala, dueno, plataforma, vigente_desde, cargado_por)
VALUES ('3b7c9a10-5f41-4a8e-9c2d-6e0f1a2b3c4d', 70.00, 15.00, 15.00, '2000-01-01 00:00:00', NULL);
