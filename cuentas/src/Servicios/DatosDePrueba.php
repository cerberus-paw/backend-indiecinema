<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Servicios;

use IndieCinema\Cuentas\Modelo\EstadoCuenta;
use IndieCinema\Cuentas\Modelo\Usuario;
use IndieCinema\Cuentas\Repositorios\RepositorioDeUsuarios;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Seguridad\Rol;
use IndieCinema\Nucleo\Uuid;
use RuntimeException;

/**
 * Los usuarios de prueba: un administrador, un organizador y dos espectadores, con las
 * contraseñas que anota el README. Sirven para la demo y para probar cada rol sin registrarse.
 *
 * El primer administrador sale de acá: la web no deja asignar ese rol a nadie, y no tiene que
 * haber una pantalla que lo haga sin un administrador que la use.
 *
 * Pasan por el mismo repositorio que el registro, así quedan como cualquier cuenta: Argon2id con
 * los parámetros de PHP, un UUID y el usuario junto con sus roles en una transacción.
 */
final class DatosDePrueba
{
    /**
     * Todos nacen espectadores, como en el registro; a los otros se les suma su rol. Los correos
     * son del dominio .test, que está reservado y nunca llega a una casilla de verdad.
     *
     * @var list<array{nombre: string, correo: string, contrasena: string, roles: list<Rol>}>
     */
    public const USUARIOS = [
        ['nombre' => 'Ana Administradora', 'correo' => 'administrador@indiecinema.test', 'contrasena' => 'administrador2026', 'roles' => [Rol::Espectador, Rol::Administrador]],
        ['nombre' => 'Omar Organizador', 'correo' => 'organizador@indiecinema.test', 'contrasena' => 'organizador2026', 'roles' => [Rol::Espectador, Rol::Organizador]],
        ['nombre' => 'Eva Espectadora', 'correo' => 'eva@indiecinema.test', 'contrasena' => 'espectador2026', 'roles' => [Rol::Espectador]],
        // Con tilde: el nombre viaja en la cabecera X-Usuario-Nombre, y así se ve que llega bien.
        ['nombre' => 'Elías Espectador', 'correo' => 'elias@indiecinema.test', 'contrasena' => 'espectador2026', 'roles' => [Rol::Espectador]],
    ];

    private function __construct(private readonly RepositorioDeUsuarios $usuarios)
    {
    }

    /**
     * Sólo en desarrollo: las contraseñas están en el repositorio, y en el VPS cualquiera podría
     * entrar como administrador con ellas.
     *
     * @throws RuntimeException fuera de desarrollo
     */
    public static function para(Configuracion $configuracion, RepositorioDeUsuarios $usuarios): self
    {
        if (!$configuracion->enDesarrollo()) {
            throw new RuntimeException('Los datos de prueba se cargan sólo con entorno = "desarrollo": sus contraseñas son públicas.');
        }

        return new self($usuarios);
    }

    /**
     * Agrega los que falten. Correrlo de nuevo no duplica nada ni toca a los que ya están: si
     * alguien cambió uno a mano para probar algo, queda como lo dejó.
     *
     * @return array<string, bool> cada correo, y si se agregó (true) o ya estaba (false)
     */
    public function cargar(): array
    {
        $resultado = [];
        foreach (self::USUARIOS as $datos) {
            if ($this->usuarios->buscarPorCorreo($datos['correo']) !== null) {
                $resultado[$datos['correo']] = false;
                continue;
            }
            $this->usuarios->agregar(new Usuario(
                Uuid::nuevo(),
                $datos['nombre'],
                $datos['correo'],
                password_hash($datos['contrasena'], PASSWORD_ARGON2ID),
                EstadoCuenta::Activa,
                $datos['roles'],
            ));
            $resultado[$datos['correo']] = true;
        }

        return $resultado;
    }
}
