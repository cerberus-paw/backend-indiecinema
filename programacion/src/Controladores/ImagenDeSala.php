<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Controladores;

use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Programacion\Servicios\ArchivosDeSalas;
use IndieCinema\Programacion\Servicios\ServicioDeSalas;
use Psr\Log\LoggerInterface;

/**
 * GET /salas/{id}/imagen: entrega la foto de la sala desde el volumen, que está fuera del
 * webroot. Pasa por acá y no la sirve nginx porque antes hay que ver si la sala se puede mostrar.
 */
final class ImagenDeSala extends Controlador
{
    public function __construct(
        private readonly ServicioDeSalas $servicio,
        private readonly ArchivosDeSalas $archivos,
        private readonly LoggerInterface $log,
    ) {
    }

    public function mostrar(Peticion $peticion): Respuesta
    {
        $id = (string) $peticion->parametro('id');
        $imagen = $this->servicio->imagenParaMostrar($id, $peticion->usuario());

        // El nombre cambia con cada imagen nueva: sirve de ETag para que el navegador pregunte y,
        // si no cambió, no la vuelva a bajar.
        $etiqueta = '"' . $imagen . '"';
        $cabeceras = ['ETag' => $etiqueta, 'Cache-Control' => 'no-cache'];
        if ($peticion->cabecera('If-None-Match') === $etiqueta) {
            return self::conCabeceras(new Respuesta('', 304), $cabeceras);
        }

        $contenido = $this->archivos->contenido($imagen);
        if ($contenido === null) {
            $this->log->error('La sala {sala} apunta a la imagen {imagen}, que no está en el volumen.', ['sala' => $id, 'imagen' => $imagen]);

            throw ExcepcionHttp::noEncontrada();
        }

        // El tipo sale de la extensión, que se eligió por el contenido al guardarla; nginx agrega
        // X-Content-Type-Options: nosniff, así el navegador no la interpreta como otra cosa.
        return self::conCabeceras(new Respuesta($contenido), $cabeceras + ['Content-Type' => ArchivosDeSalas::tipo($imagen)]);
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
