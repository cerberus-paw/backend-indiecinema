<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Servicios;

use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Uuid;
use IndieCinema\Programacion\Modelo\CupoDeFuncion;
use IndieCinema\Programacion\Modelo\DetalleDeFuncion;
use IndieCinema\Programacion\Modelo\EstadoFuncion;
use IndieCinema\Programacion\Modelo\FiltrosDeCartelera;
use IndieCinema\Programacion\Modelo\FuncionEnCartelera;
use IndieCinema\Programacion\Modelo\Genero;
use IndieCinema\Programacion\Modelo\ObraEnDetalle;
use IndieCinema\Programacion\Modelo\PaginaDeCartelera;
use IndieCinema\Programacion\Modelo\ReglaIncumplida;
use IndieCinema\Programacion\Repositorios\RepositorioDeCartelera;
use IndieCinema\Programacion\Repositorios\RepositorioDeGeneros;
use IndieCinema\Programacion\Repositorios\RepositorioDeVotos;
use RuntimeException;

/**
 * La cartelera pública, para cualquiera, con o sin sesión: una página de las funciones filtradas,
 * del tamaño que dice config.ini, o todas para descargarlas, y las opciones de los filtros.
 */
final class ServicioDeCartelera
{
    /** Si config.ini no dice otra cosa: tres filas de cuatro tarjetas. */
    private const POR_PAGINA = 12;
    private const MAXIMO_POR_PAGINA = 100;

    private readonly int $porPagina;

    /**
     * @throws RuntimeException si [cartelera] por_pagina no es un número entre 1 y 100
     */
    public function __construct(
        private readonly RepositorioDeCartelera $cartelera,
        private readonly RepositorioDeGeneros $generos,
        private readonly RepositorioDeVotos $votos,
        Configuracion $configuracion,
        private readonly ?AcuerdosDeFunciones $acuerdos = null,
    ) {
        $porPagina = $configuracion->obtener('cartelera.por_pagina', self::POR_PAGINA);
        if (!is_int($porPagina) || $porPagina < 1 || $porPagina > self::MAXIMO_POR_PAGINA) {
            throw new RuntimeException('[cartelera] por_pagina tiene que ser un número entre 1 y ' . self::MAXIMO_POR_PAGINA . '.');
        }
        $this->porPagina = $porPagina;
    }

    /**
     * La primera existe siempre, aunque no haya funciones: es la que dice que no hubo resultados.
     *
     * @param positive-int $numero
     *
     * @throws ExcepcionHttp 404 si la página no existe
     */
    public function pagina(FiltrosDeCartelera $filtros, int $numero): PaginaDeCartelera
    {
        $pagina = $this->cartelera->pagina($filtros, $numero, $this->porPagina);
        if (!$pagina->existe()) {
            throw ExcepcionHttp::noEncontrada();
        }

        return $pagina;
    }

    /**
     * Las primeras funciones de la cartelera, sin filtros, para la portada; el total dice cuántas
     * hay en toda la cartelera.
     *
     * @param positive-int $cantidad
     */
    public function proximas(int $cantidad): PaginaDeCartelera
    {
        return $this->cartelera->pagina(new FiltrosDeCartelera(), 1, $cantidad);
    }

    /**
     * Todas las funciones filtradas, sin paginar, para descargarlas.
     *
     * @return list<FuncionEnCartelera>
     */
    public function todas(FiltrosDeCartelera $filtros): array
    {
        return $this->cartelera->todas($filtros);
    }

    /**
     * @return list<string>
     */
    public function localidades(): array
    {
        return $this->cartelera->localidades();
    }

    /**
     * @return list<Genero>
     */
    public function generos(): array
    {
        return $this->generos->todos();
    }

    /**
     * El género por su nombre, como llega en la URL de la cartelera, o null si no hay ninguno.
     */
    public function generoPorNombre(string $nombre): ?Genero
    {
        return $this->generos->porNombre($nombre);
    }

    /**
     * El cupo de una función de la ficha pública, sin sus obras ni su sala: para los lugares
     * libres, que la ficha pide cada pocos segundos (E4-05.1).
     *
     * @throws ExcepcionHttp 404 en los mismos casos que detalle()
     */
    public function cupo(string $id): CupoDeFuncion
    {
        if (!Uuid::esValido($id)) {
            throw ExcepcionHttp::noEncontrada();
        }

        return $this->cartelera->cupo($id) ?? throw ExcepcionHttp::noEncontrada();
    }

    /**
     * La ficha pública de una función con sus obras y su sala, en cualquier estado: también las
     * que están en votación, las que ya pasaron y las canceladas, que la ficha muestra como tales.
     *
     * @throws ExcepcionHttp 404 si el id no es un UUID, la función no existe o su sala no está
     *                         publicada (sin habilitar o dada de baja)
     */
    public function detalle(string $id): DetalleDeFuncion
    {
        if (!Uuid::esValido($id)) {
            throw ExcepcionHttp::noEncontrada();
        }
        $detalle = $this->cartelera->detalle($id);
        if ($detalle === null) {
            throw ExcepcionHttp::noEncontrada();
        }

        return $detalle;
    }

    /**
     * La obra votada por el usuario en la función, o null si todavía no votó (E3-12.3).
     */
    public function votoDe(string $funcionId, string $usuarioId): ?string
    {
        return $this->votos->votoDe($funcionId, $usuarioId);
    }

    /**
     * Cantidad de votos por obra en una función.
     *
     * @return array<string, int>
     */
    public function conteoDeVotos(string $funcionId): array
    {
        return $this->votos->conteoPorObra($funcionId);
    }

    /**
     * Registra o cambia el voto de un espectador por una obra candidata en una función en votación de títulos (E3-12.3).
     *
     * @throws ReglaIncumplida si la función no está en votación de títulos o si la obra no es una candidata
     */
    public function votar(string $funcionId, string $usuarioId, string $obraId): void
    {
        $detalle = $this->detalle($funcionId);

        if ($detalle->estado !== EstadoFuncion::EnVotacionTitulos) {
            throw new ReglaIncumplida('La votación de títulos para esta función no está abierta.');
        }

        $candidatasIds = array_map(static fn (ObraEnDetalle $o): string => $o->id, $detalle->obras);
        if (!in_array($obraId, $candidatasIds, true)) {
            throw new ReglaIncumplida('La obra seleccionada no participa de la votación de esta función.');
        }

        $this->votos->votar($funcionId, $usuarioId, $obraId);
    }

    /**
     * El acuerdo de cada función de la lista que está en acuerdo de fecha, para que su tarjeta diga
     * en qué está (E3-13.F9): el estado del acuerdo vive en funciones, y sin preguntarle, una que
     * cerró sin quórum seguía ofreciendo «Votar fecha». Si funciones no responde, la tarjeta queda
     * como antes y la página sale igual.
     *
     * @param list<FuncionEnCartelera> $funciones
     *
     * @return array<string, array<string, mixed>|null> por id de función; null si no tiene acuerdo
     *                                                  o no se pudo preguntar
     */
    public function acuerdosDe(array $funciones): array
    {
        $acuerdos = [];
        foreach ($funciones as $funcion) {
            if ($funcion->estado !== EstadoFuncion::EnAcuerdoFecha || $this->acuerdos === null) {
                continue;
            }
            try {
                $acuerdos[$funcion->id] = $this->acuerdos->obtenerPorFuncion($funcion->id);
            } catch (ExcepcionHttp | RuntimeException) {
                $acuerdos[$funcion->id] = null;
            }
        }

        return $acuerdos;
    }

    /**
     * Consulta los datos del acuerdo de fecha de una función (E3-13.6).
     *
     * @return array<string, mixed>|null
     */
    public function acuerdoDeFecha(string $funcionId, ?string $usuarioId = null): ?array
    {
        return $this->acuerdos?->obtenerPorFuncion($funcionId, $usuarioId);
    }

    /**
     * Marca o quita el «Me sirve» de un espectador en una franja de una función en acuerdo de
     * fecha (E3-13.3 / IC-155). Se pueden marcar varias franjas (E3-13.F7).
     *
     * Se controla acá lo que funciones también rechaza: su 422 llegaría como un error del cliente
     * interno, y el espectador vería una página 500 en vez del motivo.
     *
     * @throws ReglaIncumplida si la función no está en acuerdo, el acuerdo no está abierto, la
     *                         franja no es suya o quien vota es el organizador de la función
     */
    public function votarFranjaAcuerdo(string $funcionId, string $usuarioId, string $franjaId, bool $marcar = true): void
    {
        $detalle = $this->detalle($funcionId);

        if ($detalle->estado !== EstadoFuncion::EnAcuerdoFecha) {
            throw new ReglaIncumplida('El acuerdo de fecha para esta función no está abierto.');
        }
        // El mínimo lo tiene que juntar el público: si el organizador marcara sus propias franjas,
        // se acercaría solo al quórum (E3-13.F7). Los demás organizadores y la administración sí
        // votan, porque los roles se acumulan y también son espectadores.
        if ($detalle->sala->organizadorId !== null && $detalle->sala->organizadorId === $usuarioId) {
            throw new ReglaIncumplida('Sos el organizador de esta función: las franjas las marca el público, y el mínimo lo tiene que juntar él.');
        }

        if ($this->acuerdos === null) {
            throw new \LogicException('No se configuró el cliente de acuerdos de funciones.');
        }

        $acuerdo = $this->acuerdoDeFecha($funcionId, $usuarioId);
        if ($acuerdo === null || ($acuerdo['estado'] ?? '') !== 'abierto') {
            throw new ReglaIncumplida('El acuerdo de fecha no está disponible o ya fue cerrado.');
        }
        if (($acuerdo['votacion_abierta'] ?? true) === false) {
            throw new ReglaIncumplida('Ya pasó la fecha límite para marcar «Me sirve».');
        }
        $franjas = is_array($acuerdo['franjas'] ?? null) ? $acuerdo['franjas'] : [];
        $ids = array_map(static fn (mixed $franja): string => is_array($franja) ? (string) ($franja['id'] ?? '') : '', $franjas);
        if (!in_array($franjaId, $ids, true)) {
            throw new ReglaIncumplida('La franja seleccionada no es de este acuerdo de fecha.');
        }

        if ($marcar) {
            $this->acuerdos->votar((string) $acuerdo['id'], $franjaId, $usuarioId);
        } else {
            $this->acuerdos->quitarVoto((string) $acuerdo['id'], $franjaId, $usuarioId);
        }
    }
}
