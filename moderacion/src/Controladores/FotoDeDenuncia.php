<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Controladores;

use IndieCinema\Moderacion\Repositorios\RepositorioDeDenuncias;
use IndieCinema\Moderacion\Servicios\ArchivosDeDenuncias;
use IndieCinema\Moderacion\Servicios\ArchivosDeSolicitudes;
use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Uuid;
use Psr\Log\LoggerInterface;

/**
 * GET /moderacion/denuncias/{id}/foto: la foto de una denuncia (EF-22.11), desde el volumen de
 * moderación, que está fuera del webroot. La ruta pide el rol de moderador: quien denunció no la
 * vuelve a ver.
 */
final class FotoDeDenuncia extends Controlador
{
    public function __construct(
        private readonly RepositorioDeDenuncias $denuncias,
        private readonly ArchivosDeDenuncias $archivos,
        private readonly LoggerInterface $log,
    ) {
    }

    /**
     * @throws ExcepcionHttp 404 si la denuncia no existe o no tiene foto
     */
    public function mostrar(Peticion $peticion): Respuesta
    {
        $id = (string) $peticion->parametro('id');
        $foto = Uuid::esValido($id) ? $this->denuncias->fotoDe($id) : null;
        if ($foto === null) {
            throw ExcepcionHttp::noEncontrada();
        }
        $contenido = $this->archivos->contenido($foto);
        if ($contenido === null) {
            $this->log->error('La denuncia {denuncia} apunta a la foto {foto}, que no está en el volumen.', ['denuncia' => $id, 'foto' => $foto]);

            throw ExcepcionHttp::noEncontrada();
        }

        // El tipo sale de la extensión, que se eligió por el contenido al guardarla. El nombre no
        // cambia, así que el navegador la puede guardar.
        return (new Respuesta($contenido))
            ->conCabecera('Content-Type', ArchivosDeSolicitudes::tipo($foto))
            ->conCabecera('Cache-Control', 'private, max-age=86400');
    }
}
