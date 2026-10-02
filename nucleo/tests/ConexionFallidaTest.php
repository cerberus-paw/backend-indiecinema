<?php

declare(strict_types=1);

namespace IndieCinema\Nucleo\Pruebas;

use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Si la base no responde, el error termina en el log con su traza: la clave no puede estar ahí.
 * No necesita un MySQL, porque lo que se prueba es justamente que no haya.
 */
final class ConexionFallidaTest extends TestCase
{
    public function testLaClaveNoQuedaEnLaTraza(): void
    {
        try {
            BaseDeDatos::conectar(new Configuracion(['base' => [
                'host' => '127.0.0.1',
                'puerto' => 1,
                'esquema' => 'programacion',
                'usuario' => 'programacion',
                'clave' => 'clave-que-no-va-al-log',
            ]]));
            self::fail('Conectó a un puerto donde no hay nada.');
        } catch (PDOException $error) {
            self::assertStringNotContainsString('clave-que-no-va-al-log', (string) $error);
        }
    }
}
