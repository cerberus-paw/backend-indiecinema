<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Pruebas\Repositorios;

use IndieCinema\Programacion\Repositorios\Like;
use PHPUnit\Framework\TestCase;

/**
 * El patrón de LIKE: lo que escribe el visitante se busca tal cual, sin comodines propios.
 */
final class LikeTest extends TestCase
{
    public function testEscapaLosComodinesYElCaracterDeEscape(): void
    {
        self::assertSame('%galpón%', Like::contiene('galpón'));
        self::assertSame('%100!%%', Like::contiene('100%'));
        self::assertSame('%a!_b%', Like::contiene('a_b'));
        self::assertSame('%!!!%%', Like::contiene('!%'));
        // La barra no es el escape: queda como está.
        self::assertSame('%\\%', Like::contiene('\\'));
    }
}
