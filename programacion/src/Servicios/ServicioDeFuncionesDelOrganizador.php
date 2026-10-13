<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Servicios;

use IndieCinema\Nucleo\Seguridad\Usuario;
use IndieCinema\Programacion\Modelo\FuncionDelOrganizador;
use IndieCinema\Programacion\Repositorios\RepositorioDeFunciones;

/**
 * El panel de funciones del organizador: lo que ve de las funciones de sus salas y, más adelante,
 * el alta, la edición y la cancelación.
 *
 * No es ServicioDeFunciones, que responde la API interna a los otros subsistemas: acá las reglas
 * son las del organizador y quién es el dueño.
 */
final class ServicioDeFuncionesDelOrganizador
{
    public function __construct(private readonly RepositorioDeFunciones $funciones)
    {
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
}
