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
 * GET /cuenta/administracion/usuarios: «Usuarios y roles» del administrador, como el wireframe de
 * la E2, con un buscador por nombre o correo y los filtros de rol y estado, que viajan en la URL
 * (un formulario GET), así funciona sin JavaScript y se puede compartir. Sólo el administrador
 * llega: lo controla el rol de la ruta.
 */
final class AdministracionDeUsuarios extends Controlador
{
    /** Cuántas páginas se numeran a cada lado de la actual, además de la primera y la última. */
    private const PAGINAS_ALREDEDOR = 2;

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
            'rol' => $peticion->consulta('rol'),
            'estado' => $peticion->consulta('estado'),
            'pagina' => $peticion->consulta('pagina'),
        ]);
        if ($parametros->pagina === null) {
            throw ExcepcionHttp::noEncontrada();
        }
        $pagina = $this->servicio->pagina($parametros->filtros, $parametros->pagina);
        $total = $pagina->totalDePaginas();

        return $this->vista('administracion_usuarios.html.twig', [
            'usuarios' => array_map(self::datos(...), $pagina->usuarios),
            'filtros' => $parametros->valores,
            'avisos' => $parametros->avisos,
            'paginado' => [
                'numero' => $pagina->numero,
                'total_paginas' => $total,
                'total_usuarios' => $pagina->total,
                'anterior' => $pagina->numero > 1 ? $parametros->urlDePagina($pagina->numero - 1) : null,
                'siguiente' => $pagina->numero < $total ? $parametros->urlDePagina($pagina->numero + 1) : null,
                'paginas' => self::paginas($pagina->numero, $total, $parametros),
            ],
        ]);
    }

    /**
     * Lo que muestra cada fila, ya escrito como se lee: la plantilla recibe datos y no la entidad.
     * El rol es el mayor, como en el wireframe: los roles se acumulan, y el organizador también es
     * espectador.
     *
     * @return array{id: string, nombre: string, correo: string, rol: string, estado: string}
     */
    private static function datos(UsuarioEnListado $usuario): array
    {
        return [
            'id' => $usuario->id,
            'nombre' => $usuario->nombre,
            'correo' => $usuario->correo,
            // Todo usuario nace espectador: la lista nunca está vacía.
            'rol' => ($usuario->roles[array_key_last($usuario->roles)] ?? Rol::Espectador)->value,
            'estado' => $usuario->estado->value,
        ];
    }

    /**
     * Los números del paginado, como el wireframe: la primera, la última y las de alrededor de la
     * actual; null donde se saltean páginas («…»). Con 32 páginas, en la 1: 1 2 3 4 5 … 32.
     *
     * @return list<?array{numero: int, url: string, actual: bool}>
     */
    private static function paginas(int $actual, int $total, ParametrosDeUsuarios $parametros): array
    {
        // La ventana tiene siempre el mismo ancho, también cerca de los extremos.
        $ancho = 2 * self::PAGINAS_ALREDEDOR + 1;
        $desde = max(1, min($actual - self::PAGINAS_ALREDEDOR, $total - $ancho + 1));
        $hasta = min($total, $desde + $ancho - 1);
        $numeros = array_unique([1, ...range($desde, $hasta), $total]);

        $paginas = [];
        $anterior = 0;
        foreach ($numeros as $numero) {
            if ($numero > $anterior + 1) {
                $paginas[] = null;
            }
            $paginas[] = ['numero' => $numero, 'url' => $parametros->urlDePagina($numero), 'actual' => $numero === $actual];
            $anterior = $numero;
        }

        return $paginas;
    }
}
