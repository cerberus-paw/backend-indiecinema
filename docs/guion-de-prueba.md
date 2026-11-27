# Guion de prueba

El recorrido de IndieCinema 1.0.0, con los cinco subsistemas. Pantalla → backend → base de
datos → pantalla, con cada rol. Se corre en el sitio de desarrollo (https://indiecinema-dev.duckdns.org)
antes del PR de `dev` a `main`, y en el publicado (https://indiecinema.duckdns.org) antes de cada
tag. Si un paso falla, se corrige y se vuelve a correr entero.

Hacen falta dos ventanas del navegador, porque el organizador y los espectadores actúan a la vez:
una normal, para el organizador y el administrador, y una privada, para los espectadores. También
una foto cualquiera (jpg o png) y un PDF cualquiera para las solicitudes de sala, que piden el
seguro y la habilitación.

| Rol | Correo | Contraseña |
|---|---|---|
| Espectador nuevo | uno que se crea en el paso 8 | la que se elija |
| Espectadora | `eva@indiecinema.test` | en la entrega de Classroom |
| Organizador | `organizador@indiecinema.test` | en la entrega de Classroom |
| Administrador | `administrador@indiecinema.test` | en la entrega de Classroom |

El administrador también hace todo lo del moderador: en producción no hay una cuenta de moderador
de prueba.

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
| 17 | Abrir `/moderacion/solicitudes` | «No tenés permiso para entrar acá» |

## La votación y el acuerdo de fecha

| Paso | Qué hacer | Qué se tiene que ver |
|---|---|---|
| 18 | (Privada) Ingresar como Eva, abrir la función del paso 16, votar una obra y después cambiar el voto a otra | «Esta es tu elección actual» sobre la obra votada. El voto se cambia sin sumar dos |
| 19 | (Normal) Como organizador, abrir la ficha de esa función y «Cerrar votación» | Antes de cerrar ve cuántos votos lleva cada obra: 1 la última que votó Eva en el paso 18, y 0 las otras. Al cerrar quedan las más votadas que entran en la duración de la sala, tantas como películas por función tiene (en un empate, la que va primero entre las candidatas), y lleva a «Proponer franjas horarias» |
| 20 | Proponer una franja que termina antes de empezar. Después, dos franjas de pasado mañana en adelante (la reserva del paso 27 se cancela hasta 24 horas antes) con un mínimo de 1 asistente, y «Publicar franjas y abrir acuerdo» | Primero, el error junto a la opción. Después la función pasa a «En acuerdo de fecha», con las dos franjas y la fecha límite: las 23:59 del día anterior a la primera |
| 21 | (Privada) Como Eva, marcar «Me sirve» en las dos franjas; desmarcar una y volver a marcarla | Cada franja cuenta a los que la marcaron, y se puede marcar más de una |
| 22 | (Normal) Recargar la ficha y «Confirmar franja ganadora y programar función» | Antes de confirmar, «¡La franja ganadora alcanzó el mínimo requerido!». Después la función queda programada con esa fecha, y aparece en la cartelera y en la portada |

## Reservas (ventana privada, como Eva)

| Paso | Qué hacer | Qué se tiene que ver |
|---|---|---|
| 23 | Abrir la ficha de la función del paso 22 en dos pestañas | «3 lugares libres» y la barra vacía |
| 24 | En la primera pestaña, pedir 0 lugares, después 4, y después reservar 2 con «Reservar mis lugares» | 0 y 4 dan el error debajo del campo, sin mandar nada. Con 2, «Reserva confirmada» en el lugar del formulario, sin recargar, con «Ver el comprobante». La ficha pasa a «Queda 1 lugar libre» |
| 25 | Pasar a la segunda pestaña | Sola, sin recargar, también dice «Queda 1 lugar libre», y el campo no acepta más de 1 |
| 26 | Abrir «Mis reservas» desde el menú de la cuenta, y el comprobante de la reserva | La reserva con su código, sus lugares y «confirmada». El comprobante se puede imprimir. Al buzón llegó el correo de la reserva confirmada |
| 27 | En el comprobante, «Cancelar reserva» y confirmar | Antes de cancelar, «Podés cancelarla hasta el …», 24 horas antes de la función. Después, «Reserva cancelada»: los 2 lugares vuelven a estar libres |
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

## Moderación

| Paso | Qué hacer | Qué se tiene que ver |
|---|---|---|
| 39 | (Privada) Como Eva, «Abrir una sala» con la foto y el PDF como seguro y como habilitación | La solicitud queda pendiente |
| 40 | (Normal) Como administrador, en «Solicitudes de sala» (`/moderacion/solicitudes`), abrir la de Eva y tratar de aprobarla sin tildar los cuatro requisitos | No deja: aprobar pide el checklist completo (seguro vigente, habilitación, capacidad y condiciones) |
| 41 | Devolverla marcando en «Qué falta, si la devolvés» el seguro, con un motivo | Pasa a «Esperando al solicitante». A Eva le llega al buzón un correo con lo que falta y el plazo, 15 días |
| 42 | (Privada) Como Eva, abrir otra vez «Abrir una sala» | Dice qué falta y hasta cuándo. Subir el PDF y reenviarla |
| 43 | (Normal) Abrirla, tildar los cuatro requisitos y aprobarla con un motivo | La sala aparece en «Salas» y Eva pasa a organizadora: en su ventana, al recargar, tiene el menú del organizador y la sala en «Mis salas» |
| 44 | Como organizador, cargar una obra nueva en «Mis obras». Después, como administrador, abrir «Obras» (`/moderacion/obras`) y aprobarla | Hasta que se aprueba no está en «Películas» ni se puede programar. Aprobada, aparece en el catálogo |
| 45 | (Privada) Como Eva, en la ficha de Espacio Cultural La Trocha, «Denunciar», elegir «Seguridad del lugar» y «Enviar la denuncia» | La denuncia queda registrada. Es un motivo de gravedad alta |
| 46 | (Normal) Como administrador, abrir «Denuncias» (`/moderacion/denuncias`) | La Trocha figura «Para suspender», con la denuncia de Eva |
| 47 | Elegirla, marcar «Suspender la sala» con un motivo y «Enviar resolución» | La Trocha sale de «Salas» y aparece en «Salas suspendidas por denuncia». Lo que ya tenía programado sigue en pie |
| 48 | En esa lista, «Levantar la suspensión» con un motivo | La Trocha vuelve a «Salas» |

## Mercado Pago

Sólo con el cobro prendido (`[mercadopago] activo = true` en funciones, como en el sitio de
desarrollo). Con el cobro apagado, una función paga se confirma sin cobrar, como en el paso 24, y
estos pasos se saltean. Se paga con la sesión del comprador de prueba de Mercado Pago, nunca con
una cuenta de verdad: titular `APRO` para un pago aprobado y `OTHE` para uno rechazado, con una de
las tarjetas de prueba de Checkout Pro.

| Paso | Qué hacer | Qué se tiene que ver |
|---|---|---|
| 49 | (Privada) Como Eva, reservar 1 lugar en una función paga de la demo, a más de 24 horas | Antes de confirmar, la política de cancelación. Después, la reserva queda pendiente de pago, retiene el lugar y lleva a Mercado Pago |
| 50 | Pagar con titular `APRO` | Vuelve al comprobante con la reserva confirmada: la confirma el aviso de Mercado Pago, no la vuelta del navegador. Al buzón llega el correo de la reserva confirmada |
| 51 | En «Mis reservas», cancelarla | Queda cancelada y reembolsada, con la fecha, y el lugar vuelve a estar libre |
| 52 | Reservar otra vez y pagar con titular `OTHE` | Mercado Pago lo rechaza y la reserva sigue pendiente. A los 15 minutos vence y su lugar se libera (la tarea `liberar-vencidas`, cada 5 minutos, la pasa a cancelada) |

## Recaudación

| Paso | Qué hacer | Qué se tiene que ver |
|---|---|---|
| 53 | (Privada) Como Eva, abrir «Mis puntos» (`/recaudacion/puntos`) y «Beneficios» (`/recaudacion/beneficios`) | El saldo: 10 puntos por cada asistencia registrada en la puerta. Con 100 o más, «Canjear» da un cupón del 20 % que vence a los 60 días y se carga al reservar. Beneficios lista los comercios adheridos |
| 54 | (Normal) Como administrador, abrir «Reparto» (`/recaudacion/administracion/reparto`) y cargar un cambio que no sume 100 % | El reparto vigente (70 % la sala, 15 % los dueños de las obras y 15 % la plataforma) y su historial. El cambio no se guarda: los tres tienen que sumar 100 %. En producción, no guardar uno válido |
| 55 | Abrir «Liquidaciones» (`/recaudacion/administracion/liquidaciones`) y elegir un mes con funciones pagas finalizadas | Lo que le toca a cada organizador y a cada dueño en el mes. «Marcar transferida», con una nota, la pasa a transferida con la fecha |
| 56 | Como organizador, abrir «Mis liquidaciones» (`/recaudacion/liquidaciones`) | Lo liquidado y lo que falta transferir, mes por mes, con lo que se cobró en cada función y su parte. El reparto se hace después de descontar la comisión de Mercado Pago |
| 57 | Abrir «Reportes» (`/recaudacion/reportes`) y recorrer las pestañas. Después, «Descargar CSV» de las funciones | Gráficos de lo vendido, la plata de cada sala contra su costo, el embudo de conversión y el ranking del público. Cada recomendación dice por qué la da, y «Programarla» abre el alta de la función ya cargada. El CSV suma lo mismo que el resumen |
| 58 | Como administrador, en «Comercios» (`/recaudacion/administracion/comercios`), dar de alta uno y después desactivarlo | Activo, aparece en «Beneficios». Desactivado, deja de aparecer |

## Al terminar

- Como organizador, cancelar las funciones de los pasos 14 y 16, con su confirmación: salen de la
  cartelera y quedan en «Mis funciones» como canceladas. Se cancelan también sus reservas
  confirmadas, y a cada espectador le llega el aviso por correo. La reserva con asistencia queda
  como está.
- Como administrador, retirar la sala del paso 11 desde «Solicitudes de sala» («Retirar del
  sitio») y dejar al usuario del paso 8 como espectador. Lo mismo con la sala de Eva (paso 43): se
  retira y Eva vuelve a espectadora. Así el sitio queda con los datos de la demo.
