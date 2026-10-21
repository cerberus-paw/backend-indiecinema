<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Pruebas\Modelo;

use IndieCinema\Funciones\Modelo\EstadoReserva;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EstadoReservaTest extends TestCase
{
    /**
     * Todos los pares: los que no figuran como válidos se rechazan, también quedarse igual.
     *
     * @return iterable<string, array{EstadoReserva, EstadoReserva, bool}>
     */
    public static function pasos(): iterable
    {
        $validos = ['confirmada→cancelada', 'confirmada→asistio'];
        foreach (EstadoReserva::cases() as $desde) {
            foreach (EstadoReserva::cases() as $hacia) {
                $paso = "{$desde->value}→{$hacia->value}";
                yield $paso => [$desde, $hacia, in_array($paso, $validos, true)];
            }
        }
    }

    #[DataProvider('pasos')]
    public function testSoloSePasaDeConfirmadaACanceladaOAsistio(EstadoReserva $desde, EstadoReserva $hacia, bool $valido): void
    {
        self::assertSame($valido, $desde->puedePasarA($hacia));
    }

    public function testCanceladaYAsistioSonFinales(): void
    {
        self::assertFalse(EstadoReserva::Confirmada->esFinal());
        self::assertTrue(EstadoReserva::Cancelada->esFinal());
        self::assertTrue(EstadoReserva::Asistio->esFinal());
    }
}
