<?php

declare(strict_types=1);

namespace IndieCinema\Nucleo\Pruebas\Http;

use IndieCinema\Nucleo\Http\Respuesta;
use PHPUnit\Framework\TestCase;

/**
 * Las tareas de después de responder (alTerminar). Que la respuesta salga antes de que terminen
 * depende de Apache y no se prueba acá: se probó a mano con la imagen de los subsistemas, con y
 * sin compresión.
 */
final class RespuestaTest extends TestCase
{
    public function testLasTareasCorrenRecienAlTerminarYEnOrden(): void
    {
        $hechas = [];
        $respuesta = Respuesta::json(['ok' => true])
            ->alTerminar(static function () use (&$hechas): void {
                $hechas[] = 'primera';
            })
            ->alTerminar(static function () use (&$hechas): void {
                $hechas[] = 'segunda';
            });

        self::assertSame([], $hechas);
        $respuesta->terminar();
        self::assertSame(['primera', 'segunda'], $hechas);
    }

    public function testAlTerminarDevuelveUnaCopia(): void
    {
        $hechas = 0;
        $sinTareas = Respuesta::json(['ok' => true]);
        $sinTareas->alTerminar(static function () use (&$hechas): void {
            ++$hechas;
        });

        $sinTareas->terminar();

        self::assertSame(0, $hechas);
    }
}
