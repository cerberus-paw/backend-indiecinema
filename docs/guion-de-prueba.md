# Guion de prueba

El recorrido de lo que está hecho en las entregas 3 y 4. Pantalla → backend → base de
datos → pantalla, con cada rol. Se corre en el sitio de desarrollo (https://indiecinema-dev.duckdns.org)
antes del PR de `dev` a `main`, y en el publicado (https://indiecinema.duckdns.org) antes de cada
tag. Si un paso falla, se corrige y se vuelve a correr entero.

Hacen falta dos ventanas del navegador, porque el organizador y los espectadores actúan a la vez:
una normal, para el organizador y el administrador, y una privada, para los espectadores. También
una foto cualquiera (jpg o png) para la solicitud de sala.

| Rol | Correo | Contraseña |
|---|---|---|
| Espectador nuevo | uno que se crea en el paso 8 | la que se elija |
| Espectadora | `eva@indiecinema.test` | en la entrega de Classroom |
| Organizador | `organizador@indiecinema.test` | en la entrega de Classroom |
| Administrador | `administrador@indiecinema.test` | en la entrega de Classroom |

Las salas, las obras y las funciones de la demo son del organizador. Las dos funciones que se
crean en el guion (pasos 14 y 16) se cancelan al final, así no quedan en la cartelera.

## Sin sesión

| Paso | Qué hacer | Qué se tiene que ver |
|---|---|---|
| 1 | Abrir el sitio con `http://` (`http://indiecinema-dev.duckdns.org` en desarrollo, `http://indiecinema.duckdns.org` en producción) | Pasa a `https://`, con el candado. La portada muestra el carrusel de destacadas, las próximas funciones y algunas salas |
| 2 | En el carrusel, esperar, usar las flechas, tocar una miniatura y, con el foco adentro, las teclas ← y → | Pasa solo cuando terminan de cargar las imágenes. Se detiene con el botón de pausa o con el mouse encima, y cada control lleva a la diapositiva que corresponde |
| 3 | Ir a «Cartelera» y escribir parte de un título en «Buscar», sin apretar Enter | La lista se filtra sin recargar la página |
| 4 | Filtrar por localidad, fecha y género, y cambiar de página | Sólo las funciones que cumplen todo. Cambiar de página conserva los filtros. «Limpiar» los saca |
| 5 | «Descargar CSV» con los filtros puestos | Un archivo con las mismas funciones, que abre bien en una planilla |
| 6 | Abrir una función programada | La ficha con los afiches y las obras, la sala, la fecha, el precio, los lugares libres (el número y la barra de lo reservado) y «Ingresá con tu cuenta para reservar». «Compartir» abre el menú de compartir del navegador o, si no tiene, copia el enlace |
| 7 | Desde la ficha, ir a la sala. Después abrir `/cartelera/` seguido de cualquier texto, `/interno/sesion` y `/organizador/funciones` | La sala con su foto y sus próximas funciones. La página de error con el enlace a la portada. `/interno/` da 404 desde afuera. El panel del organizador pide ingresar |

## Espectador nuevo (ventana privada)

| Paso | Qué hacer | Qué se tiene que ver |
|---|---|---|
| 8 | «Crear cuenta»: primero con una contraseña de 4 letras y la repetición distinta, después bien | Primero, el error junto a cada campo, sin perder lo cargado. Después, «¡Cuenta creada!» |
| 9 | «Ingresar» con una contraseña equivocada y después con la buena | Primero un error que no dice si falló el correo o la contraseña. Después, el nombre en el encabezado |
| 10 | Abrir `/organizador/funciones` | «No tenés permiso para entrar acá» |
| 11 | «Abrir una sala»: escribir en un campo obligatorio, borrarlo y pasar al siguiente. Después arrastrar la foto hasta la zona de la imagen y completar el resto | El error aparece debajo del campo al dejarlo, antes de mandar, y se apaga al corregirlo. La foto se ve en la vista previa. Al mandar, la solicitud queda pendiente y llega al buzón del equipo (correo simulado) |
| 12 | «Cerrar sesión» | Vuelve como visitante |

## Organizador (ventana normal)

| Paso | Qué hacer | Qué se tiene que ver |
|---|---|---|
| 13 | Ingresar como organizador y abrir «Mis funciones» | Sólo las funciones de sus salas, con estado y fecha |
| 14 | «Programar una función»: escribir un precio negativo y pasar al campo siguiente; mandarla con una fecha pasada y un cupo mayor que la capacidad de la sala; después, bien | El error del precio aparece antes de mandar. Los de la fecha y el cupo, al mandar, junto a cada campo. Después la función aparece en «Mis funciones» y en la cartelera |
| 15 | «Editar» esa función y cambiarle el precio | «¡Cambios guardados!» en la ficha, con el precio nuevo |
| 16 | «Programar una función» con «Abrir a votación de títulos»: tres obras candidatas, cupo 3 | Queda en «Mis funciones» en votación y sin fecha, y en la cartelera con «En votación de títulos» |
| 17 | Abrir `/administracion/solicitudes` | «No tenés permiso para entrar acá» |

## La votación y el acuerdo de fecha

| Paso | Qué hacer | Qué se tiene que ver |
|---|---|---|
| 18 | (Privada) Ingresar como Eva, abrir la función del paso 16, votar una obra y después cambiar el voto a otra | «Esta es tu elección actual» sobre la obra votada. El voto se cambia sin sumar dos |
| 19 | (Normal) Como organizador, abrir la ficha de esa función y «Cerrar votación» | Antes de cerrar ve cuántos votos lleva cada obra: 1 la última que votó Eva en el paso 18, y 0 las otras. Al cerrar gana la más votada y lleva a «Proponer franjas horarias» |
| 20 | Proponer una franja que termina antes de empezar. Después, dos franjas de mañana en adelante con un mínimo de 1 asistente, y «Publicar franjas y abrir acuerdo» | Primero, el error junto a la opción. Después la función pasa a «En acuerdo de fecha», con las dos franjas y la fecha límite: las 23:59 del día anterior a la primera |
| 21 | (Privada) Como Eva, marcar «Me sirve» en las dos franjas; desmarcar una y volver a marcarla | Cada franja cuenta a los que la marcaron, y se puede marcar más de una |
| 22 | (Normal) Recargar la ficha y «Confirmar franja ganadora y programar función» | Antes de confirmar, «¡La franja ganadora alcanzó el mínimo requerido!». Después la función queda programada con esa fecha, y aparece en la cartelera y en la portada |

## Reservas (ventana privada, como Eva)

| Paso | Qué hacer | Qué se tiene que ver |
|---|---|---|
| 23 | Abrir la ficha de la función del paso 22 en dos pestañas | «3 lugares libres» y la barra vacía |
| 24 | En la primera pestaña, pedir 0 lugares, después 4, y después reservar 2 con «Reservar mis lugares» | 0 y 4 dan el error debajo del campo, sin mandar nada. Con 2, «Reserva confirmada» en el lugar del formulario, sin recargar, con «Ver el comprobante». La ficha pasa a «Queda 1 lugar libre» |
| 25 | Pasar a la segunda pestaña | Sola, sin recargar, también dice «Queda 1 lugar libre», y el campo no acepta más de 1 |
| 26 | Abrir «Mis reservas» desde el menú de la cuenta, y el comprobante de la reserva | La reserva con su código, sus lugares y «confirmada». El comprobante se puede imprimir. Al buzón llegó el correo de la reserva confirmada |
| 27 | En el comprobante, «Cancelar reserva» y confirmar | «Reserva cancelada»: los 2 lugares vuelven a estar libres |
| 28 | Volver a la ficha, reservar 2 lugares y después 1 más | Con el cupo lleno, la ficha dice «Función agotada», sin el formulario, y la tarjeta de la cartelera, «Agotada» |
| 29 | Abrir otra función programada de la demo y pedir 7 lugares | «Se pueden reservar hasta 6 lugares por persona en cada función.» debajo del campo, sin mandar nada |

## Paneles de reservas

| Paso | Qué hacer | Qué se tiene que ver |
|---|---|---|
| 30 | (Normal) Como organizador, en «Mis funciones», «Reservas» de la función del paso 22 | Las tres reservas (la del paso 27, cancelada) con su código, a nombre de quién están, los lugares, el total y el estado. Arriba, 3 de 3 lugares reservados. «Exportar listado» baja el CSV, también con el nombre |
| 31 | Registrar la asistencia de la reserva confirmada de 2 lugares, con su código tal como lo muestra el comprobante | Como la función todavía no empezó, el error «La asistencia se registra desde una hora antes de la función: desde las … del …», y la reserva sigue confirmada. Desde una hora antes hasta que termina, pasa a «con asistencia» sin recargar la lista y suma en «Asistentes registrados» |
| 32 | Cerrar sesión e ingresar como administrador. Abrir «Solicitudes de sala» | La solicitud del paso 11, primera entre las pendientes |
| 33 | Aprobarla con un motivo | La sala aparece en «Salas», y el espectador del paso 8 pasa a organizador |
| 34 | En «Usuarios y roles», buscar al usuario del paso 8 | Figura como organizador. Si se lo vuelve a espectador y se guarda, su sesión se cierra |
| 35 | En «Reservas», filtrar por «confirmada» y anular la reserva de 1 lugar | Queda cancelada sin recargar la lista, y la función deja de estar agotada: vuelve a quedar 1 lugar |

## Fichas y cuenta

| Paso | Qué hacer | Qué se tiene que ver |
|---|---|---|
| 36 | Abrir «Películas» en el menú y buscar parte de un título | El catálogo con las películas de la demo, cada una con su afiche. El buscador deja sólo las que coinciden |
| 37 | Abrir la ficha de una película | El afiche, la dirección, la duración, los géneros, la sinopsis y sus próximas funciones, cada una con el enlace a su ficha |
| 38 | Abrir «Mi cuenta» desde el menú de la cuenta | «Mis datos»: el nombre, el correo y los roles, y el enlace a «Mis reservas» |

## Al terminar

- Como organizador, cancelar las funciones de los pasos 14 y 16, con su confirmación: salen de la
  cartelera y quedan en «Mis funciones» como canceladas. Se cancelan también sus reservas
  confirmadas, y a cada espectador le llega el aviso por correo. La reserva con asistencia queda
  como está.
- Como administrador, retirar la sala del paso 11 desde «Solicitudes de sala» («Retirar del
  sitio») y dejar al usuario del paso 8 como espectador. Así el sitio queda con los datos de la
  demo.
