# IndieCinema · back-end

Back-end de **IndieCinema**, la plataforma para organizar proyecciones de cine amateur e
independiente en salas indie. Trabajo Práctico Integrador de Programación en Ambiente Web
(UNLu), grupo Cerberus.

Es un monorepo: acá están el `docker-compose.yml`, la configuración de nginx, el script inicial
de MySQL y una carpeta por subsistema. Lo visual (plantillas, CSS y JavaScript) está en el otro
repositorio, [frontend-indiecinema](https://github.com/cerberus-paw/frontend-indiecinema), que
cada subsistema instala como paquete de Composer.

## Estructura

```
backend-indiecinema/
├── docker-compose.yml     nginx, un contenedor PHP por subsistema y MySQL
├── .env.ejemplo           puerto y claves de MySQL (copiar a .env)
├── nginx/conf.d/          ruteo por prefijo, validación de sesión y límites de intentos
├── php/                   Dockerfile común a los subsistemas (php:8-apache)
├── mysql/
│   ├── init/              esquemas, usuarios y permisos; corre una vez, con la base vacía
│   └── herramientas/      aplicar migraciones y cargar datos de prueba
├── nucleo/                MVC común: ruteo, middleware, base de datos, vistas y errores
├── cuentas/               /cuenta/   registro, sesión, roles, perfil y seguidos
├── programacion/          /          catálogo, salas, funciones, cartelera, votación y rankings
└── funciones/             /funcion/  acuerdo de fecha, reservas, cobro, asistencia y calificaciones
```

Moderación y beneficios se suman en la Entrega 4 con la misma forma.

Cada subsistema tiene la misma estructura por dentro:

```
<subsistema>/
├── composer.json          depende de nucleo y del paquete front
├── public/index.php       único punto de entrada; todo lo demás queda fuera del webroot
├── rutas.php              método + ruta → controlador, y el rol mínimo de cada ruta
├── src/
│   ├── Controladores/     validan la entrada y deciden la respuesta; sin SQL ni reglas de negocio
│   ├── Servicios/         reglas del dominio («no se reserva sin cupo»)
│   ├── Modelo/            entidades y enumeraciones de estado
│   ├── Repositorios/      todo el SQL del subsistema, con consultas preparadas
│   └── Clientes/          una clase por subsistema consumido por /interno/ (y Mercado Pago)
├── migraciones/           001_*.sql, 002_*.sql… sólo sobre el esquema propio
├── datos-prueba/          usuarios y datos para la demo y las pruebas
├── bin/                   tareas programadas (cerrar votaciones, liberar reservas vencidas…)
├── tests/
└── config/
    ├── config.ejemplo.ini se versiona
    └── config.ini         no se versiona: base, prefijo, tokens y secretos
```

Las plantillas no están acá: viven en el paquete front, en una carpeta por subsistema, para que
todo el sitio se vea igual aunque lo sirvan subsistemas distintos.

## Decisiones

- **Núcleo propio con Twig, sin framework.** La arquitectura dejaba abierto «un núcleo propio y
  Twig o Laravel». Los docentes pidieron trabajar con lo mínimo de librerías («vanilla»), así que
  el ruteo, los controladores, el middleware, el acceso a la base y el manejo de errores los
  escribimos nosotros. Sólo usamos una librería donde reinventarla sería un riesgo o no suma nada:

  | Librería | Para qué | Por qué no la escribimos |
  |---|---|---|
  | `twig/twig` | Plantillas | Escapa el HTML por defecto, que es el control contra XSS que prometimos, y es el motor de las plantillas del paquete front |
  | `psr/log` | Interfaz del registro de errores (PSR-3) | Son sólo interfaces; el registro que escribe el log es nuestro |
  | `phpunit/phpunit` | Pruebas del núcleo | Sólo en desarrollo, no llega al servidor |

- **El núcleo es un paquete de Composer dentro del monorepo** (`nucleo/`, instalado con un
  repositorio `path`). Cuentas, programación y funciones usan el mismo código, así un error se
  arregla una sola vez.
- **El front es la carpeta hermana.** Los dos repositorios se clonan uno al lado del otro y cada
  subsistema instala `../../frontend-indiecinema` como paquete. Para cada entrega, los dos se
  etiquetan (`entrega-N`) y se despliegan en esa etiqueta.
- **Cada contenedor monta sólo lo suyo**: su carpeta, el núcleo y el front. Ningún subsistema ve
  el `config.ini` de otro, así que si uno queda comprometido no se lleva los secretos de los
  demás; la credencial de Mercado Pago existe sólo en funciones.
- **Los archivos subidos van a un volumen del subsistema dueño**, fuera del webroot, y los entrega
  un controlador. La E2 sólo nombraba la documentación de las salas, que es de moderación; la E3
  suma la imagen de la sala y el afiche de la obra, así que programación ya tiene el suyo. Cuentas
  y moderación suman el propio cuando guarden archivos (la foto de perfil, la documentación).
- **Sólo nginx publica un puerto.** MySQL y los subsistemas están en la red interna: desde
  afuera no se llega a la base ni a las rutas `/interno/`.
- **La sesión llega en cabeceras de nginx.** Después de validar la cookie con cuentas, nginx
  manda `X-Usuario-Id`, `X-Rol` y `X-Usuario-Nombre`, más `X-Nginx-Secreto` con el secreto de
  ese subsistema; sin el secreto, el núcleo ignora las otras. El nombre no estaba en la E2, que
  decía pedírselo a cuentas: el encabezado lo muestra en todas las páginas y sería una llamada
  más por cada una. Va codificado con `rawurlencode()`.
- **CSRF con doble envío:** el token está en la cookie `csrf` y en cada formulario (campo
  `_csrf`, o la cabecera `X-CSRF-Token` desde JavaScript), y un POST pasa sólo si coinciden. Los
  subsistemas no guardan sesión, así que no hay dónde tener el token del lado del servidor.
- **Las migraciones se aplican desde el contenedor de MySQL.** El usuario con permiso para crear
  y alterar tablas nunca está en un contenedor de la aplicación; cada subsistema se conecta con un
  usuario que sólo lee y escribe datos de su propio esquema.
- **Los secretos no se versionan.** Cada subsistema tiene su `config.ini` y compose lee `.env`;
  en el repositorio están sólo los ejemplos.
- **Las tareas programadas las dispara el cron del servidor** con
  `docker compose exec -T <subsistema> php bin/<tarea>.php`, en lugar de sumar un contenedor
  sólo para eso.
- **Las rutas dentro del contenedor copian las del disco** (`/src/backend-indiecinema/<subsistema>`
  y `/src/frontend-indiecinema`), así los repositorios `path` de Composer funcionan igual si se
  instala desde afuera o desde adentro del contenedor.

## Levantar el entorno

Requisitos: Docker con Docker Compose, y PHP 8.4 o superior con Composer.

```
git clone https://github.com/cerberus-paw/backend-indiecinema.git
git clone https://github.com/cerberus-paw/frontend-indiecinema.git
cd backend-indiecinema
cp .env.ejemplo .env                     # y cambiar las claves
cp cuentas/config/config.ejemplo.ini cuentas/config/config.ini
cp programacion/config/config.ejemplo.ini programacion/config/config.ini
cp funciones/config/config.ejemplo.ini funciones/config/config.ini
composer install -d cuentas && composer install -d programacion && composer install -d funciones
docker compose up -d
```

El sitio queda en http://localhost:8080.
