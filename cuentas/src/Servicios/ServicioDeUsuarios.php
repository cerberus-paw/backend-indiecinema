<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Servicios;

use IndieCinema\Cuentas\Modelo\FiltrosDeUsuarios;
use IndieCinema\Cuentas\Modelo\PaginaDeUsuarios;
use IndieCinema\Cuentas\Modelo\ResumenDeUsuarios;
use IndieCinema\Cuentas\Modelo\UsuarioEnListado;
use IndieCinema\Cuentas\Repositorios\RepositorioDeUsuarios;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Seguridad\Rol;
use IndieCinema\Nucleo\Seguridad\Usuario;
use IndieCinema\Nucleo\Uuid;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Los usuarios para el administrador: el listado con la búsqueda y los filtros, por páginas del
 * tamaño que dice config.ini, los números de las tarjetas, y dar o quitar el rol de organizador.
 * Que quien pide sea administrador lo controla la ruta.
 *
 * Desde la web sólo se da y se quita el de organizador: el de moderador y el de administrador no
 * tienen pantalla, y el primer administrador sale de los datos de prueba.
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
        private readonly LoggerInterface $log,
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

    /**
     * Las opciones del filtro de alta: los años en que se creó alguna cuenta, del más nuevo al más
     * viejo.
     *
     * @return list<int>
     */
    public function aniosDeAlta(): array
    {
        return $this->usuarios->aniosDeAlta();
    }

    /**
     * Los números de las tarjetas, sobre todas las cuentas: no cambian con la búsqueda.
     */
    public function resumen(): ResumenDeUsuarios
    {
        return $this->usuarios->resumen();
    }

    /**
     * El usuario como lo ve el administrador, o null si no existe.
     */
    public function buscarEnListado(string $id): ?UsuarioEnListado
    {
        return Uuid::esValido($id) ? $this->usuarios->buscarEnListado($id) : null;
    }

    /**
     * El desplegable del rol de cada fila: deja al usuario de espectador o de organizador. Al
     * moderador y al administrador no se los cambia desde la web, y el desplegable ni los ofrece.
     *
     * @return bool si cambió algo: elegir el rol que ya tenía no cambia nada ni se registra
     *
     * @throws ExcepcionHttp 404 si el usuario no existe; 400 si el rol no es espectador ni
     *                       organizador, o si el usuario es moderador o administrador
     */
    public function cambiarRol(string $id, Rol $rol, Usuario $administrador): bool
    {
        $actual = $this->buscar($id)->mayorRol();
        $cambiables = [Rol::Espectador, Rol::Organizador];
        if (!in_array($rol, $cambiables, true) || !in_array($actual, $cambiables, true)) {
            throw ExcepcionHttp::solicitudInvalida('Desde la web sólo se pasa de espectador a organizador, o al revés.');
        }
        if ($rol === $actual) {
            return false;
        }
        if ($rol === Rol::Organizador) {
            $this->darOrganizador($id, $administrador);
        } else {
            $this->quitarOrganizador($id, $administrador);
        }

        return true;
    }

    /**
     * Le da el rol de organizador, además del que tiene. Si ya lo tiene, o uno mayor que lo
     * incluye, no cambia nada ni se registra (dos pestañas, o reenviar el POST).
     *
     * El repositorio cierra sus sesiones en la misma transacción, así el rol nuevo se ve en el
     * próximo ingreso, y el cambio queda en el log con quién lo hizo: es el registro de auditoría.
     *
     * @throws ExcepcionHttp 404 si el usuario no existe
     */
    public function darOrganizador(string $id, Usuario $administrador): UsuarioEnListado
    {
        $usuario = $this->buscar($id);
        if ($usuario->mayorRol()->alcanza(Rol::Organizador)) {
            return $usuario;
        }
        $this->usuarios->agregarRol($usuario->id, Rol::Organizador);
        $this->log->notice('{administrador} le dio el rol de organizador a {usuario}.', [
            'administrador' => $administrador->id,
            'usuario' => $usuario->id,
        ]);

        return $usuario;
    }

    /**
     * Le quita el rol de organizador; vuelve a ser espectador. Sus salas quedan como están, pero
     * sin el rol no entra a «Mis salas». Si no lo tenía, no cambia nada ni se registra.
     *
     * @throws ExcepcionHttp 404 si el usuario no existe
     */
    public function quitarOrganizador(string $id, Usuario $administrador): UsuarioEnListado
    {
        $usuario = $this->buscar($id);
        if (!in_array(Rol::Organizador, $usuario->roles, true)) {
            return $usuario;
        }
        $this->usuarios->quitarRol($usuario->id, Rol::Organizador);
        $this->log->notice('{administrador} le quitó el rol de organizador a {usuario}.', [
            'administrador' => $administrador->id,
            'usuario' => $usuario->id,
        ]);

        return $usuario;
    }

    /**
     * @throws ExcepcionHttp 404 si no existe, también si el id no tiene forma de id
     */
    private function buscar(string $id): UsuarioEnListado
    {
        return $this->buscarEnListado($id) ?? throw ExcepcionHttp::noEncontrada();
    }
}
