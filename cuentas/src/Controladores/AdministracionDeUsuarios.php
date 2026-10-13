<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Controladores;

use IndieCinema\Cuentas\Modelo\UsuarioEnListado;
use IndieCinema\Cuentas\Servicios\ServicioDeUsuarios;
use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Seguridad\Rol;

/**
 * GET /cuenta/administracion/usuarios: el listado de usuarios para el administrador, con un
 * buscador por nombre o correo que viaja en la URL (un formulario GET), así funciona sin
 * JavaScript y se puede compartir. Sólo el administrador llega: lo controla el rol de la ruta.
 */
final class AdministracionDeUsuarios extends Controlador
{
    public function __construct(private readonly ServicioDeUsuarios $servicio)
    {
    }

    /**
     * @throws ExcepcionHttp 404 si la página no es un número o no existe
     */
    public function listar(Peticion $peticion): Respuesta
    {
        $parametros = ParametrosDeUsuarios::desdeConsulta([
            'q' => $peticion->consulta('q'),
            'pagina' => $peticion->consulta('pagina'),
        ]);
        if ($parametros->pagina === null) {
            throw ExcepcionHttp::noEncontrada();
        }
        $pagina = $this->servicio->pagina($parametros->texto, $parametros->pagina);
        $total = $pagina->totalDePaginas();

        return $this->vista('administracion_usuarios.html.twig', [
            'usuarios' => array_map(self::datos(...), $pagina->usuarios),
            'busqueda' => $parametros->texto,
            'avisos' => $parametros->avisos,
            'paginado' => [
                'numero' => $pagina->numero,
                'total_paginas' => $total,
                'total_usuarios' => $pagina->total,
                'anterior' => $pagina->numero > 1 ? $parametros->urlDePagina($pagina->numero - 1) : null,
                'siguiente' => $pagina->numero < $total ? $parametros->urlDePagina($pagina->numero + 1) : null,
            ],
        ]);
    }

    /**
     * Lo que muestra cada fila, ya escrito como se lee: la plantilla recibe datos y no la entidad.
     *
     * @return array{id: string, nombre: string, correo: string, roles: list<string>, estado: string, alta: string}
     */
    private static function datos(UsuarioEnListado $usuario): array
    {
        return [
            'id' => $usuario->id,
            'nombre' => $usuario->nombre,
            'correo' => $usuario->correo,
            'roles' => array_map(static fn (Rol $rol): string => $rol->value, $usuario->roles),
            'estado' => $usuario->estado->value,
            'alta' => $usuario->creadoEn->format('d/m/Y'),
        ];
    }
}
