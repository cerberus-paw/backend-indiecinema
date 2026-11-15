<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Controladores;

use IndieCinema\Moderacion\Servicios\ArchivosDeDocumentos;
use IndieCinema\Moderacion\Servicios\ServicioDeSolicitudes;
use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use LogicException;
use Psr\Log\LoggerInterface;

/**
 * GET /moderacion/documentos/{id}: el PDF del seguro o de la habilitación de una sala (EF-07.4),
 * desde el volumen de moderación, que está fuera del webroot. Sólo para quien mandó la solicitud y
 * la moderación: para los demás no existe.
 *
 * Va como adjunto, con un nombre que arma el sitio (nunca el que subió el usuario) y nosniff, así
 * el navegador no lo abre en el sitio ni lo toma por otra cosa. Tiene datos de la póliza: no se
 * guarda en ningún caché.
 */
final class DocumentoDeSala extends Controlador
{
    public function __construct(
        private readonly ServicioDeSolicitudes $servicio,
        private readonly ArchivosDeDocumentos $archivos,
        private readonly LoggerInterface $log,
    ) {
    }

    public function bajar(Peticion $peticion): Respuesta
    {
        $id = (string) $peticion->parametro('id');
        $usuario = $peticion->usuario() ?? throw new LogicException('Ruta con sesión sin usuario.');
        $documento = $this->servicio->documentoParaBajar($id, $usuario);

        $contenido = $this->archivos->contenido($documento->archivo);
        if ($contenido === null) {
            $this->log->error('El documento {documento} apunta a {archivo}, que no está en el volumen.', ['documento' => $id, 'archivo' => $documento->archivo]);

            throw ExcepcionHttp::noEncontrada();
        }

        return new Respuesta($contenido)
            ->conCabecera('Content-Type', 'application/pdf')
            ->conCabecera('Content-Disposition', "attachment; filename=\"{$documento->tipo->value}.pdf\"")
            ->conCabecera('X-Content-Type-Options', 'nosniff')
            ->conCabecera('Cache-Control', 'private, no-store');
    }
}
