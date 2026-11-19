# Permisos por rol y por ruta

La matriz que prometió la propuesta en el diseño (sección 3, «Seguridad en cada etapa del ciclo de
vida»): qué rol mínimo pide cada ruta de cada subsistema. Los roles van de menos a más:
visitante (sin sesión), espectador, organizador, moderador y administrador, y cada uno alcanza lo de
los anteriores. El rol lo controla el núcleo antes de llegar al controlador
(`nucleo/src/Seguridad/ControlDeAcceso.php`): sin sesión, 401; con un rol que no alcanza, 403. Que
un usuario no llegue a lo de otro (la sala, la reserva o la función ajena responden 404) lo controla
cada servicio y lo prueba cada pantalla (`docs/seguridad.md`, «Aplicación»).

**Esta tabla es la que prueba `AccesoPorRolTest` en cada subsistema** (EF-18.2): compara las rutas
de su `rutas.php` con su sección de acá, y pide cada una sin sesión y con cada rol. Una ruta nueva,
o un rol cambiado, hace fallar la prueba hasta que se anota acá: cambiar un permiso es una decisión
a la vista, no un detalle de `rutas.php`.

No están las rutas de la API interna (`/interno/`), que no se alcanzan desde afuera y declaran qué
subsistemas las llaman (`$router->interna()`, probadas en las `*InternosTest`), ni el aviso de pago
de Mercado Pago (`POST /funcion/pagos/aviso`), que no lleva sesión y se acepta sólo con la firma.
La ruta va sin el prefijo del subsistema, como en `rutas.php`; nginx se lo agrega.

## cuentas

Prefijo `/cuenta`. La cuenta de cada uno, el ingreso y el registro, y los usuarios y roles del administrador.

| Método | Ruta | Rol mínimo |
|---|---|---|
| GET | `/` | espectador |
| GET | `/administracion/usuarios` | administrador |
| POST | `/administracion/usuarios/roles` | administrador |
| POST | `/administracion/usuarios/{id}/estado` | administrador |
| GET | `/contrasena` | espectador |
| POST | `/contrasena` | espectador |
| GET | `/datos` | espectador |
| POST | `/datos` | espectador |
| GET | `/ingresar` | visitante |
| POST | `/ingresar` | visitante |
| GET | `/recuperar` | visitante |
| POST | `/recuperar` | visitante |
| GET | `/recuperar/nueva` | visitante |
| POST | `/recuperar/nueva` | visitante |
| GET | `/registro` | visitante |
| POST | `/registro` | visitante |
| POST | `/salir` | visitante |
| GET | `/seguimientos` | espectador |
| POST | `/seguimientos` | espectador |
| GET | `/verificar` | visitante |
| POST | `/verificar/reenviar` | espectador |

## programacion

Prefijo `/`. La cartelera, las salas y las obras públicas, la votación y los pedidos del espectador, y el panel del organizador.

| Método | Ruta | Rol mínimo |
|---|---|---|
| GET | `/` | visitante |
| GET | `/acerca-de` | visitante |
| GET | `/cartelera` | visitante |
| GET | `/cartelera.csv` | visitante |
| GET | `/cartelera/{id}` | visitante |
| POST | `/cartelera/{id}/acuerdo/votar` | espectador |
| GET | `/cartelera/{id}/lugares` | visitante |
| POST | `/cartelera/{id}/votar` | espectador |
| POST | `/cartelera/{id}/votar-franja` | espectador |
| GET | `/como-funciona` | visitante |
| GET | `/obras` | visitante |
| GET | `/obras/{id}` | visitante |
| GET | `/obras/{id}/afiche` | visitante |
| GET | `/organizador/bloqueados` | organizador |
| GET | `/organizador/funciones` | organizador |
| GET | `/organizador/funciones/nueva` | organizador |
| POST | `/organizador/funciones/nueva` | organizador |
| POST | `/organizador/funciones/{id}/acuerdo` | organizador |
| GET | `/organizador/funciones/{id}/cancelar` | organizador |
| POST | `/organizador/funciones/{id}/cancelar` | organizador |
| POST | `/organizador/funciones/{id}/cerrar-sin-minimo` | organizador |
| POST | `/organizador/funciones/{id}/cerrar-votacion` | organizador |
| POST | `/organizador/funciones/{id}/confirmar-acuerdo` | organizador |
| GET | `/organizador/funciones/{id}/editar` | organizador |
| POST | `/organizador/funciones/{id}/editar` | organizador |
| GET | `/organizador/funciones/{id}/franjas` | organizador |
| POST | `/organizador/funciones/{id}/franjas` | organizador |
| POST | `/organizador/funciones/{id}/proponer-acuerdo` | organizador |
| GET | `/organizador/funciones/{id}/proponer-franjas` | organizador |
| POST | `/organizador/funciones/{id}/proponer-franjas` | organizador |
| GET | `/organizador/obras` | organizador |
| GET | `/organizador/obras/nueva` | organizador |
| POST | `/organizador/obras/nueva` | organizador |
| POST | `/organizador/obras/{id}/baja` | organizador |
| GET | `/organizador/obras/{id}/editar` | organizador |
| POST | `/organizador/obras/{id}/editar` | organizador |
| GET | `/organizador/pedidos` | organizador |
| POST | `/organizador/pedidos/{id}/tomar` | organizador |
| GET | `/organizador/salas` | organizador |
| POST | `/organizador/salas/{id}/bloqueos` | organizador |
| POST | `/organizador/salas/{id}/bloqueos/{usuario}/quitar` | organizador |
| GET | `/organizador/salas/{id}/editar` | organizador |
| POST | `/organizador/salas/{id}/editar` | organizador |
| GET | `/pedidos` | visitante |
| GET | `/pedidos/nuevo` | espectador |
| POST | `/pedidos/nuevo` | espectador |
| POST | `/pedidos/{id}/quitar` | espectador |
| POST | `/pedidos/{id}/sumarme` | espectador |
| GET | `/salas` | visitante |
| GET | `/salas/{id}` | visitante |
| GET | `/salas/{id}/imagen` | visitante |

## funciones

Prefijo `/funcion`. La reserva del espectador, el seguimiento de las reservas del organizador y las reservas del administrador.

| Método | Ruta | Rol mínimo |
|---|---|---|
| GET | `/administracion/reservas` | administrador |
| GET | `/administracion/reservas/{id}` | administrador |
| POST | `/administracion/reservas/{id}/anular` | administrador |
| POST | `/organizador/funciones/{id}/asistencia` | organizador |
| POST | `/organizador/funciones/{id}/conducta` | organizador |
| GET | `/organizador/funciones/{id}/reservas` | organizador |
| GET | `/organizador/funciones/{id}/reservas.csv` | organizador |
| GET | `/reservar/{id}` | espectador |
| POST | `/reservar/{id}` | espectador |
| GET | `/reservas` | espectador |
| GET | `/reservas/{id}` | espectador |
| GET | `/reservas/{id}/calificar` | espectador |
| POST | `/reservas/{id}/calificar` | espectador |
| GET | `/reservas/{id}/cancelar` | espectador |
| POST | `/reservas/{id}/cancelar` | espectador |
| GET | `/reservas/{id}/regreso` | espectador |

## moderacion

Prefijo `/moderacion`. La solicitud de sala, las denuncias y la documentación, y las bandejas del moderador.

| Método | Ruta | Rol mínimo |
|---|---|---|
| GET | `/denuncias` | moderador |
| GET | `/denuncias/nueva` | espectador |
| POST | `/denuncias/nueva` | espectador |
| POST | `/denuncias/salas/{id}/levantar` | moderador |
| POST | `/denuncias/{tipo}/{id}/resolver` | moderador |
| GET | `/documentos` | moderador |
| GET | `/documentos/{id}` | espectador |
| POST | `/documentos/{id}/resolver` | moderador |
| GET | `/obras` | moderador |
| POST | `/obras/{id}/resolver` | moderador |
| GET | `/salas/{id}/documentacion` | organizador |
| POST | `/salas/{id}/documentacion` | organizador |
| GET | `/solicitar-sala` | espectador |
| POST | `/solicitar-sala` | espectador |
| GET | `/solicitudes` | moderador |
| POST | `/solicitudes/{id}/deshabilitar-sala` | moderador |
| GET | `/solicitudes/{id}/foto` | espectador |
| POST | `/solicitudes/{id}/habilitar-sala` | moderador |
| POST | `/solicitudes/{id}/resolver` | moderador |

## recaudacion

Prefijo `/recaudacion`. Los beneficios y los puntos, los reportes y las liquidaciones del organizador, y el reparto, los comercios y las liquidaciones del administrador.

| Método | Ruta | Rol mínimo |
|---|---|---|
| GET | `/administracion/comercios` | administrador |
| POST | `/administracion/comercios` | administrador |
| GET | `/administracion/comercios/{id}` | administrador |
| POST | `/administracion/comercios/{id}` | administrador |
| POST | `/administracion/comercios/{id}/activo` | administrador |
| GET | `/administracion/liquidaciones` | administrador |
| POST | `/administracion/liquidaciones/{id}/transferida` | administrador |
| GET | `/administracion/reparto` | administrador |
| POST | `/administracion/reparto` | administrador |
| GET | `/beneficios` | visitante |
| GET | `/liquidaciones` | organizador |
| GET | `/puntos` | espectador |
| POST | `/puntos/canjear` | espectador |
| GET | `/reportes` | organizador |
| POST | `/reportes/costos` | organizador |
| GET | `/reportes/funciones` | organizador |
| GET | `/reportes/funciones.csv` | organizador |
| GET | `/reportes/obras` | organizador |
