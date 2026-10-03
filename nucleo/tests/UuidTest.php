<?php

declare(strict_types=1);

namespace IndieCinema\Nucleo\Pruebas;

use IndieCinema\Nucleo\Uuid;
use PHPUnit\Framework\TestCase;

final class UuidTest extends TestCase
{
    public function testGeneraUuidVersion4Distintos(): void
    {
        $ids = array_map(static fn (): string => Uuid::nuevo(), range(1, 100));

        foreach ($ids as $id) {
            self::assertTrue(Uuid::esValido($id), $id);
        }
        self::assertCount(100, array_unique($ids));
    }

    public function testRechazaLoQueNoEsUnUuidVersion4(): void
    {
        foreach (['', '1', 'u-1', '11111111-1111-1111-1111-111111111111', '11111111-1111-4111-8111-11111111111G', "11111111-1111-4111-8111-111111111111\n"] as $id) {
            self::assertFalse(Uuid::esValido($id), $id);
        }
    }
}
