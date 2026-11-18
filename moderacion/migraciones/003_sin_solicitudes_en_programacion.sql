-- Programación ya no tiene solicitudes de sala (EF-07.2): la anterior las copió acá. Va en
-- moderación y no en programación porque migrar.sh aplica primero las de programación, y la
-- tabla se tiene que borrar después de copiarla.
DROP TABLE programacion.solicitud_sala;
