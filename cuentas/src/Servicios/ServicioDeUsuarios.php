<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Servicios;

use IndieCinema\Cuentas\Modelo\FiltrosDeUsuarios;
use IndieCinema\Cuentas\Modelo\PaginaDeUsuarios;
use IndieCinema\Cuentas\Repositorios\RepositorioDeUsuarios;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use RuntimeException;

/**
 * Los usuarios para el administrador: el listado con la búsqueda y los filtros, por páginas del
 * tamaño que dice config.ini. Que quien pide sea administrador lo controla la ruta.
 */
final class ServicioDeUsuarios
{
    /** Si config.ini no dice otra cosa. */
    private const POR_PAGINA = 20;
    private const MAXIMO_POR_PAGINA = 100;

    private readonly int $porPagina;

    /**
     * @throws RuntimeException si [usuarios] por_pagina no es un número entre 1 y 100
     */
    public function __construct(
        private readonly RepositorioDeUsuarios $usuarios,
        Configuracion $configuracion,
    ) {
        $porPagina = $configuracion->obtener('usuarios.por_pagina', self::POR_PAGINA);
        if (!is_int($porPagina) || $porPagina < 1 || $porPagina > self::MAXIMO_POR_PAGINA) {
            throw new RuntimeException('[usuarios] por_pagina tiene que ser un número entre 1 y ' . self::MAXIMO_POR_PAGINA . '.');
        }
        $this->porPagina = $porPagina;
    }

    /**
     * @param positive-int $numero
     *
     * @throws ExcepcionHttp 404 si la página no existe
     */
    public function pagina(FiltrosDeUsuarios $filtros, int $numero): PaginaDeUsuarios
    {
        $pagina = $this->usuarios->pagina($filtros, $numero, $this->porPagina);
        if (!$pagina->existe()) {
            throw ExcepcionHttp::noEncontrada();
        }

        return $pagina;
    }
}
