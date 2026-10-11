<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Servicios;

use IndieCinema\Cuentas\Modelo\EstadoCuenta;
use IndieCinema\Cuentas\Modelo\Usuario;
use IndieCinema\Cuentas\Repositorios\RepositorioDeUsuarios;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Seguridad\Rol;
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
     * El id es fijo, y no uno al azar como en el registro: los datos de prueba de programación
     * ponen las salas y las obras a nombre del organizador, y como programación no ve el esquema
     * de cuentas, sólo pueden apuntarle si conocen su id de antemano.
     *
     * @var list<array{id: string, nombre: string, correo: string, contrasena: string, roles: list<Rol>}>
     */
    public const USUARIOS = [
        ['id' => '00000000-0000-4000-8000-000000000001', 'nombre' => 'Ana Administradora', 'correo' => 'administrador@indiecinema.test', 'contrasena' => 'administrador2026', 'roles' => [Rol::Espectador, Rol::Administrador]],
        ['id' => '00000000-0000-4000-8000-000000000002', 'nombre' => 'Omar Organizador', 'correo' => 'organizador@indiecinema.test', 'contrasena' => 'organizador2026', 'roles' => [Rol::Espectador, Rol::Organizador]],
        ['id' => '00000000-0000-4000-8000-000000000003', 'nombre' => 'Eva Espectadora', 'correo' => 'eva@indiecinema.test', 'contrasena' => 'espectador2026', 'roles' => [Rol::Espectador]],
        // Con tilde: el nombre viaja en la cabecera X-Usuario-Nombre, y así se ve que llega bien.
        ['id' => '00000000-0000-4000-8000-000000000004', 'nombre' => 'Elías Espectador', 'correo' => 'elias@indiecinema.test', 'contrasena' => 'espectador2026', 'roles' => [Rol::Espectador]],
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
                $datos['id'],
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
