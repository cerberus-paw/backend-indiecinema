# Guion de prueba

El recorrido completo de la Entrega 3 en el sitio publicado, https://indiecinema.duckdns.org:
pantalla → backend → base de datos → pantalla, con cada rol. Se corre de punta a punta antes de
cada tag; si un paso falla, se corrige y se vuelve a correr entero.

Usuarios:

| Rol | Correo | Contraseña |
|---|---|---|
| Espectador | uno nuevo, que se crea en el paso 7 | la que se elija |
| Organizador | `organizador@indiecinema.test` | en la entrega de Classroom |
| Administrador | `administrador@indiecinema.test` | en la entrega de Classroom |

Las salas, obras y funciones de la demo son del organizador. La función que se programa en el
paso 13 se cancela en el 15, así no queda en la cartelera.

## Sin sesión

| Paso | Qué hacer | Qué se tiene que ver |
|---|---|---|
| 1 | Abrir `http://indiecinema.duckdns.org` | Pasa a `https://`, con el candado. La portada muestra las próximas funciones |
| 2 | Ir a «Cartelera», buscar un título y filtrar por localidad, fecha y género | Sólo las funciones que cumplen todo. Cambiar de página conserva los filtros. «Limpiar» los saca |
| 3 | «Descargar CSV» con los filtros puestos | Un archivo con las mismas funciones, que abre bien en una planilla |
| 4 | Abrir una función | La ficha con las obras, la sala, la fecha, el precio y el cupo. «Ver sala y cartelera» lleva a la sala con sus próximas funciones |
| 5 | Abrir `/cartelera/` seguido de cualquier texto | La página de error, con el enlace a la portada |
| 6 | Abrir `/organizador/funciones` | Pide ingresar |

## Espectador

| Paso | Qué hacer | Qué se tiene que ver |
|---|---|---|
| 7 | «Crear cuenta»: primero con una contraseña de 4 letras y la repetición distinta, después bien | Primero, el error junto a cada campo, sin perder lo cargado. Después, «¡Cuenta creada!» |
| 8 | «Ingresar» con una contraseña equivocada y después con la buena | Primero un error que no dice si falló el correo o la contraseña. Después, el nombre en el encabezado |
| 9 | Abrir `/organizador/funciones` | «No tenés permiso para entrar acá» |
| 10 | «Abrir una sala»: completar la solicitud con una foto | La solicitud queda pendiente y llega al buzón del equipo (correo simulado) |
| 11 | «Cerrar sesión» | Vuelve como visitante |

## Organizador

| Paso | Qué hacer | Qué se tiene que ver |
|---|---|---|
| 12 | Ingresar como organizador y abrir «Mis funciones» | Sólo las funciones de sus salas, con estado y fecha |
| 13 | «Programar una función»: primero con una fecha pasada y un cupo mayor que la capacidad de la sala, después bien | Primero los dos errores. Después la función aparece en «Mis funciones» y en la cartelera |
| 14 | «Editar» esa función y cambiarle el precio | «¡Cambios guardados!» en la ficha, con el precio nuevo |
| 15 | «Cancelar» y confirmar | Sale de la cartelera y queda en «Mis funciones» como cancelada |
| 16 | Abrir `/administracion/solicitudes` | «No tenés permiso para entrar acá» |

## Administrador

| Paso | Qué hacer | Qué se tiene que ver |
|---|---|---|
| 17 | Ingresar como administrador y abrir «Solicitudes de sala» | La solicitud del paso 10 primera entre las pendientes |
| 18 | Aprobarla con un motivo | La sala aparece en «Salas», y el espectador del paso 7 pasa a organizador |
| 19 | En «Usuarios y roles», buscar al usuario del paso 7 | Figura como organizador. Si se lo vuelve a espectador y se guarda, su sesión se cierra |

Al terminar, el usuario del paso 7 se deja como espectador y la sala del paso 10 se retira desde la
bandeja («Retirar del sitio»), así el sitio queda con los datos de la demo.
