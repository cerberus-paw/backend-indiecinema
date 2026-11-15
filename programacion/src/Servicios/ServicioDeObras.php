<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Servicios;

use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Seguridad\Usuario;
use IndieCinema\Nucleo\Uuid;
use IndieCinema\Nucleo\Seguridad\Rol;
use IndieCinema\Programacion\Modelo\DatosDeObra;
use IndieCinema\Programacion\Modelo\EstadoObra;
use IndieCinema\Programacion\Modelo\FiltrosDeCartelera;
use IndieCinema\Programacion\Modelo\FuncionEnCartelera;
use IndieCinema\Programacion\Modelo\Genero;
use IndieCinema\Programacion\Modelo\ImagenSubida;
use IndieCinema\Programacion\Modelo\Obra;
use IndieCinema\Programacion\Modelo\ReglaIncumplida;
use IndieCinema\Programacion\Repositorios\RepositorioDeCartelera;
use IndieCinema\Programacion\Repositorios\RepositorioDeGeneros;
use IndieCinema\Programacion\Repositorios\RepositorioDeObras;
use Psr\Log\LoggerInterface;

/**
 * El catálogo público de obras y la gestión de obras por los organizadores (alta, edición y baja lógica).
 */
final class ServicioDeObras
{
    public function __construct(
        private readonly RepositorioDeObras $obras,
        private readonly RepositorioDeGeneros $generos,
        private readonly AfichesDeObras $afiches,
        private readonly RepositorioDeCartelera $cartelera,
        private readonly LoggerInterface $log,
    ) {
    }

    /**
     * Catálogo público de obras activas, opcionalmente filtradas por texto o género.
     *
     * @return list<Obra>
     */
    public function catalogo(?string $texto = null, ?string $generoId = null): array
    {
        return $this->obras->buscarEnCatalogo($texto, $generoId);
    }

    /**
     * Las obras activas que pertenecen al organizador.
     *
     * @return list<Obra>
     */
    public function delOrganizador(Usuario $organizador): array
    {
        return $this->obras->delDueno($organizador->id);
    }

    /**
     * @throws ExcepcionHttp 404 si la obra no existe, no es un UUID o está dada de baja
     */
    public function buscar(string $id): Obra
    {
        if (!Uuid::esValido($id)) {
            throw ExcepcionHttp::noEncontrada();
        }
        $obra = $this->obras->buscar($id);
        if ($obra === null) {
            throw ExcepcionHttp::noEncontrada();
        }

        return $obra;
    }

    /**
     * Las pendientes de revisión, para la bandeja de moderación (EF-07.7).
     *
     * @return list<array{Obra, string}> la obra y cuándo se cargó
     */
    public function pendientes(): array
    {
        return $this->obras->pendientes();
    }

    /**
     * La aprueba o la rechaza (EF-07.7). Lo decidió un moderador en la bandeja de moderación, que
     * guarda el historial; acá queda en el log.
     *
     * @return array{Obra, bool} la obra como quedó y si cambió: resolver lo mismo otra vez no
     *                           cambia nada, ni el motivo
     *
     * @throws ExcepcionHttp 404 si no existe o se dio de baja
     */
    public function revisar(string $id, EstadoObra $estado, string $motivo, string $moderadorId): array
    {
        $this->buscar($id);
        $cambio = $this->obras->revisar($id, $estado, $motivo);
        if ($cambio) {
            $this->log->notice('{moderador} revisó la obra {obra}: {estado} ({motivo})', [
                'moderador' => $moderadorId,
                'obra' => $id,
                'estado' => $estado->value,
                'motivo' => $motivo,
            ]);
        }

        return [$this->buscar($id), $cambio];
    }

    /**
     * Una obra del catálogo público: sólo las aprobadas (EF-07.7). Las demás no existen para el
     * sitio, tampoco para su dueño, que las ve en «Mis obras».
     *
     * @throws ExcepcionHttp 404
     */
    public function publicada(string $id): Obra
    {
        $obra = $this->buscar($id);
        if (!$obra->estaAprobada()) {
            throw ExcepcionHttp::noEncontrada();
        }

        return $obra;
    }

    /**
     * Obtiene una obra para su edición, verificando que quien la pide sea su dueño.
     *
     * @throws ExcepcionHttp 404 si no existe o no pertenece al organizador
     */
    public function paraEditar(string $id, Usuario $organizador): Obra
    {
        $obra = $this->buscar($id);
        if ($obra->duenoId !== $organizador->id) {
            throw ExcepcionHttp::noEncontrada();
        }

        return $obra;
    }

    /**
     * Da de alta una nueva obra con su afiche en el catálogo.
     */
    public function crear(Usuario $organizador, DatosDeObra $datos, ?ImagenSubida $imagen = null): Obra
    {
        $afiche = $imagen !== null ? $this->afiches->guardar($imagen) : null;
        // EF-07.7: queda pendiente hasta que la aprueba la moderación.
        $obra = Obra::crear($organizador->id, $datos, $afiche, EstadoObra::Pendiente);
        $this->obras->agregar($obra);
        $this->log->info('Obra creada: {id} - {titulo} por {organizador}', [
            'id' => $obra->id,
            'titulo' => $obra->titulo,
            'organizador' => $organizador->id,
        ]);

        return $obra;
    }

    /**
     * Actualiza los datos de una obra y reemplaza su afiche si se subió uno nuevo.
     */
    public function actualizar(Obra $obra, DatosDeObra $datos, ?ImagenSubida $nuevaImagen = null): Obra
    {
        $nuevoAfiche = $nuevaImagen !== null ? $this->afiches->guardar($nuevaImagen) : null;
        $viejoAfiche = $nuevaImagen !== null ? $obra->afiche : null;
        $actualizada = $obra->actualizar($datos, $nuevoAfiche);
        $this->obras->actualizar($actualizada);
        if ($viejoAfiche !== null) {
            $this->afiches->borrar($viejoAfiche);
        }
        $this->log->info('Obra actualizada: {id} - {titulo}', [
            'id' => $obra->id,
            'titulo' => $actualizada->titulo,
        ]);

        return $actualizada;
    }

    /**
     * Baja lógica de la obra si no tiene funciones futuras asociadas.
     *
     * @throws ReglaIncumplida si la obra participa en funciones futuras o en votación
     */
    public function darDeBaja(string $id, Usuario $organizador): void
    {
        $obra = $this->paraEditar($id, $organizador);
        if ($this->tieneFuncionesFuturas($id)) {
            throw new ReglaIncumplida('No se puede dar de baja una obra que tiene funciones futuras o en votación.');
        }

        $this->obras->darDeBaja($id);
        $this->log->info('Obra dada de baja: {id} - {titulo}', [
            'id' => $obra->id,
            'titulo' => $obra->titulo,
        ]);
    }

    public function tieneFuncionesFuturas(string $id): bool
    {
        return $this->obras->tieneFuncionesFuturas($id);
    }

    /**
     * El nombre de archivo del afiche, si lo puede ver. El de una aprobada o rechazada es público:
     * las funciones que ya tenía lo siguen mostrando. El de una pendiente lo ven su dueño y la
     * moderación, que la revisa (EF-07.7).
     *
     * @throws ExcepcionHttp 404 si la obra no existe, no tiene afiche o no lo puede ver
     */
    public function imagenParaMostrar(string $id, ?Usuario $usuario = null): string
    {
        $obra = $this->buscar($id);
        $loVe = $obra->estado !== EstadoObra::Pendiente
            || ($usuario !== null && (hash_equals($obra->duenoId, $usuario->id) || $usuario->alcanza(Rol::Moderador)));
        if ($obra->afiche === null || !$loVe) {
            throw ExcepcionHttp::noEncontrada();
        }

        return $obra->afiche;
    }

    /**
     * Próximas funciones en cartelera donde se proyecta la obra.
     *
     * @return list<FuncionEnCartelera>
     */
    public function proximasFunciones(string $obraId): array
    {
        return $this->cartelera->todas(new FiltrosDeCartelera(obraId: $obraId));
    }

    /**
     * @return array<string, string> mapa [genero_id => nombre]
     */
    public function mapaGeneros(): array
    {
        $mapa = [];
        foreach ($this->generos->todos() as $genero) {
            $mapa[$genero->id] = $genero->nombre;
        }

        return $mapa;
    }

    /**
     * @return list<Genero>
     */
    public function generos(): array
    {
        return $this->generos->todos();
    }

    public function generoPorNombre(string $nombre): ?Genero
    {
        return $this->generos->porNombre($nombre);
    }
}
