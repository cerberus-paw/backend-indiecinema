<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Controladores;

use IndieCinema\Moderacion\Servicios\ArchivosDeSolicitudes;
use IndieCinema\Moderacion\Servicios\ServicioDeSolicitudes;
use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use LogicException;
use Psr\Log\LoggerInterface;

/**
 * GET /moderacion/solicitudes/{id}/foto: la foto de la sala que mandó una solicitud (EF-07.2),
 * desde el volumen de moderación, que está fuera del webroot. Sólo para quien la mandó y el
 * administrador: para los demás no existe.
 */
final class FotoDeSolicitud extends Controlador
{
    public function __construct(
        private readonly ServicioDeSolicitudes $servicio,
        private readonly ArchivosDeSolicitudes $archivos,
        private readonly LoggerInterface $log,
    ) {
    }

    public function mostrar(Peticion $peticion): Respuesta
    {
        $id = (string) $peticion->parametro('id');
        $usuario = $peticion->usuario() ?? throw new LogicException('Ruta con sesión sin usuario.');
        $foto = $this->servicio->fotoParaMostrar($id, $usuario);

        // El nombre no cambia: una foto nueva es otra solicitud. Sirve de ETag para que el
        // navegador pregunte y, si es la misma, no la vuelva a bajar.
        $etiqueta = '"' . $foto . '"';
        $cabeceras = ['ETag' => $etiqueta, 'Cache-Control' => 'private, no-cache'];
        if ($peticion->cabecera('If-None-Match') === $etiqueta) {
            return self::conCabeceras(new Respuesta('', 304), $cabeceras);
        }

        $contenido = $this->archivos->contenido($foto);
        if ($contenido === null) {
            $this->log->error('La solicitud {solicitud} apunta a la foto {foto}, que no está en el volumen.', ['solicitud' => $id, 'foto' => $foto]);

            throw ExcepcionHttp::noEncontrada();
        }

        // El tipo sale de la extensión, que se eligió por el contenido al guardarla; nginx agrega
        // X-Content-Type-Options: nosniff, así el navegador no la interpreta como otra cosa.
        return self::conCabeceras(new Respuesta($contenido), $cabeceras + ['Content-Type' => ArchivosDeSolicitudes::tipo($foto)]);
    }

    /**
     * @param array<string, string> $cabeceras
     */
    private static function conCabeceras(Respuesta $respuesta, array $cabeceras): Respuesta
    {
        foreach ($cabeceras as $nombre => $valor) {
            $respuesta = $respuesta->conCabecera($nombre, $valor);
        }

        return $respuesta;
    }
}
