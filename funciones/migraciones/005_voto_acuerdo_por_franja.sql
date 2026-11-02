-- «Me sirve» en varias franjas (E3-13.F7).
--
-- El wireframe «funcion-acuerdo-de-fecha» dice «Podés marcar más de una»: un espectador marca
-- todas las franjas a las que puede ir, y cada franja cuenta a los que la marcaron. La clave única
-- pasa de usuario y función (un voto por función, de 004) a usuario y franja: la misma franja no
-- se marca dos veces. El índice por acuerdo y usuario queda, sin ser único, para buscar las
-- franjas que marcó cada uno y para la clave foránea del acuerdo.
ALTER TABLE voto_acuerdo
    ADD INDEX voto_acuerdo_por_acuerdo_y_usuario (acuerdo_id, usuario_id),
    ADD UNIQUE INDEX voto_acuerdo_por_usuario_y_franja (franja_id, usuario_id),
    DROP INDEX voto_acuerdo_por_usuario_y_acuerdo,
    DROP INDEX voto_acuerdo_por_usuario_y_funcion;
