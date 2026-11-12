<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Servicios;

use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Seguridad\Rol;
use IndieCinema\Nucleo\Seguridad\Usuario;
use IndieCinema\Nucleo\Uuid;
use IndieCinema\Programacion\Modelo\DatosDeSala;
use IndieCinema\Programacion\Modelo\FichaDeSala;
use IndieCinema\Programacion\Modelo\FiltrosDeCartelera;
use IndieCinema\Programacion\Modelo\ImagenSubida;
use IndieCinema\Programacion\Modelo\ResumenDeFuncion;
use IndieCinema\Programacion\Modelo\Sala;
use IndieCinema\Programacion\Repositorios\RepositorioDeCartelera;
use IndieCinema\Programacion\Repositorios\RepositorioDeFunciones;
use IndieCinema\Programacion\Repositorios\RepositorioDeSalas;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * El alta y la edición de salas, su habilitación y lo que se muestra de ellas. El rol lo controla
 * la ruta; que la sala sea del que la edita, que la ruta no puede saber, se controla acá.
 */
final class ServicioDeSalas
{
    public function __construct(
        private readonly RepositorioDeSalas $salas,
        private readonly ArchivosDeSalas $archivos,
        private readonly LoggerInterface $log,
        private readonly RepositorioDeCartelera $cartelera,
        private readonly RepositorioDeFunciones $funciones,
    ) {
    }

    /**
     * Lo menos que puede tener de capacidad la sala: el mayor cupo de sus funciones que siguen
     * abiertas (F-01.1). Si no, una función quedaba con más lugares que butacas.
     */
    public function capacidadMinima(Sala $sala): int
    {
        return $this->funciones->mayorCupoAbierto($sala->id);
    }

    /**
     * Las que puede ver cualquiera, con o sin sesión.
     *
     * @param ?string $texto si viene, sólo las que lo tienen en el nombre o la localidad
     *
     * @return list<Sala>
     */
    public function publicadas(?string $texto = null): array
    {
        return $this->salas->publicadas($texto);
    }

    /**
     * La sala de una solicitud que aprobó moderación (EF-07.1), habilitada, con el id que eligió
     * moderación: así repetir el pedido no crea otra.
     *
     * - Si no hay ninguna con ese id, la crea con la foto.
     * - Si hay una sin habilitar del mismo organizador (la de una solicitud que se mudó a
     *   moderación), le pone los datos y la foto corregidos allá y la habilita; la foto anterior se
     *   borra recién cuando la base apunta a la nueva.
     * - Si hay una habilitada del mismo organizador, es el reintento de un pedido que ya se hizo: no
     *   la toca, porque sus datos ya son de su organizador.
     *
     * @return array{Sala, bool} la sala y si se creó
     *
     * @throws ExcepcionHttp 409 si el id es de una sala de otro organizador o de una dada de baja
     */
    public function crearParaSolicitud(string $id, string $organizadorId, DatosDeSala $datos, ImagenSubida $imagen): array
    {
        $existente = $this->salas->buscar($id);
        if ($existente === null ? $this->salas->idUsado($id) : !$existente->esDe($organizadorId)) {
            throw new ExcepcionHttp(409, 'Ese id es de otra sala.');
        }
        if ($existente?->habilitada) {
            return [$existente, false];
        }

        $foto = $this->archivos->guardar($imagen);
        $sala = $existente ?? new Sala($id, $organizadorId, $datos, $foto, habilitada: true);
        $anterior = $existente?->imagen;
        try {
            if ($existente === null) {
                $this->salas->agregar($sala);
            } else {
                $sala->cambiarDatos($datos);
                $sala->cambiarImagen($foto);
                $this->salas->guardar($sala);
            }
        } catch (Throwable $error) {
            // Que no quede en el volumen una foto que nadie usa.
            $this->archivos->borrar($foto);
            throw $error;
        }
        // La anterior se borra recién cuando la base apunta a la nueva. Si habilitarla falla, el
        // reintento la encuentra sin habilitar y la completa otra vez.
        if ($anterior !== null) {
            $this->archivos->borrar($anterior);
        }
        if ($existente !== null) {
            $sala->cambiarHabilitacion(true);
            $this->salas->guardarHabilitacion($sala);
        }
        $this->log->notice('Moderación habilitó la sala {sala} de {organizador}, de una solicitud aprobada.', [
            'sala' => $sala->id,
            'organizador' => $organizadorId,
        ]);

        return [$sala, $existente === null];
    }

    /**
     * Las que existen de esos ids, con el id como clave, sin las dadas de baja (EF-07.1).
     *
     * @param list<string> $ids
     *
     * @return array<string, Sala>
     */
    public function varias(array $ids): array
    {
        return $this->salas->varias($ids);
    }

    /**
     * @return list<Sala>
     */
    public function delOrganizador(Usuario $organizador): array
    {
        return $this->salas->delOrganizador($organizador->id);
    }

    /**
     * Publica la sala en el sitio o la retira. Cada cambio queda en el log, con quién lo hizo (el
     * administrador desde la bandeja, o el moderador por la API interna, EF-07.1) y, si lo hay, por
     * qué: es el registro de auditoría que pide la historia. Una sala retirada deja de verse en el
     * listado público y sus funciones, en la cartelera, y no programa funciones nuevas.
     *
     * @throws ExcepcionHttp 404 si no existe o está dada de baja
     */
    public function cambiarHabilitacion(string $id, bool $habilitada, string $quien, ?string $motivo = null): Sala
    {
        $sala = $this->buscar($id);
        if ($sala->habilitada === $habilitada) {
            // Dos pestañas, o recargar el POST: no hay nada que cambiar ni que registrar.
            return $sala;
        }
        $sala->cambiarHabilitacion($habilitada);
        $this->salas->guardarHabilitacion($sala);
        $accion = $habilitada ? '{quien} habilitó la sala {sala}' : '{quien} deshabilitó la sala {sala}';
        $this->log->notice($accion . ($motivo === null ? '.' : ': {motivo}'), [
            'quien' => $quien,
            'sala' => $sala->id,
            'motivo' => $motivo,
        ]);

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
     * La ficha de la sala con sus próximas funciones, las mismas que muestra la cartelera. Como la
     * imagen, una sala sin habilitar sólo la ve su organizador, y sin funciones, porque no están
     * en la cartelera. Si no, 404, como si no existiera.
     *
     * @throws ExcepcionHttp 404
     */
    public function ficha(string $id, ?Usuario $usuario): FichaDeSala
    {
        $sala = $this->buscar($id);
        if (!$sala->laPuedeVer($usuario?->id)) {
            throw ExcepcionHttp::noEncontrada();
        }

        return new FichaDeSala($sala, $this->cartelera->todas(new FiltrosDeCartelera(salaId: $sala->id)));
    }

    /**
     * Las últimas funciones que se dieron en la sala, para el historial de su ficha (EF-04.3).
     *
     * @return list<ResumenDeFuncion>
     */
    public function proyectadas(Sala $sala, int $cuantas = 10): array
    {
        return $this->funciones->finalizadasDeLaSala($sala->id, $cuantas);
    }

    /**
     * El nombre de la imagen de la sala, si quien pide la puede ver: una sala sin habilitar sólo
     * la ven su organizador y el administrador, que la mira en su bandeja antes de habilitarla.
     * Si no, 404, como si no existiera.
     *
     * @throws ExcepcionHttp 404
     */
    public function imagenParaMostrar(string $id, ?Usuario $usuario): string
    {
        $sala = $this->buscar($id);
        $administrador = $usuario?->alcanza(Rol::Administrador) ?? false;
        if ((!$sala->laPuedeVer($usuario?->id) && !$administrador) || $sala->imagen === null) {
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
