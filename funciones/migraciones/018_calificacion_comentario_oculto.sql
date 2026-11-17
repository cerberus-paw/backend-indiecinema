-- El comentario de una calificación que la moderación ocultó por una denuncia (EF-07.8). El texto
-- queda, para el registro, pero deja de mostrarse en las fichas; el puntaje sigue contando en los
-- promedios. Quién lo ocultó es un moderador, de cuentas: sin clave foránea.
ALTER TABLE calificacion
    ADD COLUMN comentario_oculto_en DATETIME NULL,
    ADD COLUMN comentario_oculto_por CHAR(36) CHARACTER SET ascii NULL;
