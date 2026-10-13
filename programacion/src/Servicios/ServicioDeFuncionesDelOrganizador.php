<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Servicios;

use DateTimeImmutable;
use IndieCinema\Nucleo\Seguridad\Usuario;
use IndieCinema\Programacion\Modelo\Funcion;
use IndieCinema\Programacion\Modelo\FuncionDelOrganizador;
use IndieCinema\Programacion\Modelo\Obra;
use IndieCinema\Programacion\Modelo\ReglaIncumplida;
use IndieCinema\Programacion\Modelo\Sala;
use IndieCinema\Programacion\Repositorios\RepositorioDeFunciones;
use IndieCinema\Programacion\Repositorios\RepositorioDeObras;
use IndieCinema\Programacion\Repositorios\RepositorioDeSalas;

/**
 * El panel de funciones del organizador: lo que ve de las funciones de sus salas y el alta
 * (E3-11.2), la edición y la cancelación.
 *
 * No es ServicioDeFunciones, que responde la API interna a los otros subsistemas: acá las reglas
 * son las del organizador y quién es el dueño.
 */
final class ServicioDeFuncionesDelOrganizador
{
    public function __construct(
        private readonly RepositorioDeFunciones $funciones,
        private readonly RepositorioDeSalas $salas,
        private readonly RepositorioDeObras $obras,
    ) {
    }

    /**
     * Las funciones de las salas del organizador. Que sean sólo las suyas no lo decide la ruta,
     * que únicamente controla el rol: lo decide la consulta, con su id.
     *
     * @return list<FuncionDelOrganizador>
     */
    public function listar(Usuario $organizador): array
    {
        return $this->funciones->delOrganizador($organizador->id);
    }

    /**
     * Las salas del organizador que están habilitadas para programar funciones.
     *
     * @return list<Sala>
     */
    public function salasHabilitadas(Usuario $organizador): array
    {
        return array_values(array_filter(
            $this->salas->delOrganizador($organizador->id),
            static fn (Sala $sala): bool => $sala->habilitada,
        ));
    }

    /**
     * El catálogo de obras disponibles para proyectar.
     *
     * @return list<Obra>
     */
    public function catalogoDeObras(): array
    {
        return $this->obras->todas();
    }

    /**
     * Da de alta y programa una nueva función en una sala propia habilitada (E3-11.2).
     *
     * @param list<string> $obrasIds
     *
     * @throws ReglaIncumplida
     */
    public function programar(
        Usuario $organizador,
        string $salaId,
        array $obrasIds,
        int $cupo,
        float $precioBase,
        DateTimeImmutable $fechaHora,
    ): Funcion {
        $sala = $this->salas->buscar($salaId);
        if ($sala === null || !$sala->esDe($organizador->id)) {
            throw new ReglaIncumplida('La sala no existe o no pertenece a tu cuenta.');
        }

        $funcion = $sala->programarFuncion($obrasIds, $cupo, $precioBase, $fechaHora);
        $this->funciones->agregar($funcion);

        return $funcion;
    }
}
