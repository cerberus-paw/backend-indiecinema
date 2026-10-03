<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Servicios;

use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Seguridad\Usuario;
use IndieCinema\Nucleo\Uuid;
use IndieCinema\Programacion\Modelo\DatosDeSala;
use IndieCinema\Programacion\Modelo\ImagenSubida;
use IndieCinema\Programacion\Modelo\Sala;
use IndieCinema\Programacion\Repositorios\RepositorioDeSalas;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * El alta y la edición de salas. El rol de organizador lo controla la ruta; que la sala sea del
 * que la edita, que la ruta no puede saber, se controla acá.
 */
final class ServicioDeSalas
{
    public function __construct(
        private readonly RepositorioDeSalas $salas,
        private readonly ArchivosDeSalas $archivos,
        private readonly LoggerInterface $log,
    ) {
    }

    public function crear(Usuario $organizador, DatosDeSala $datos, ImagenSubida $imagen): Sala
    {
        $sala = Sala::nueva($organizador->id, $datos, $this->archivos->guardar($imagen));
        $this->guardandoImagen((string) $sala->imagen, fn () => $this->salas->agregar($sala));

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
        $sala = $this->buscar($id);
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

    /**
     * @param ?ImagenSubida $imagen la nueva; sin una, queda la que tenía
     */
    public function actualizar(Sala $sala, DatosDeSala $datos, ?ImagenSubida $imagen): void
    {
        $anterior = $sala->imagen;
        $sala->cambiarDatos($datos);
        if ($imagen === null) {
            $this->salas->guardar($sala);

            return;
        }

        $sala->cambiarImagen($this->archivos->guardar($imagen));
        $this->guardandoImagen((string) $sala->imagen, fn () => $this->salas->guardar($sala));
        // La anterior se borra recién cuando la base apunta a la nueva.
        if ($anterior !== null) {
            $this->archivos->borrar($anterior);
        }
    }

    /**
     * El nombre de la imagen de la sala, si quien pide la puede ver: una sala sin habilitar sólo
     * la ve su organizador. Si no, 404, como si no existiera.
     *
     * @throws ExcepcionHttp 404
     */
    public function imagenParaMostrar(string $id, ?Usuario $usuario): string
    {
        $sala = $this->buscar($id);
        if (!$sala->laPuedeVer($usuario?->id) || $sala->imagen === null) {
            throw ExcepcionHttp::noEncontrada();
        }

        return $sala->imagen;
    }

    /**
     * @throws ExcepcionHttp 404 si no existe o está dada de baja
     */
    private function buscar(string $id): Sala
    {
        return (Uuid::esValido($id) ? $this->salas->buscar($id) : null) ?? throw ExcepcionHttp::noEncontrada();
    }

    /**
     * Corre $guardar, que anota la imagen nueva en la base; si falla, la borra del volumen para
     * que no quede un archivo que nadie usa.
     *
     * @param callable(): void $guardar
     */
    private function guardandoImagen(string $imagen, callable $guardar): void
    {
        try {
            $guardar();
        } catch (Throwable $error) {
            $this->archivos->borrar($imagen);
            throw $error;
        }
    }
}
