# Seguridad por capa

Qué protege cada capa de IndieCinema (red, sistema operativo, base de datos y aplicación), dónde
está configurado y cómo se comprueba; después, las amenazas ordenadas por impacto y probabilidad;
al final, qué se hace en cada etapa del ciclo de vida. Red y
sistema operativo describen el VPS de https://indiecinema.duckdns.org, armado el 18/10/2026 y
verificado de nuevo el 08/11/2026: una instancia EC2 de AWS (t3.small) con IP elástica, donde corre
el `docker-compose.yml` de este repositorio. Desde el 03/11 hay además un servidor de desarrollo,
en otra EC2 (README, «Ramas y entornos»), con los mismos controles salvo lo que dice «El servidor
de desarrollo», al final de «Sistema operativo».

## Red

| Control | Dónde | Cómo se comprueba |
|---|---|---|
| Cortafuegos fuera de la máquina: sólo entran el 22 (SSH), el 80 y el 443, por IPv4. El SSH queda abierto a cualquier origen en los dos servidores porque el despliegue continuo entra a los dos desde las máquinas de GitHub Actions, que no tienen IP fija; lo cuidan la clave, fail2ban y que la clave del despliegue sólo puede correr el script (capa de sistema operativo) | reglas de entrada del security group de la instancia en AWS (ufw no se usa) | consola de EC2 → la instancia → Security |
| Sólo HTTPS. Por el 80 se redirige a HTTPS (301) y se atiende el desafío de Let's Encrypt | `nginx/templates/servidor-https.inc.template`, que se activa desde `.env` con `docker-compose.https.yml` | `curl -I http://indiecinema.duckdns.org/` |
| TLS 1.2 y 1.3, sin versiones anteriores, y cifrados con secreto hacia adelante (ECDHE) | `ssl_protocols` y `ssl_ciphers` en la misma plantilla | `openssl s_client -tls1_1 …` responde `protocol version` |
| Certificado de Let's Encrypt que se renueva solo: certbot por webroot y un hook que recarga nginx | `certbot.timer` en el servidor (README, «HTTPS en el VPS») | `sudo certbot certificates` |
| HSTS por un año: el navegador ya no intenta por HTTP | `Strict-Transport-Security` en `servidor-https.inc.template` | `curl -I https://indiecinema.duckdns.org/` |
| Cabeceras contra el sniffing de tipos, el clickjacking y la fuga del Referer, y sin la versión de nginx | `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy` y `server_tokens off` en `nginx/templates/sitio.inc.template` | la misma respuesta |
| Lo que el sitio no usa, apagado (cámara, micrófono, ubicación, pagos y USB), y aislado de otros orígenes: una ventana de otro sitio no tiene referencia a la nuestra, otro sitio no puede incrustar nuestras respuestas, y la página sólo carga lo que lo permite | `Permissions-Policy`, `Cross-Origin-Opener-Policy`, `Cross-Origin-Resource-Policy` y `Cross-Origin-Embedder-Policy` en `nginx/templates/sitio.inc.template` | `nginx/comprobar-desde-afuera.sh` |
| Content-Security-Policy: el navegador sólo carga scripts, estilos, fuentes e imágenes del mismo sitio, y nada en línea. Si un texto se escapara del escapado de Twig, el script que trajera no corre. Por eso el front sirve su fuente y no tiene `onclick` ni `style` en el HTML | `Content-Security-Policy` en `nginx/templates/sitio.inc.template` | `nginx/comprobar-desde-afuera.sh`, que corre la CI; las pruebas de punta a punta fallan si una página viola la política |
| Un solo punto de entrada: sólo nginx publica puertos. MySQL y los subsistemas quedan en la red interna del compose | `ports` de `docker-compose.yml` | en el servidor escuchan sólo el 22, el 80 y el 443 (`ss -tln`) |
| El buzón de prueba (Mailpit), que simula el envío de correos, sólo escucha en la máquina | `127.0.0.1:8025` en `docker-compose.yml` | se ve con un túnel: `ssh -L 8025:127.0.0.1:8025 …` |
| Las rutas `/interno/` (las APIs entre subsistemas) y el `auth_request` de la sesión no se alcanzan desde afuera | `nginx/templates/sitio.inc.template` | `nginx/comprobar-desde-afuera.sh https://indiecinema.duckdns.org`, que también corre la CI |
| Límite de pedidos por IP al ingresar, registrarse, pedir una sala, reservar y buscar (la cartelera, su CSV y las obras). Quien se pasa lee que espere un minuto: en JSON si lo pidió el JavaScript, como la reserva, y en una página si no | `limit_req_zone` en `nginx/templates/indiecinema.conf.template`; `limit_req` y `error_page 429` en `nginx/templates/sitio.inc.template` | `nginx/comprobar-desde-afuera.sh`: superar el límite de la reserva o de la búsqueda responde 429 con el mensaje |
| Los metadatos de la instancia (de donde saldrían credenciales de AWS) sólo con IMDSv2, que pide un token | configuración de la instancia en AWS | sin token, `curl http://169.254.169.254/latest/meta-data/` responde 401 |

## Sistema operativo

| Control | Dónde | Cómo se comprueba |
|---|---|---|
| Ubuntu 26.04 LTS con las actualizaciones de seguridad automáticas | `unattended-upgrades` activo y `/etc/apt/apt.conf.d/20auto-upgrades` | `systemctl status unattended-upgrades` |
| SSH sólo con clave: sin contraseñas y sin entrar como root | `PasswordAuthentication no` y `KbdInteractiveAuthentication no`, que trae la imagen de Ubuntu de AWS, y `PermitRootLogin no` en `/etc/ssh/sshd_config.d/10-sin-root.conf` (README, «Preparar el servidor») | `sudo sshd -T` |
| Bloqueo de quien prueba claves por SSH: 5 intentos en 10 minutos dejan la IP 10 minutos afuera. El 08/11 llevaba 327 intentos fallidos y 47 IP bloqueadas | jaula `sshd` de fail2ban, con la unidad de SSH de Ubuntu (`ssh.service`; la del paquete mira la de Debian y no ve nada) (README, «Preparar el servidor») | `sudo fail2ban-client status sshd` |
| Las claves del despliegue continuo, una por servidor y guardada en el environment de GitHub de cada uno (`produccion` y `desarrollo`), sólo corren `herramientas/desplegar.sh`: no eligen el comando ni abren una terminal o un túnel. La de un servidor no entra al otro | `restrict,command=…` en su línea de `~/.ssh/authorized_keys` (README, «Despliegue continuo») | con esa clave, `ssh … id` despliega en vez de correr `id` |
| Un solo usuario con acceso, `ubuntu`, que despliega con Docker sin ser root | grupo `docker` | `id ubuntu` |
| Los secretos no están en el repositorio, sólo los lee el usuario del despliegue, y gitleaks revisa cada pull request y cada push a `main` o a `dev`. Lo que necesita GitHub Actions está en los environments: la clave de cada servidor y, en `desarrollo`, las cuentas de las pruebas de punta a punta (el espectador en `E2E_CORREO` y `E2E_CLAVE`, y el organizador y el administrador en `E2E_ORGANIZADOR_*` y `E2E_ADMINISTRADOR_*`), que sólo existen en ese servidor | `.env` (600) y cada `config.ini` (640), que ignora `.gitignore`; `.gitleaks.toml` y la CI | `stat -c '%a %n' .env */config/config.ini` |
| Cada subsistema corre como `www-data`, sin ninguna capacidad de root, sin poder ganar privilegios y con el sistema de archivos de sólo lectura | `php/Dockerfile` y `x-subsistema` en `docker-compose.yml` (`cap_drop: [ALL]`, `no-new-privileges`, `read_only`) | `docker compose exec programacion id` |
| Un subsistema comprometido no lee el código ni los secretos de los otros: cada uno monta sólo su carpeta | `volumes` de cada subsistema en `docker-compose.yml` | — |
| Docker aísla los contenedores con AppArmor y seccomp | perfiles por defecto de Docker | `docker info` |

**El servidor de desarrollo**

Verificado el 08/11/2026 con los mismos comandos. Tiene los mismos controles que producción, con
dos diferencias:

- **Ubuntu 24.04 LTS**, no 26.04: se armó con la imagen de entonces. También recibe las
  actualizaciones de seguridad automáticas.
- **Es otra EC2, con otro dominio, otro certificado y otros secretos.** Su base tiene datos de
  prueba que se pueden borrar, y ahí corren las pruebas de punta a punta y el análisis de OWASP ZAP
  del despliegue continuo (ver «Por etapa»).

Hasta el 08/11 no tenía fail2ban, dejaba entrar como root con clave y el certificado se renovaba
con certbot `standalone`, que falla con nginx ocupando el 80. Se igualó a producción ese día, y los
pasos quedaron en el README («Preparar el servidor» y «HTTPS en el VPS») para el próximo servidor.

## Base de datos

MySQL 8.4 en su contenedor, sin puertos publicados (ver «Red»): sólo lo alcanzan los subsistemas
por la red interna del compose.

| Control | Dónde | Cómo se comprueba |
|---|---|---|
| Un esquema por subsistema, y un usuario por subsistema que sólo lee y escribe datos del suyo: sin crear, alterar ni borrar tablas, y sin ver los esquemas de los otros. Lo que un subsistema necesita de otro lo pide por la API interna | `mysql/init/01-esquemas-y-usuarios.sh` | `docker compose exec mysql bash /herramientas/comprobar-aislamiento.sh`: en el VPS, el 18/10, los 15 intentos dieron `command denied` |
| Las tablas las crea y las cambia sólo el usuario de migraciones, que la aplicación nunca usa. Ese usuario y root entran sólo desde adentro del contenedor de MySQL | `mysql/init/02-usuario-de-migraciones.sh` y `MYSQL_ROOT_HOST: localhost` en `docker-compose.yml` | `SELECT user, host FROM mysql.user`: `migraciones` y `root` en `localhost` |
| El registro de las migraciones aplicadas está en un esquema que ningún subsistema ve, con la huella de cada archivo: la aplicación no puede hacer que una se repita, y una migración editada después de aplicarse se rechaza | `control_migraciones` y `mysql/herramientas/migrar.sh` | `MigracionesTest` de cada subsistema |
| Las claves de los usuarios de MySQL salen de `.env` al crear la base: no están en el repositorio | variables `MYSQL_CLAVE_*` de `docker-compose.yml` | gitleaks en la CI |
| En la base no hay secretos en claro: de cada contraseña queda el hash Argon2id, y de cada sesión, el SHA-256 de su identificador | `usuario.contrasena_hash` y `sesion.huella` | `RegistroTest::testConTodoBienGuardaElUsuarioConArgon` |
| Sentencias preparadas de verdad, no emuladas por PDO, y conexión en `utf8mb4`: los datos nunca se mezclan con el SQL | `PDO::ATTR_EMULATE_PREPARES => false` en `nucleo/src/BaseDeDatos.php` | `CarteleraTest::testUnaInyeccionEnLaBusquedaNoTraeNada` |
| La base también hace cumplir las reglas del modelo: claves foráneas, únicos (correo, roles sin repetir, una solicitud pendiente por sala) y `CHECK` en rangos y estados | `migraciones/` de cada subsistema | `MigracionesTest` («la base rechaza…») |
| El código que el espectador muestra en la puerta es único: dos reservas hechas a la vez no salen con el mismo | `UNIQUE INDEX reserva_por_codigo` en `funciones/migraciones/001_tabla_reserva.sql` | `MigracionesTest::testDosReservasNoTienenElMismoCodigo` (funciones) |
| Un voto por usuario en cada acuerdo de fecha y uno por franja: cambiar el voto reemplaza el que había en lugar de sumar otro | los `UNIQUE INDEX` de `voto_acuerdo` en `funciones/migraciones/004…` y `005…` | `MigracionesTest::testUnUsuarioMarcaVariasFranjasPeroCadaUnaUnaSolaVez` (funciones) |
| Cada cambio de estado de una reserva queda registrado con su autor y el estado anterior, y la base rechaza un cambio que no cambia nada | `funciones/migraciones/006_tabla_cambio_reserva.sql` | `RepositorioDeReservasTest::testCadaCambioQuedaEnElHistorialConSuAutor` |
| El cupo no lo puede hacer cumplir la base: la función vive en programación y no hay fila que bloquear acá. Lo controla la aplicación (ver «Aplicación») | comentario de `funciones/migraciones/001_tabla_reserva.sql` | — |

## Aplicación

Revisado pantalla por pantalla el 18/10, sobre todo lo de la Entrega 3: registro, ingreso,
cartelera y su CSV, fichas de función y de sala, solicitud de sala, salas y funciones del
organizador, y la bandeja y los usuarios del administrador. Las pruebas nombradas corren en la CI.

Repasado el 05/11 con lo que sumó la Entrega 4: la reserva de una función y su cancelación, «Mis
reservas» y el comprobante, el panel de seguimiento del organizador con su CSV, la anulación del
administrador, el acuerdo de fecha y la votación de títulos, y los pedidos que el navegador hace
con `fetch` sin recargar la página. Lo que agregó esa entrega está en las filas que la nombran.

| Control | Dónde | Cómo se comprueba |
|---|---|---|
| Validación en el backend de cada formulario, con las mismas reglas que el navegador: largos, rangos, formatos, fecha futura, cupo hasta la capacidad de la sala y obras que existen. Un campo que llega como arreglo o con UTF-8 inválido cuenta como vacío | `Formulario*` de cada subsistema (`cuentas/src/Controladores/`, `programacion/src/Controladores/`) y los `Parametros*` de los listados | `SolicitudDeSalaTest::testElServidorRechazaLoMismoQueElNavegador`, `FormularioDeRegistroTest`, `FuncionesDelOrganizadorTest` |
| Consultas parametrizadas: toda consulta pasa por un único `prepare`. Los filtros se arman con fragmentos fijos y `?`, y el texto buscado va como parámetro, con los comodines de `LIKE` escapados | `nucleo/src/BaseDeDatos.php` y los `Repositorio*` | `CarteleraTest::testUnaInyeccionEnLaBusquedaNoTraeNada` |
| Contraseñas con Argon2id, de 8 a 128 caracteres, con letras y números. Al ingresar, el error no dice si falló el correo o la contraseña, y un correo inexistente también calcula un hash, así el tiempo no lo delata | `ServicioDeRegistro`, `ServicioDeIngreso` y `CredencialesInvalidas` en `cuentas/src/Servicios/` | `RegistroTest::testConTodoBienGuardaElUsuarioConArgon`, `IngresoTest::testElErrorNoDiceSiFallaElCorreoOLaContrasena` |
| Control de rol en el servidor: cada ruta declara su rol mínimo, y se controla antes de llegar al controlador. Las rutas sin rol son sólo las públicas, y todas son GET | `rutas.php` de cada subsistema y `nucleo/src/Seguridad/ControlDeAcceso.php` | `PanelDeAdministracionTest` (cuentas y programación) y `PanelDelOrganizadorTest` |
| Quién es el usuario lo dice nginx, que lo pregunta a cuentas. Un subsistema sólo cree esas cabeceras si traen el secreto que comparte con nginx: otro contenedor de la red interna no puede hacerse pasar por un usuario | `nucleo/src/Seguridad/Identificacion.php` y `[nginx] secreto` en cada `config.ini` | `IdentificacionTest::testSinElSecretoIgnoraLasCabecerasYLoRegistra` |
| Control de dueño: un organizador sólo ve y cambia sus salas y sus funciones. Lo ajeno responde 404, como lo que no existe, así no se averigua qué hay | `ServicioDeSalas` y `ServicioDeFuncionesDelOrganizador` en `programacion/src/Servicios/` | `SalasDelOrganizadorTest::testOtroOrganizadorNoVeNiGuardaLaSalaAjena`, `FuncionesDelOrganizadorTest::testFormularioEdicionDevuelve404SiNoExisteOEsAjena` |
| Los id de la URL se validan como UUID antes de ir a la base | `nucleo/src/Uuid.php`, en cada servicio que busca por id | `CarteleraTest::testDetalleConIdInvalidoResponde` |
| Escape de la salida: Twig escapa todo lo que muestra y ninguna plantilla usa `raw`. El JavaScript del front arma lo que muestra sin `innerHTML`, salvo `gestor-franjas.js`, que clona la fila de su `<template>` y sólo le reemplaza números. Lo que se escapara de esto lo frena la Content-Security-Policy (ver «Red»). Los avisos de error que vuelven a la ficha en la URL (`?error_voto=`, `?error_acuerdo=`) van firmados con un HMAC del texto y la función: un link armado a mano no muestra nada | `nucleo/src/Vista.php` (`autoescape`) y las plantillas del front | `SolicitudDeSalaTest::testConErroresVuelveConLoCargadoYEscapado`, `CarteleraTest::testElAvisoDeLaFichaSoloSeMuestraConSuFirma` |
| Sesión propia en la base de cuentas: el identificador son 32 bytes al azar en una cookie `HttpOnly`, `Secure` y `SameSite=Lax`, y al ingresar siempre es uno nuevo. Se cierra con dos horas sin uso y dura como mucho siete días. Cambiar el rol de un usuario o suspenderlo cierra sus sesiones | `cuentas/src/Modelo/Sesion.php` y `ServicioDeSesiones` | `SesionTest`, `SesionInternaTest::testDosHorasSinUsoLaCierran` y la cabecera del sitio publicado («Las cookies en producción», abajo) |
| Token CSRF en todo pedido que cambia algo: va en una cookie y en el formulario, y tienen que coincidir. La cookie es `HttpOnly`, `Secure` y `SameSite=Lax`. Lo que el navegador manda con `fetch` sin recargar (E4) lleva el mismo token en la cabecera `X-CSRF-Token`: sin él no se reserva, y el error vuelve en JSON | `nucleo/src/Seguridad/ProteccionCsrf.php`, para todo el sitio | `AplicacionTest::testUnPostSinTokenCsrfSeRechaza`, `AplicacionTest::testElJavaScriptPuedeMandarElTokenEnUnaCabecera`, `ReservasTest::testEnJsonSinTokenElErrorVaEnJsonYNoSeReserva` y la prueba «sin el token» de cada formulario; la cabecera del sitio publicado («Las cookies en producción», abajo) |
| La foto de una sala y el afiche de una obra se aceptan por su contenido, no por el nombre ni por lo que dice el navegador: JPG, PNG o WebP, hasta 5 MB y 8000 píxeles de lado. Se guardan con un nombre nuevo y se sirven con el tipo de su extensión | `FormularioDeSala`, `ArchivosDeSalas` e `ImagenDeSala`; `FormularioDeObra` y `AfichesDeObras`, en `programacion/src/` | `SolicitudDeSalaTest::testUnaSalaNuevaNecesitaUnaFotoDeVerdad`, `FormularioDeObraTest::testVerificaAfichePorContenidoReal` |
| En el CSV de la cartelera, un texto que una planilla tomaría como fórmula va con un apóstrofo adelante | `programacion/src/Controladores/CsvDeCartelera.php` | `CsvDeCarteleraTest::testUnaFormulaSeEscribeComoTexto` |
| En el CSV de reservas del organizador (E4), el nombre del espectador (cargado por una persona al registrarse) va con un apóstrofo adelante si empieza con `=`, `+`, `-`, `@`, tabulador o retorno de carro, para que una planilla no lo tome como fórmula | `funciones/src/Controladores/CsvDeReservas.php` | `PanelDeReservasTest::testUnaFormulaEnElNombreDelEspectadorSeEscribeComoTextoEnElCsv` |
| Control de dueño de la reserva (E4): el espectador sólo ve y cancela las suyas, y el organizador sólo las de sus funciones. Lo ajeno responde 404, igual que lo que no existe | `funciones/src/Servicios/` y `rutas.php` de funciones | `ReservasTest::testLaReservaDeOtroEsUn404`, `ReservasTest::testLaReservaDeOtroNoSeCancela` |
| No se reservan más lugares que los que quedan, ni con dos pedidos a la vez: las reservas de una misma función se serializan con un bloqueo por función (`GET_LOCK`) y la transacción se confirma antes de soltarlo. Funciones distintas no se esperan entre sí, y si el bloqueo no se suelta a tiempo responde 503 | `RepositorioDeReservas::conLaFuncionBloqueada()` | `ServicioDeReservasTest::testDeVariasReservasALaVezPorElUltimoLugarSeConfirmaUnaSola`, `ServicioDeReservasTest::testNoSeReservanMasLugaresQueLosQueQuedan` |
| Cambiar el estado de una reserva no pisa el cambio de otro: si alguien la movió en el medio, no se guarda | `RepositorioDeReservas` | `RepositorioDeReservasTest::testSiOtroLaCambioAntesNoLaPisa` |
| El código del comprobante se sortea con `random_int` sobre 31 caracteres en 8 posiciones (casi 10¹²) y va aparte del id: no se adivina ni se recorre, y el id de la reserva es un UUID | `Reserva::nuevoCodigo()` en `funciones/src/Modelo/` | `MigracionesTest::testDosReservasNoTienenElMismoCodigo` (funciones) |
| Si el correo del comprobante no se puede mandar, la reserva igual queda hecha y el fallo va al log: el envío no decide si el espectador tiene su lugar | `funciones/src/Servicios/` y `nucleo/src/Correo/ClienteSmtp.php` | `ReservasTest::testSinServidorDeCorreoLaReservaQuedaIgual`, `ReservasTest::testSinLaDireccionDelEspectadorLaReservaQuedaIgual` |
| La API interna (`/interno/`) sólo atiende a los subsistemas que declara cada ruta, cada uno con su token; en la configuración está sólo el SHA-256 del token | `$router->interna()` en cada `rutas.php` y `[llamadores]` en cada `config.ini` | `UsuariosInternosTest::testSoloLasLlamaProgramacionConSuToken`, `FuncionesInternasTest::testSoloLaPideFuncionesConSuToken` |
| En producción, un error responde 500 sin el detalle, que queda en el log. Con algún valor de ejemplo en `config.ini` el subsistema no arranca | `nucleo/src/Aplicacion.php` y `entorno = "produccion"` | `AplicacionTest::testUnErrorEnProduccionNoMuestraElDetalleYQuedaEnElLog` |
| Las dependencias se revisan contra los avisos de seguridad conocidos en cada pull request | `composer audit` en `.github/workflows/ci.yml` | la CI |

**Las cookies en producción**

Copiadas el 08/11/2026 de https://indiecinema.duckdns.org, con el valor reemplazado por `…`. Son
las mismas que en el servidor de desarrollo y en el compose local.

```
GET /cuenta/ingresar                                  (la de CSRF, con el primer pedido)
set-cookie: csrf=…; path=/; secure; HttpOnly; SameSite=Lax

POST /cuenta/ingresar → 303, date: Sun, 08 Nov 2026 00:23:39 GMT
set-cookie: sesion=…; expires=Sun, 15 Nov 2026 00:23:39 GMT; Max-Age=604800; path=/; secure; HttpOnly; SameSite=Lax

POST /cuenta/salir → 303
set-cookie: sesion=…; expires=Thu, 01 Jan 1970 00:00:01 GMT; Max-Age=0; path=/; secure; HttpOnly; SameSite=Lax
```

- Las dos son `HttpOnly`, `Secure` y `SameSite=Lax`, con `path=/` y sin `Domain`: sólo vuelven a
  este mismo host.
- La de sesión vence a los siete días justos del ingreso (`Max-Age=604800`), que es el tope de la
  sesión. Las dos horas sin uso las controla cuentas, no la cookie.
- La de CSRF no tiene `expires`: el navegador la borra al cerrarse.
- Al salir, la de sesión vuelve vencida y el navegador la borra. La sesión del ingreso de arriba
  quedó cerrada.

Para repetirlo, ingresar con `curl` con una cuenta de prueba (`CLAVE`, su contraseña) y mirar las
`set-cookie` de cada respuesta, con el valor tapado:

```
B=https://indiecinema.duckdns.org; F=$(mktemp)
tapar() { grep -i set-cookie | sed -E 's/(: *[a-z]+=)[^;]*/\1…/'; }
curl -s -o /dev/null -c $F $B/cuenta/ingresar -D - | tapar
CSRF=$(awk '$6 == "csrf" { print $7 }' $F)
curl -s -o /dev/null -b $F -c $F -D - $B/cuenta/ingresar --data-urlencode email=eva@indiecinema.test \
  --data-urlencode "password=$CLAVE" --data-urlencode _csrf=$CSRF | tapar
curl -s -o /dev/null -b $F -c $F -D - $B/cuenta/salir --data-urlencode _csrf=$CSRF | tapar
```

**Decisiones de la capa de aplicación**

- **Duración de la sesión.** Dos horas sin uso alcanzan para recorrer la cartelera o cargar
  funciones sin volver a ingresar, y en una computadora compartida no dejan la cuenta abierta
  para el siguiente. Los siete días de tope hacen que una cookie robada no sirva para siempre.
- **El registro avisa si el correo ya tiene cuenta.** Sin verificar el correo no hay otra forma de
  decírselo a quien se equivocó. El ingreso, en cambio, no lo revela. Recorrer correos en masa lo
  frena el límite de 5 pedidos por minuto por IP en nginx (ver «Red»). Se revisa cuando el
  registro mande un correo de verificación (IC-330).
- **Por qué el cupo se controla con un bloqueo con nombre y no con `SELECT … FOR UPDATE`.** El
  cupo vive en la función, que es de programación, y en el esquema de funciones no hay una fila
  que bloquear: sólo están las reservas. Un `GET_LOCK` por función serializa las reservas de esa
  función y deja correr las de todas las demás; si el bloqueo no se consigue en el tiempo de
  espera, la reserva responde 503 en lugar de arriesgarse a contar mal los lugares.
- **El correo del comprobante no decide la reserva.** Si el servidor de correo no responde, la
  reserva igual queda confirmada y el fallo va al log. Al revés, un espectador podría perder el
  lugar por algo que no depende de él, y el comprobante se puede ver siempre en «Mis reservas».

## Amenazas

Lo que puede salir mal, de lo más grave y probable a lo menos. **Impacto** es qué se pierde si
pasa: alto, si se ven o se cambian datos de otra persona, o si alguien entra al servidor; medio,
si se degrada el servicio o se filtra algo que ayuda a un ataque mayor; bajo, si queda en una
molestia. **Probabilidad** es qué tan seguido se intenta contra un sitio así en internet, no qué
tan fácil es acá: por eso una amenaza bien contenida puede seguir siendo probable.

La columna **Qué falta** nombra el ticket cuando el control está planificado y todavía no entró.

| Amenaza | Impacto | Probabilidad | Qué la contiene hoy | Qué falta |
|---|---|---|---|---|
| Entrar a la reserva, la sala o la función de otro cambiando el id de la URL | Alto | Media | Rol mínimo declarado por ruta y controlado antes del controlador; control de dueño que responde 404 y no 403, así no se averigua qué existe; los id son UUID y se validan antes de ir a la base | — |
| Dos reservas a la vez se llevan el mismo último lugar y la función queda sobrevendida | Alto | Media | Las reservas de una misma función se serializan con `GET_LOCK` y la transacción se confirma antes de soltar el bloqueo | — |
| Robo o reutilización de la cookie de sesión | Alto | Baja | Identificador de 32 bytes al azar, nuevo en cada ingreso; cookie `HttpOnly`, `Secure` y `SameSite=Lax`; dos horas de inactividad y siete días de tope; cambiar el rol o suspender al usuario cierra sus sesiones; TLS 1.2+ y HSTS; la CSP no deja correr un script inyectado, que igual no podría leer la cookie | — |
| Inyección SQL | Alto | Baja | Un único `prepare`, sin emulación de PDO; los filtros se arman con fragmentos fijos y `?`, y los comodines de `LIKE` van escapados | — |
| XSS: un texto cargado por un usuario se ejecuta en el navegador de otro | Alto | Baja | Twig escapa todo y ninguna plantilla usa `raw`; el JavaScript del front no arma HTML con texto de nadie (el único `innerHTML`, en `gestor-franjas.js`, sólo pone números); la CSP no deja correr scripts en línea ni de otro origen; `X-Content-Type-Options: nosniff` | — |
| Un subsistema comprometido llega a los datos o los secretos de los otros | Alto | Baja | Un esquema y un usuario de MySQL por subsistema, sin ver los otros; la API interna pide un token por llamador y en la configuración queda sólo su SHA-256; cada contenedor monta sólo su carpeta, corre sin capacidades de root y con el sistema de archivos de sólo lectura | — |
| Alguien entra al VPS | Alto | Baja | SSH sólo con clave y sin root; fail2ban; la clave del despliegue sólo corre `desplegar.sh`; secretos fuera del repositorio y gitleaks en cada pull request; IMDSv2 para los metadatos de AWS | — |
| Prueba masiva de contraseñas, o recorrido de correos para ver cuáles tienen cuenta | Medio | Alta | 5 pedidos por minuto por IP al ingresar y al registrarse; Argon2id; el error de ingreso no dice si falló el correo o la contraseña, y un correo inexistente igual calcula un hash | La verificación de correo (IC-330) sacaría la pista que hoy da el registro |
| CSRF: otro sitio hace que el navegador de un usuario con sesión mande un pedido | Medio | Media | Token en cookie y en el formulario, o en la cabecera `X-CSRF-Token` para lo que va por `fetch`; cookie `SameSite=Lax` | — |
| Abuso de la reserva y de la búsqueda: muchos pedidos seguidos desde una misma IP | Medio | Media | Límite por IP: 10 reservas por minuto (pasan 6 seguidas) y 120 búsquedas por minuto (pasan 61 seguidas). Lo que se pasa responde 429 y no llega a los subsistemas ni a la base. Corta también sin sesión, porque va antes que el `auth_request` | — |
| Un error de producción muestra la traza o un mensaje interno | Medio | Baja | `display_errors` apagado desde antes de arrancar; en producción el 500 sale con un mensaje genérico en HTML y en JSON, y el detalle va al log; con un valor de ejemplo en `config.ini` el subsistema no arranca; si un subsistema no contesta, nginx responde 503 con una página o un JSON propios y no con la suya, que dice su nombre (`error_page` en `sitio.inc.template`) | — |
| Un archivo malicioso subido como foto de sala o afiche de obra | Medio | Baja | Se aceptan por el contenido y no por el nombre ni por lo que dice el navegador: JPG, PNG o WebP, hasta 5 MB y 8000 píxeles; se guardan con un nombre nuevo, fuera del webroot, y se sirven con el tipo de su extensión | — |
| Una fórmula escondida en un CSV se ejecuta al abrirlo en una planilla | Bajo | Baja | En los CSV de la cartelera y de las reservas del organizador, lo que empieza como fórmula va con un apóstrofo adelante | — |

La tabla sale de leer el código y la configuración, y la contrastó el análisis con OWASP ZAP de
la sección siguiente, que atacó el sitio con cada rol.

## Análisis con OWASP ZAP

Hecho entre el 7 y el 8/11/2026 con la imagen `ghcr.io/zaproxy/zaproxy:stable` (ZAP 2.17), en
tres partes:

- **Pasivo (baseline), sin sesión**, contra https://indiecinema-dev.duckdns.org: recorre las páginas
  públicas (104 URL) y revisa cada respuesta, sin atacar. Se repitió contra el compose local con las
  correcciones de abajo, y desde entonces lo corre el despliegue continuo después de cada
  publicación de `dev` (trabajo `zap` de `.github/workflows/cd.yml`): falla con cualquier alerta
  media o alta que no esté revisada en `.zap/reglas.tsv`, y deja el informe HTML como artefacto.
- **Activo, con sesión**, contra el compose local: además de recorrer, ataca cada parámetro
  (inyección SQL, XSS, inclusión de archivos, inyección de comandos, entre otras; unas 140 reglas en
  total), con la sesión de Eva (espectadora, 68 URL), Omar (organizador, 379) y Ana (administradora,
  100). Sólo en local: en `dev` podría cancelar las funciones de las pruebas de punta a punta,
  llenarles el cupo o suspender su cuenta. Después se recrea la base. Se saltea la regla de XSS
  basado en el DOM (40026), que abre un Firefox y tumbaba a ZAP por memoria; ese riesgo lo cubren la
  CSP y que el JavaScript no arma HTML con texto de nadie.
- **Activo, sin sesión**, contra https://indiecinema-dev.duckdns.org, ya con las correcciones
  publicadas: las mismas reglas que el anterior, sobre las 104 URL públicas y con el nginx, el
  HTTPS y el HSTS de un servidor de verdad.

Después de las correcciones, el pasivo contra dev no da ninguna alerta alta, media ni baja: quedan
seis informativas. Antes daba dos medias y cuatro bajas.

**Alertas medias y altas.** Altas no hubo.

| Alerta | Dónde | Tratamiento |
|---|---|---|
| Falta `Content-Security-Policy` (10038) | todas las páginas | **Corregida**: la cabecera en `nginx/templates/sitio.inc.template` (ver «Red»). El front dejó de usar recursos de afuera y código o estilos en línea |
| Falta Subresource Integrity (90003) | el `<link>` a Google Fonts de `base.html.twig` | **Corregida**: Google Fonts no admite SRI, porque devuelve un CSS distinto según el navegador. La fuente se sirve desde el sitio (`estaticos/fuentes/` del front) |
| Anti-CSRF Tokens Check (20012) | en local, el formulario de «Cerrar sesión» del panel del organizador, en las respuestas al registro de asistencia (sólo con Omar); en dev, el buscador de la cartelera, en las respuestas a ingresar y a registrarse | **Falso positivo**: el formulario de salir lleva `_csrf` en todas las respuestas que se reprodujeron (bien, sin token, con un token ajeno, con error 422 y 403), y `POST /cuenta/salir` sin el token o con el de otro usuario responde 403 y la sesión sigue. El buscador es un formulario GET que sólo consulta: no cambia nada, así que no lleva token |
| Bypassing 403 (40038) | `GET /` con `X-Original-URL: /estaticos/css` (con los tres roles y en dev) | **Falso positivo**: nginx no usa esa cabecera; el 200 es de la portada, no del directorio |
| Proxy Disclosure (40025) | dev: con `TRACE`, `TRACK` y `OPTIONS` con `Max-Forwards`, ZAP identifica que hay un nginx | **Aceptada**: es lo mismo que dice la cabecera `Server: nginx` de cada respuesta, sin la versión (`server_tokens off`), y los tres métodos responden 405. Sacar también el nombre necesitaría un módulo que la imagen oficial de nginx no trae, y no esconde nada que no se averigüe de otra forma |
| HTTP Only Site (10106) | el compose local | **No aplica**: en local se usa HTTP a propósito. Los servidores redirigen a HTTPS y mandan HSTS |

Las bajas también se corrigieron: faltaban `Permissions-Policy`, `Cross-Origin-Opener-Policy`,
`Cross-Origin-Resource-Policy` y `Cross-Origin-Embedder-Policy`, que ahora están en la misma
plantilla (ver «Red»).

**Falsos positivos que quedan en el informe**

- *User Controllable HTML Element Attribute (Potential XSS)*, informativa (10031, entre 12 y 26 URL
  según el recorrido): los filtros de la cartelera vuelven en el `value` del formulario, y Twig los
  escapa. Está en `IGNORE` en `.zap/reglas.tsv`.
- *Inyección SQL*: en un primer escaneo activo como organizador, el 7/11, la regla 40018 levantó una
  alerta, pero ZAP se quedó sin memoria antes de escribir el informe y no se supo en qué URL. En los
  escaneos completos las seis reglas de inyección SQL, la booleana y las de tiempo, pasaron sin
  alertas con los tres roles, también con el organizador (379 URL), y sin sesión contra dev. Es lo
  esperable: toda consulta pasa por un `prepare` sin emulación (ver «Aplicación»).

**Cómo repetirlo.** El pasivo lo corre el CD. El activo, con el compose levantado y los datos de
prueba cargados, por cada cuenta: ingresar con `POST /cuenta/ingresar` (`email`, `password` y
`_csrf`, igual a la cookie `csrf`), y pasarle a ZAP las cookies `csrf` y `sesion`. Son `Secure`,
así que por HTTP hay que mandarlas a mano, con el reemplazo de cabeceras. Además, excluir
`/cuenta/salir`, que le cerraría la sesión, y limitar la memoria de Java:

```
echo -Xmx2g > zap-jvm.properties
printf '40026\tIGNORE\n' > activo.tsv
SESION='csrf=…;sesion=…'
R='replacer.full_list(0)'
OPCIONES="-config $R.description=sesion -config $R.enabled=true -config $R.matchtype=REQ_HEADER"
OPCIONES+=" -config $R.matchstr=Cookie -config $R.regex=false -config $R.replacement=$SESION"
OPCIONES+=" -config globalexcludeurl.url_list.url(0).regex=.*/cuenta/salir.*"
OPCIONES+=" -config globalexcludeurl.url_list.url(0).enabled=true"
docker run --rm --network indiecinema_interna -m 4g -v "$PWD:/zap/wrk:rw" \
  -v "$PWD/zap-jvm.properties:/home/zap/.ZAP/.ZAP_JVM.properties:ro" ghcr.io/zaproxy/zaproxy:stable \
  zap-full-scan.py -t http://nginx/ -m 5 -I -c activo.tsv -r informe.html -z "$OPCIONES"
```

`activo.tsv` saltea la regla 40026. Con 4 GB por contenedor entran dos a la vez, pero el del
administrador va solo, porque puede suspender a las otras cuentas o cambiarles el rol.

## Por etapa

| Etapa | Qué se hace | Dónde |
|---|---|---|
| Diseño | Cada capa con sus controles antes de programar: subsistemas con esquema y usuario propios, sesión validada por nginx con cuentas y API interna con un token por llamador | capítulo 6 de `entrega-2/Entrega_2.pdf`, en el repositorio de entregas |
| Desarrollo | Cada cambio entra por pull request y lo revisa otro integrante. Desde la Entrega 4 los pull request van contra `dev`, que se despliega en su propio servidor, y `main` queda para lo entregado. El núcleo concentra lo que se repite (consultas, escape, CSRF, rol por ruta), así ninguna pantalla lo resuelve por su cuenta | `nucleo/`, `.github/pull_request_template.md` y el README («Ramas y entornos») |
| Prueba | Pruebas de cada control en la CI, en cada pull request: rol y dueño, CSRF, inyección, validación. También gitleaks, `composer audit` y la configuración de nginx desde afuera. Desde la Entrega 4, después de publicar en el servidor de desarrollo corren además las pruebas de punta a punta contra ese sitio, con sesión iniciada y fallando ante cualquier violación de la CSP, y el análisis pasivo de OWASP ZAP. El escaneo activo con sesión se corre a mano contra el compose local (ver «Análisis con OWASP ZAP») | `.github/workflows/ci.yml`, `nginx/comprobar-desde-afuera.sh` y los trabajos `e2e` y `zap` de `.github/workflows/cd.yml` |
| Despliegue | Secretos generados en el servidor y fuera del repositorio; con `entorno = "produccion"` no arranca con valores de ejemplo. El despliegue continuo entra con una clave que sólo puede correr el script | `herramientas/desplegar.sh` y `.github/workflows/cd.yml` (README, «Despliegue continuo») |
| Operación | Actualizaciones de seguridad automáticas, fail2ban, renovación del certificado y logs de cada subsistema | «Sistema operativo» y «Red», arriba; `docker compose logs` |
