# Seguridad por capa

Qué protege cada capa de IndieCinema (red, sistema operativo, base de datos y aplicación), dónde
está configurado y cómo se comprueba; al final, qué se hace en cada etapa del ciclo de vida. Red y
sistema operativo describen el VPS de https://indiecinema.duckdns.org tal como quedó el
18/10/2026: una instancia EC2 de AWS (t3.small) con IP elástica, donde corre el
`docker-compose.yml` de este repositorio.

## Red

| Control | Dónde | Cómo se comprueba |
|---|---|---|
| Cortafuegos fuera de la máquina: sólo entran el 22 (SSH), el 80 y el 443, por IPv4. El SSH queda abierto a cualquier origen porque el despliegue continuo entra desde las máquinas de GitHub Actions, que no tienen IP fija; lo cuidan la clave, fail2ban y que la clave del despliegue sólo puede correr el script (capa de sistema operativo) | reglas de entrada del security group de la instancia en AWS (ufw no se usa) | consola de EC2 → la instancia → Security |
| Sólo HTTPS. Por el 80 se redirige a HTTPS (301) y se atiende el desafío de Let's Encrypt | `nginx/templates/servidor-https.inc.template`, que se activa desde `.env` con `docker-compose.https.yml` | `curl -I http://indiecinema.duckdns.org/` |
| TLS 1.2 y 1.3, sin versiones anteriores, y cifrados con secreto hacia adelante (ECDHE) | `ssl_protocols` y `ssl_ciphers` en la misma plantilla | `openssl s_client -tls1_1 …` responde `protocol version` |
| Certificado de Let's Encrypt que se renueva solo: certbot por webroot y un hook que recarga nginx | `certbot.timer` en el servidor (README, «HTTPS en el VPS») | `sudo certbot certificates` |
| HSTS por un año: el navegador ya no intenta por HTTP | `Strict-Transport-Security` en `servidor-https.inc.template` | `curl -I https://indiecinema.duckdns.org/` |
| Cabeceras contra el sniffing de tipos, el clickjacking y la fuga del Referer, y sin la versión de nginx | `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy` y `server_tokens off` en `nginx/templates/sitio.inc.template` | la misma respuesta |
| Un solo punto de entrada: sólo nginx publica puertos. MySQL y los subsistemas quedan en la red interna del compose | `ports` de `docker-compose.yml` | en el servidor escuchan sólo el 22, el 80 y el 443 (`ss -tln`) |
| El buzón de prueba (Mailpit), que simula el envío de correos, sólo escucha en la máquina | `127.0.0.1:8025` en `docker-compose.yml` | se ve con un túnel: `ssh -L 8025:127.0.0.1:8025 …` |
| Las rutas `/interno/` (las APIs entre subsistemas) y el `auth_request` de la sesión no se alcanzan desde afuera | `nginx/templates/sitio.inc.template` | `nginx/comprobar-desde-afuera.sh https://indiecinema.duckdns.org`, que también corre la CI |
| Límite de pedidos por IP al ingresar, registrarse y pedir una sala | `limit_req_zone` en `nginx/templates/indiecinema.conf.template` | superar el límite responde 429 |
| Los metadatos de la instancia (de donde saldrían credenciales de AWS) sólo con IMDSv2, que pide un token | configuración de la instancia en AWS | sin token, `curl http://169.254.169.254/latest/meta-data/` responde 401 |

## Sistema operativo

| Control | Dónde | Cómo se comprueba |
|---|---|---|
| Ubuntu 26.04 LTS con las actualizaciones de seguridad automáticas | `unattended-upgrades` activo y `/etc/apt/apt.conf.d/20auto-upgrades` | `systemctl status unattended-upgrades` |
| SSH sólo con clave: sin contraseñas y sin entrar como root | `PasswordAuthentication no`, `KbdInteractiveAuthentication no` y `PermitRootLogin no` | `sudo sshd -T` |
| Bloqueo de quien prueba claves por SSH: 5 intentos en 10 minutos dejan la IP 10 minutos afuera | jaula `sshd` de fail2ban | `sudo fail2ban-client status sshd` |
| La clave del despliegue continuo (el secreto de GitHub) sólo corre `herramientas/desplegar.sh`: no elige el comando ni abre una terminal o un túnel | `restrict,command=…` en su línea de `~/.ssh/authorized_keys` (README, «Despliegue continuo») | con esa clave, `ssh … id` despliega en vez de correr `id` |
| Un solo usuario con acceso, `ubuntu`, que despliega con Docker sin ser root | grupo `docker` | `id ubuntu` |
| Los secretos no están en el repositorio, sólo los lee el usuario del despliegue, y gitleaks revisa cada pull request y cada push a `main` | `.env` (600) y cada `config.ini` (640), que ignora `.gitignore`; `.gitleaks.toml` y la CI | `stat -c '%a %n' .env */config/config.ini` |
| Cada subsistema corre como `www-data`, sin ninguna capacidad de root, sin poder ganar privilegios y con el sistema de archivos de sólo lectura | `php/Dockerfile` y `x-subsistema` en `docker-compose.yml` (`cap_drop: [ALL]`, `no-new-privileges`, `read_only`) | `docker compose exec programacion id` |
| Un subsistema comprometido no lee el código ni los secretos de los otros: cada uno monta sólo su carpeta | `volumes` de cada subsistema en `docker-compose.yml` | — |
| Docker aísla los contenedores con AppArmor y seccomp | perfiles por defecto de Docker | `docker info` |

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

## Aplicación

Revisado pantalla por pantalla el 18/10, sobre todo lo de la Entrega 3: registro, ingreso,
cartelera y su CSV, fichas de función y de sala, solicitud de sala, salas y funciones del
organizador, y la bandeja y los usuarios del administrador. Las pruebas nombradas corren en la CI.

| Control | Dónde | Cómo se comprueba |
|---|---|---|
| Validación en el backend de cada formulario, con las mismas reglas que el navegador: largos, rangos, formatos, fecha futura, cupo hasta la capacidad de la sala y obras que existen. Un campo que llega como arreglo o con UTF-8 inválido cuenta como vacío | `Formulario*` de cada subsistema (`cuentas/src/Controladores/`, `programacion/src/Controladores/`) y los `Parametros*` de los listados | `SolicitudDeSalaTest::testElServidorRechazaLoMismoQueElNavegador`, `FormularioDeRegistroTest`, `FuncionesDelOrganizadorTest` |
| Consultas parametrizadas: toda consulta pasa por un único `prepare`. Los filtros se arman con fragmentos fijos y `?`, y el texto buscado va como parámetro, con los comodines de `LIKE` escapados | `nucleo/src/BaseDeDatos.php` y los `Repositorio*` | `CarteleraTest::testUnaInyeccionEnLaBusquedaNoTraeNada` |
| Contraseñas con Argon2id, de 8 a 128 caracteres, con letras y números. Al ingresar, el error no dice si falló el correo o la contraseña, y un correo inexistente también calcula un hash, así el tiempo no lo delata | `ServicioDeRegistro`, `ServicioDeIngreso` y `CredencialesInvalidas` en `cuentas/src/Servicios/` | `RegistroTest::testConTodoBienGuardaElUsuarioConArgon`, `IngresoTest::testElErrorNoDiceSiFallaElCorreoOLaContrasena` |
| Control de rol en el servidor: cada ruta declara su rol mínimo, y se controla antes de llegar al controlador. Las rutas sin rol son sólo las públicas, y todas son GET | `rutas.php` de cada subsistema y `nucleo/src/Seguridad/ControlDeAcceso.php` | `PanelDeAdministracionTest` (cuentas y programación) y `PanelDelOrganizadorTest` |
| Quién es el usuario lo dice nginx, que lo pregunta a cuentas. Un subsistema sólo cree esas cabeceras si traen el secreto que comparte con nginx: otro contenedor de la red interna no puede hacerse pasar por un usuario | `nucleo/src/Seguridad/Identificacion.php` y `[nginx] secreto` en cada `config.ini` | `IdentificacionTest::testSinElSecretoIgnoraLasCabecerasYLoRegistra` |
| Control de dueño: un organizador sólo ve y cambia sus salas y sus funciones. Lo ajeno responde 404, como lo que no existe, así no se averigua qué hay | `ServicioDeSalas` y `ServicioDeFuncionesDelOrganizador` en `programacion/src/Servicios/` | `SalasDelOrganizadorTest::testOtroOrganizadorNoVeNiGuardaLaSalaAjena`, `FuncionesDelOrganizadorTest::testFormularioEdicionDevuelve404SiNoExisteOEsAjena` |
| Los id de la URL se validan como UUID antes de ir a la base | `nucleo/src/Uuid.php`, en cada servicio que busca por id | `CarteleraTest::testDetalleConIdInvalidoResponde` |
| Escape de la salida: Twig escapa todo lo que muestra y ninguna plantilla usa `raw`. El JavaScript del front arma lo que muestra sin `innerHTML` | `nucleo/src/Vista.php` (`autoescape`) y las plantillas del front | `SolicitudDeSalaTest::testConErroresVuelveConLoCargadoYEscapado` |
| Sesión propia en la base de cuentas: el identificador son 32 bytes al azar en una cookie `HttpOnly`, `Secure` y `SameSite=Lax`, y al ingresar siempre es uno nuevo. Se cierra con dos horas sin uso y dura como mucho siete días. Cambiar el rol de un usuario o suspenderlo cierra sus sesiones | `cuentas/src/Modelo/Sesion.php` y `ServicioDeSesiones` | `SesionTest`, `SesionInternaTest::testDosHorasSinUsoLaCierran` |
| Token CSRF en todo pedido que cambia algo: va en una cookie y en el formulario, y tienen que coincidir. La cookie es `HttpOnly`, `Secure` y `SameSite=Lax` | `nucleo/src/Seguridad/ProteccionCsrf.php`, para todo el sitio | `AplicacionTest::testUnPostSinTokenCsrfSeRechaza` y la prueba «sin el token» de cada formulario |
| La foto de una sala se acepta por su contenido, no por el nombre ni por lo que dice el navegador: JPG, PNG o WebP, hasta 5 MB y 8000 píxeles de lado. Se guarda con un nombre nuevo y se sirve con el tipo de su extensión | `FormularioDeSala`, `ArchivosDeSalas` e `ImagenDeSala` en `programacion/src/` | `SolicitudDeSalaTest::testUnaSalaNuevaNecesitaUnaFotoDeVerdad` |
| En el CSV de la cartelera, un texto que una planilla tomaría como fórmula va con un apóstrofo adelante | `programacion/src/Controladores/CsvDeCartelera.php` | `CsvDeCarteleraTest::testUnaFormulaSeEscribeComoTexto` |
| La API interna (`/interno/`) sólo atiende a los subsistemas que declara cada ruta, cada uno con su token; en la configuración está sólo el SHA-256 del token | `$router->interna()` en cada `rutas.php` y `[llamadores]` en cada `config.ini` | `UsuariosInternosTest::testSoloLasLlamaProgramacionConSuToken`, `FuncionesInternasTest::testSoloLaPideFuncionesConSuToken` |
| En producción, un error responde 500 sin el detalle, que queda en el log. Con algún valor de ejemplo en `config.ini` el subsistema no arranca | `nucleo/src/Aplicacion.php` y `entorno = "produccion"` | `AplicacionTest::testUnErrorEnProduccionNoMuestraElDetalleYQuedaEnElLog` |
| Las dependencias se revisan contra los avisos de seguridad conocidos en cada pull request | `composer audit` en `.github/workflows/ci.yml` | la CI |

**Decisiones de la capa de aplicación**

- **Duración de la sesión.** Dos horas sin uso alcanzan para recorrer la cartelera o cargar
  funciones sin volver a ingresar, y en una computadora compartida no dejan la cuenta abierta
  para el siguiente. Los siete días de tope hacen que una cookie robada no sirva para siempre.
- **El registro avisa si el correo ya tiene cuenta.** Sin verificar el correo no hay otra forma de
  decírselo a quien se equivocó. El ingreso, en cambio, no lo revela. Recorrer correos en masa lo
  frena el límite de 5 pedidos por minuto por IP en nginx (ver «Red»). Se revisa cuando el
  registro mande un correo de verificación.

## Por etapa

| Etapa | Qué se hace | Dónde |
|---|---|---|
| Diseño | Cada capa con sus controles antes de programar: subsistemas con esquema y usuario propios, sesión validada por nginx con cuentas y API interna con un token por llamador | capítulo 6 de `entrega-2/Entrega_2.pdf`, en el repositorio de entregas |
| Desarrollo | Cada cambio entra por pull request y lo revisa otro integrante. El núcleo concentra lo que se repite (consultas, escape, CSRF, rol por ruta), así ninguna pantalla lo resuelve por su cuenta | `nucleo/` y `.github/pull_request_template.md` |
| Prueba | Pruebas de cada control en la CI, en cada pull request: rol y dueño, CSRF, inyección, validación. También gitleaks, `composer audit` y la configuración de nginx desde afuera | `.github/workflows/ci.yml` y `nginx/comprobar-desde-afuera.sh` |
| Despliegue | Secretos generados en el servidor y fuera del repositorio; con `entorno = "produccion"` no arranca con valores de ejemplo. El despliegue continuo entra con una clave que sólo puede correr el script | `herramientas/desplegar.sh` y `.github/workflows/cd.yml` (README, «Despliegue continuo») |
| Operación | Actualizaciones de seguridad automáticas, fail2ban, renovación del certificado y logs de cada subsistema | «Sistema operativo» y «Red», arriba; `docker compose logs` |
