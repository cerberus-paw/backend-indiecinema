<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Controladores;

use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Uuid;
use IndieCinema\Programacion\Repositorios\RepositorioDeObras;
use IndieCinema\Programacion\Servicios\AfichesDeObras;
use IndieCinema\Programacion\Servicios\ArchivosDeSalas;
use Psr\Log\LoggerInterface;

/**
 * GET /obras/{id}/afiche: el afiche de una obra, para la tarjeta de sus funciones. Es público: el
 * catálogo de obras no tiene nada que esconder, a diferencia de una sala sin habilitar.
 */
final class AficheDeObra extends Controlador
{
    public function __construct(
        private readonly RepositorioDeObras $obras,
        private readonly AfichesDeObras $afiches,
        private readonly LoggerInterface $log,
    ) {
    }

    public function mostrar(Peticion $peticion): Respuesta
    {
        $id = (string) $peticion->parametro('id');
        $afiche = (Uuid::esValido($id) ? $this->obras->buscar($id) : null)->afiche
            ?? throw ExcepcionHttp::noEncontrada();

        // Como la foto de la sala: el nombre del archivo sirve de ETag.
        $etiqueta = '"' . $afiche . '"';
        if ($peticion->cabecera('If-None-Match') === $etiqueta) {
            return (new Respuesta('', 304))->conCabecera('ETag', $etiqueta)->conCabecera('Cache-Control', 'no-cache');
        }

        $contenido = $this->afiches->contenido($afiche);
        if ($contenido === null) {
            $this->log->error('La obra {obra} apunta al afiche {afiche}, que no está en el volumen.', ['obra' => $id, 'afiche' => $afiche]);

            throw ExcepcionHttp::noEncontrada();
        }

        return (new Respuesta($contenido))
            ->conCabecera('ETag', $etiqueta)
            ->conCabecera('Cache-Control', 'no-cache')
            ->conCabecera('Content-Type', ArchivosDeSalas::tipo($afiche));
    }
}
