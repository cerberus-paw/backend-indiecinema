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
 * En producción, sólo con la confirmación explícita, van el administrador, el organizador y un
 * espectador, con las contraseñas de [datos_de_prueba] del config.ini: así el sitio publicado se
 * puede reiniciar sin crear usuarios a mano, y las salas de la demo quedan a nombre del
 * organizador por su id fijo.
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
     * «produccion» es la clave de su contraseña en [datos_de_prueba], o null si en producción no
     * va: con un espectador alcanza para probar el sitio publicado.
     *
     * @var list<array{id: string, nombre: string, correo: string, contrasena: string, roles: list<Rol>, produccion: ?string}>
     */
    public const USUARIOS = [
        ['id' => '00000000-0000-4000-8000-000000000001', 'nombre' => 'Ana Administradora', 'correo' => 'administrador@indiecinema.test', 'contrasena' => 'administrador2026', 'roles' => [Rol::Espectador, Rol::Administrador], 'produccion' => 'administrador'],
        ['id' => '00000000-0000-4000-8000-000000000002', 'nombre' => 'Omar Organizador', 'correo' => 'organizador@indiecinema.test', 'contrasena' => 'organizador2026', 'roles' => [Rol::Espectador, Rol::Organizador], 'produccion' => 'organizador'],
        ['id' => '00000000-0000-4000-8000-000000000003', 'nombre' => 'Eva Espectadora', 'correo' => 'eva@indiecinema.test', 'contrasena' => 'espectador2026', 'roles' => [Rol::Espectador], 'produccion' => 'espectador'],
        // Con tilde: el nombre viaja en la cabecera X-Usuario-Nombre, y así se ve que llega bien.
        ['id' => '00000000-0000-4000-8000-000000000004', 'nombre' => 'Elías Espectador', 'correo' => 'elias@indiecinema.test', 'contrasena' => 'espectador2026', 'roles' => [Rol::Espectador], 'produccion' => null],
    ];

    /**
     * @param list<array{id: string, nombre: string, correo: string, contrasena: string, roles: list<Rol>}> $aCargar
     */
    private function __construct(private readonly RepositorioDeUsuarios $usuarios, private readonly array $aCargar)
    {
    }

    /**
     * En desarrollo, los cuatro con las contraseñas del README. Fuera de desarrollo no carga nada
     * sin $confirmarProduccion, para que un comando copiado del README no llene el sitio real; con
     * la confirmación, los tres que van a producción con las contraseñas del config.ini, que no se
     * versiona. Nunca con las del README: el repositorio es público, y cualquiera entraría al
     * sitio como administrador.
     *
     * @throws RuntimeException fuera de desarrollo sin la confirmación, si falta una contraseña en
     *                          [datos_de_prueba] o si es la pública del README
     */
    public static function para(Configuracion $configuracion, RepositorioDeUsuarios $usuarios, bool $confirmarProduccion = false): self
    {
        if ($configuracion->enDesarrollo()) {
            return new self($usuarios, self::USUARIOS);
        }
        if (!$confirmarProduccion) {
            throw new RuntimeException('Fuera de desarrollo, los usuarios de prueba se cargan sólo con --confirmar-produccion.');
        }

        $aCargar = [];
        foreach (self::USUARIOS as $datos) {
            if ($datos['produccion'] === null) {
                continue;
            }
            $clave = "datos_de_prueba.{$datos['produccion']}";
            $contrasena = (string) $configuracion->requerir($clave);
            if ($contrasena === $datos['contrasena']) {
                throw new RuntimeException("«{$clave}» es la contraseña pública del README: en producción tiene que ser otra.");
            }
            $aCargar[] = ['contrasena' => $contrasena] + $datos;
        }

        return new self($usuarios, $aCargar);
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
        foreach ($this->aCargar as $datos) {
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
