-- Las solicitudes de sala que había en programación, con los datos de su sala, que hasta EF-07.2
-- eran la sala propuesta: se mudan a moderación. La foto queda en la sala de programación (imagen
-- nula), y la bandeja la muestra desde allá. Sólo las de salas que no se dieron de baja: las
-- demás tampoco se veían en la bandeja.
--
-- Lee el esquema de programación: el usuario de migraciones tiene permisos sobre los dos. Corre
-- después de las de programación (migrar.sh las aplica en ese orden), así que en una base nueva la
-- tabla de allá ya existe, vacía. La borra la migración que sigue.
INSERT INTO solicitud_sala (
    id, sala_id, usuario_id, solicitante_nombre, nombre, descripcion, direccion, localidad, capacidad,
    peliculas_por_funcion, duracion_funcion, tiempo_entre_funciones, imagen, estado, enviada_en,
    resuelta_por, resuelta_en, motivo
)
SELECT so.id, so.sala_id, so.usuario_id, so.solicitante_nombre, sa.nombre, sa.descripcion, sa.direccion, sa.localidad,
       sa.capacidad, sa.peliculas_por_funcion, sa.duracion_funcion, sa.tiempo_entre_funciones, NULL, so.estado,
       so.enviada_en, so.resuelta_por, so.resuelta_en, so.motivo
FROM programacion.solicitud_sala so
JOIN programacion.sala sa ON sa.id = so.sala_id AND sa.dada_de_baja_en IS NULL;
