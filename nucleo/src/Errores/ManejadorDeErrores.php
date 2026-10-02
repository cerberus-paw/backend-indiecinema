<?php

declare(strict_types=1);

namespace IndieCinema\Nucleo\Errores;

use ErrorException;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Vista;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Convierte cualquier error en una respuesta sin detalles para el usuario y deja el detalle en el
 * log (E1: errores de PHP ocultos al usuario y registrados en el log).
 */
final class ManejadorDeErrores
{
    private const MENSAJE_INTERNO = 'Algo salió mal. Probá de nuevo en un rato.';

    /**
     * @param bool $mostrarDetalle sólo en desarrollo: agrega el error completo a la página
     */
    public function __construct(private readonly LoggerInterface $log, private readonly bool $mostrarDetalle)
    {
    }

    /**
     * Se instala una vez, al arrancar el subsistema. Los avisos y advertencias de PHP también
     * cortan la petición: es preferible una página de error a seguir con datos a medias.
     */
    public function instalar(): void
    {
        ini_set('display_errors', '0');
        error_reporting(E_ALL);
        set_error_handler(static function (int $nivel, string $mensaje, string $archivo, int $linea): bool {
            if ((error_reporting() & $nivel) === 0) {
                return false; // silenciado con @
            }
            throw new ErrorException($mensaje, 0, $nivel, $archivo, $linea);
        });
    }

    public function respuestaPara(Throwable $error, Peticion $peticion, Vista $vista): Respuesta
    {
        if ($error instanceof ExcepcionHttp) {
            $estado = $error->estado;
            $mensaje = $error->getMessage();
            $cabeceras = $error->cabeceras;
            $detalle = null;
        } else {
            $this->log->error('{metodo} {ruta}: {mensaje}', [
                'metodo' => $peticion->metodo(),
                'ruta' => $peticion->ruta(),
                'mensaje' => $error->getMessage(),
                'exception' => $error,
            ]);
            $estado = 500;
            $mensaje = self::MENSAJE_INTERNO;
            $cabeceras = [];
            $detalle = $this->mostrarDetalle ? (string) $error : null;
        }

        $respuesta = $peticion->aceptaJson() || $peticion->rutaResuelta()?->esInterna()
            ? Respuesta::json(array_filter(['error' => $mensaje, 'detalle' => $detalle]), $estado)
            : $this->pagina($vista, $estado, $mensaje, $detalle);

        foreach ($cabeceras as $nombre => $valor) {
            $respuesta = $respuesta->conCabecera($nombre, $valor);
        }

        return $respuesta;
    }

    private function pagina(Vista $vista, int $estado, string $mensaje, ?string $detalle): Respuesta
    {
        try {
            $html = $vista->renderizar('error.html.twig', [
                'estado' => $estado,
                'mensaje' => $mensaje,
                'detalle' => $detalle,
            ]);

            return Respuesta::html($html, $estado);
        } catch (Throwable $falla) {
            // Si falla hasta la plantilla de error, texto plano y nunca el detalle.
            $this->log->error('No se pudo mostrar la página de error.', ['exception' => $falla]);

            return (new Respuesta($mensaje, $estado))->conCabecera('Content-Type', 'text/plain; charset=utf-8');
        }
    }
}
