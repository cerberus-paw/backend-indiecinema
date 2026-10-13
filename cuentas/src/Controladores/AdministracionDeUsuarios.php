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
use IndieCinema\Nucleo\Seguridad\Usuario;
use LogicException;

/**
 * GET /cuenta/administracion/usuarios: «Usuarios y roles» del administrador, como el wireframe de
 * la E2, con las tarjetas de números, un buscador por nombre o correo y los filtros de rol y
 * estado, que viajan en la URL (un formulario GET), así funciona sin JavaScript y se puede
 * compartir. En cada fila, el rol es un desplegable: elegir otro y guardar es un POST. Sólo el
 * administrador llega: lo controla el rol de la ruta, y el núcleo ya controló el token CSRF de los
 * POST.
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

        $resumen = $this->servicio->resumen();

        return $this->vista('administracion_usuarios.html.twig', [
            'resumen' => ['usuarios' => $resumen->usuarios, 'nuevos' => $resumen->nuevos, 'organizadores' => $resumen->organizadores],
            'usuarios' => array_map(self::datos(...), $pagina->usuarios),
            'filtros' => $parametros->valores,
            'avisos' => $parametros->avisos,
            'mensaje_exito' => $this->mensaje($peticion),
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
     * El desplegable del rol de una fila. El campo es nuevo_rol y no rol, que es el filtro que
     * viaja en los campos ocultos para volver a la misma búsqueda. Si no cambió nada, vuelve sin
     * aviso.
     *
     * @throws ExcepcionHttp 404 si el usuario no existe; 400 si el rol no se puede elegir
     */
    public function cambiarRol(Peticion $peticion): Respuesta
    {
        $id = (string) $peticion->parametro('id');
        $valor = $peticion->campo('nuevo_rol');
        $rol = is_string($valor) ? Rol::tryFrom($valor) : null;
        if ($rol === null) {
            throw ExcepcionHttp::solicitudInvalida('Elegí espectador u organizador.');
        }
        $cambio = $this->servicio->cambiarRol($id, $rol, self::administrador($peticion));

        return self::volverAlListado($peticion, match (true) {
            !$cambio => [],
            $rol === Rol::Organizador => ['organizador_dado' => $id],
            default => ['organizador_quitado' => $id],
        });
    }

    private static function administrador(Peticion $peticion): Usuario
    {
        return $peticion->usuario() ?? throw new LogicException('La ruta pide el rol de administrador: sin sesión no llega acá.');
    }

    /**
     * 303 al listado con los filtros que tenía (llegan en campos ocultos del formulario) y el
     * aviso: recargar no vuelve a mandar el cambio, y el administrador no pierde la búsqueda. Va
     * a la primera página, porque con el cambio el usuario puede salir del filtro y la página en
     * la que estaba, dejar de existir. Es la URL completa, con el prefijo: no pasa por redirigir().
     *
     * @param array<string, string> $aviso
     */
    private static function volverAlListado(Peticion $peticion, array $aviso): Respuesta
    {
        $parametros = ParametrosDeUsuarios::desdeConsulta([
            'q' => $peticion->cuerpo()['q'] ?? null,
            'rol' => $peticion->cuerpo()['rol'] ?? null,
            'estado' => $peticion->cuerpo()['estado'] ?? null,
        ]);

        return Respuesta::redireccion($parametros->urlDePagina(1, $aviso));
    }

    /**
     * El aviso del cambio que se acaba de hacer, con el nombre del usuario, o null.
     */
    private function mensaje(Peticion $peticion): ?string
    {
        $avisos = [
            'organizador_dado' => 'Le diste el rol de organizador a «%s». Lo va a tener la próxima vez que ingrese.',
            'organizador_quitado' => 'Le quitaste el rol de organizador a «%s».',
        ];
        foreach ($avisos as $clave => $texto) {
            $id = $peticion->consulta($clave);
            $usuario = is_string($id) ? $this->servicio->buscarEnListado($id) : null;
            if ($usuario !== null) {
                return sprintf($texto, $usuario->nombre);
            }
        }

        return null;
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
            'rol' => $usuario->mayorRol()->value,
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
