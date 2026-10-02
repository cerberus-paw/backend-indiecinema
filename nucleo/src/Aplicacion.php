<?php

declare(strict_types=1);

namespace IndieCinema\Nucleo;

use Composer\InstalledVersions;
use IndieCinema\Nucleo\Errores\ManejadorDeErrores;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Ruteo\Router;
use IndieCinema\Nucleo\Seguridad\ControlDeAcceso;
use IndieCinema\Nucleo\Seguridad\Identificacion;
use IndieCinema\Nucleo\Seguridad\ProteccionCsrf;
use LogicException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Un subsistema en marcha: se arma a partir de su carpeta (config/config.ini y rutas.php) y
 * atiende cada petición buscando la ruta y despachando al controlador.
 */
final class Aplicacion
{
    private const PAQUETE_FRONT = 'cerberus-paw/frontend-indiecinema';

    private readonly Router $router;
    private readonly Contenedor $contenedor;
    private readonly Vista $vista;
    private readonly ManejadorDeErrores $errores;
    private readonly Identificacion $identificacion;
    private readonly ControlDeAcceso $controlDeAcceso;
    private readonly ProteccionCsrf $csrf;
    private readonly LoggerInterface $log;
    private readonly string $prefijo;

    /**
     * @param string               $raiz la carpeta del subsistema
     * @param LoggerInterface|null $log  para las pruebas; si no, el log a la salida de errores
     */
    public function __construct(string $raiz, Configuracion $configuracion, ?LoggerInterface $log = null)
    {
        $configuracion->verificarQueNoQuedenValoresDeEjemplo();
        $subsistema = (string) $configuracion->requerir('app.subsistema');
        $this->log = $log ?? new Log($subsistema);
        $this->prefijo = rtrim((string) $configuracion->obtener('app.prefijo', ''), '/');
        $this->errores = new ManejadorDeErrores($this->log, $configuracion->enDesarrollo());
        $this->vista = new Vista(self::directoriosDePlantillas($subsistema), $this->prefijo, $configuracion->enDesarrollo());
        $this->identificacion = new Identificacion((string) $configuracion->requerir('nginx.secreto'), $this->log);
        $this->controlDeAcceso = new ControlDeAcceso();
        $this->csrf = new ProteccionCsrf();

        $this->contenedor = new Contenedor();
        $this->contenedor->registrar(Configuracion::class, $configuracion);
        $this->contenedor->registrar(Vista::class, $this->vista);
        $this->contenedor->registrar(LoggerInterface::class, $this->log);
        $this->contenedor->fabrica(BaseDeDatos::class, static fn (): BaseDeDatos => BaseDeDatos::conectar($configuracion));

        $this->router = new Router();
        $declararRutas = require $raiz . '/rutas.php';
        $declararRutas($this->router);
    }

    /**
     * El punto de entrada de public/index.php. Si el subsistema ni siquiera arranca (por ejemplo,
     * porque falta config.ini), responde 500 sin detalles y deja el motivo en el log.
     */
    public static function iniciar(string $raiz): void
    {
        ini_set('display_errors', '0');
        // El nombre sale de la carpeta y no de la configuración, que puede ser justo lo que falla.
        $log = new Log(basename($raiz));
        try {
            $aplicacion = new self($raiz, Configuracion::desdeArchivo($raiz . '/config/config.ini'), $log);
            $aplicacion->errores->instalar();
        } catch (Throwable $error) {
            $log->critical('El subsistema no arrancó: {mensaje}', ['mensaje' => $error->getMessage(), 'exception' => $error]);
            http_response_code(500);
            echo 'Error interno.';

            return;
        }

        $aplicacion->atender(Peticion::desdeGlobales())->enviar();
    }

    /**
     * El middleware de la E2 (sección 5) corre acá, antes del controlador y en este orden:
     * quién es el usuario y su token CSRF, que valen aunque la ruta no exista (la página de error
     * también muestra el encabezado con la sesión); después el rol mínimo de la ruta y el token de
     * los formularios. Así ninguna ruta puede saltearlo.
     */
    public function atender(Peticion $peticion): Respuesta
    {
        $inicio = hrtime(true);
        try {
            $peticion = $this->csrf->asignarToken($this->identificacion->identificar($peticion));
            $this->vista->compartirPeticion($peticion);

            [$ruta, $parametros] = $this->router->resolver($peticion->metodo(), $peticion->ruta());
            $peticion = $peticion->conRuta($ruta, $parametros);
            $this->controlDeAcceso->verificar($peticion);
            $this->csrf->verificar($peticion);

            $respuesta = $this->despachar($peticion);
        } catch (Throwable $error) {
            $respuesta = $this->errores->respuestaPara($error, $peticion, $this->vista);
        }
        $respuesta = $this->csrf->guardarToken($peticion, $respuesta);
        $this->registrarPedido($peticion, $respuesta, $inicio);

        return $respuesta;
    }

    /**
     * Una línea por pedido con el método, la ruta, el estado y cuánto tardó. La ruta va como
     * patrón (/salas/{id}) si existe, y nunca la consulta (?q=…), el cuerpo, las cookies, la IP ni
     * el usuario: el log sirve para ver qué falla o qué anda lento, no quién hizo qué.
     */
    private function registrarPedido(Peticion $peticion, Respuesta $respuesta, int $inicio): void
    {
        $this->log->info('{metodo} {ruta} {estado} {duracion} ms', [
            'metodo' => $peticion->metodo(),
            'ruta' => $peticion->rutaResuelta()?->patron ?? $peticion->ruta(),
            'estado' => $respuesta->estado(),
            'duracion' => intdiv(hrtime(true) - $inicio, 1_000_000),
        ]);
    }

    private function despachar(Peticion $peticion): Respuesta
    {
        [$clase, $metodo] = $peticion->rutaResuelta()?->accion
            ?? throw new LogicException('Se despachó una petición sin ruta.');

        $controlador = $this->contenedor->obtener($clase);
        if ($controlador instanceof Controlador) {
            $controlador->prepararParaResponder($this->vista, $this->prefijo);
        }

        $respuesta = $controlador->$metodo($peticion);
        if (!$respuesta instanceof Respuesta) {
            throw new LogicException("{$clase}::{$metodo} tiene que devolver una Respuesta.");
        }

        return $respuesta;
    }

    /**
     * Primero las plantillas del subsistema, después la base y los parciales comunes del paquete
     * front, y al final las del núcleo, que el front puede reemplazar.
     *
     * @return list<string>
     */
    private static function directoriosDePlantillas(string $subsistema): array
    {
        $directorios = [];
        if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled(self::PAQUETE_FRONT)) {
            $front = InstalledVersions::getInstallPath(self::PAQUETE_FRONT) . '/plantillas';
            $directorios[] = "{$front}/{$subsistema}";
            $directorios[] = $front;
        }
        $directorios[] = dirname(__DIR__) . '/plantillas';

        return $directorios;
    }
}
