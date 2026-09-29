<?php

declare(strict_types=1);

namespace IndieCinema\Nucleo;

use Composer\InstalledVersions;
use IndieCinema\Nucleo\Errores\ManejadorDeErrores;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Ruteo\Router;
use LogicException;
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
    private readonly string $prefijo;

    /**
     * @param string $raiz la carpeta del subsistema
     */
    public function __construct(string $raiz, Configuracion $configuracion)
    {
        $subsistema = (string) $configuracion->requerir('app.subsistema');
        $this->prefijo = rtrim((string) $configuracion->obtener('app.prefijo', ''), '/');
        $this->errores = new ManejadorDeErrores($configuracion->enDesarrollo());
        $this->vista = new Vista(self::directoriosDePlantillas($subsistema), $this->prefijo, $configuracion->enDesarrollo());

        $this->contenedor = new Contenedor();
        $this->contenedor->registrar(Configuracion::class, $configuracion);
        $this->contenedor->registrar(Vista::class, $this->vista);

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
        try {
            $aplicacion = new self($raiz, Configuracion::desdeArchivo($raiz . '/config/config.ini'));
            $aplicacion->errores->instalar();
        } catch (Throwable $error) {
            error_log('El subsistema no arrancó: ' . $error);
            http_response_code(500);
            echo 'Error interno.';

            return;
        }

        $aplicacion->atender(Peticion::desdeGlobales())->enviar();
    }

    public function atender(Peticion $peticion): Respuesta
    {
        try {
            [$ruta, $parametros] = $this->router->resolver($peticion->metodo(), $peticion->ruta());

            return $this->despachar($peticion->conRuta($ruta, $parametros));
        } catch (Throwable $error) {
            return $this->errores->respuestaPara($error, $peticion, $this->vista);
        }
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
