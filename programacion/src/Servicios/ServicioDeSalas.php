<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Servicios;

use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Seguridad\Usuario;
use IndieCinema\Nucleo\Uuid;
use IndieCinema\Programacion\Modelo\DatosDeSala;
use IndieCinema\Programacion\Modelo\Sala;
use IndieCinema\Programacion\Repositorios\RepositorioDeSalas;
use Psr\Log\LoggerInterface;

/**
 * El alta y la edición de salas. El rol de organizador lo controla la ruta; que la sala sea del
 * que la edita, que la ruta no puede saber, se controla acá.
 */
final class ServicioDeSalas
{
    public function __construct(
        private readonly RepositorioDeSalas $salas,
        private readonly LoggerInterface $log,
    ) {
    }

    public function crear(Usuario $organizador, DatosDeSala $datos): Sala
    {
        $sala = Sala::nueva($organizador->id, $datos);
        $this->salas->agregar($sala);

        return $sala;
    }

    /**
     * La sala para editarla, si es del organizador. Si no existe o es de otro, la respuesta es la
     * misma, 404: así un organizador no puede averiguar qué ids de sala existen.
     *
     * @throws ExcepcionHttp 404
     */
    public function paraEditar(string $id, Usuario $organizador): Sala
    {
        $sala = Uuid::esValido($id) ? $this->salas->buscar($id) : null;
        if ($sala === null) {
            throw ExcepcionHttp::noEncontrada();
        }
        if (!$sala->esDe($organizador->id)) {
            // No es un error de la aplicación sino alguien probando ids ajenos: queda en el log.
            $this->log->warning('{usuario} quiso editar la sala {sala}, que no es suya: se responde 404.', [
                'usuario' => $organizador->id,
                'sala' => $id,
            ]);

            throw ExcepcionHttp::noEncontrada();
        }

        return $sala;
    }

    public function actualizar(Sala $sala, DatosDeSala $datos): void
    {
        $sala->cambiarDatos($datos);
        $this->salas->guardar($sala);
    }
}
