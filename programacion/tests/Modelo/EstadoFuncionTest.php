<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Pruebas\Modelo;

use IndieCinema\Programacion\Modelo\EstadoFuncion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EstadoFuncionTest extends TestCase
{
    /** Las flechas del ciclo de vida; todo lo demás está prohibido. */
    private const PERMITIDAS = [
        ['en_votacion_titulos', 'en_acuerdo_fecha'],
        ['en_votacion_titulos', 'cancelada'],
        ['en_acuerdo_fecha', 'programada'],
        ['en_acuerdo_fecha', 'cancelada'],
        ['programada', 'finalizada'],
        ['programada', 'cancelada'],
    ];

    /**
     * Cada par de estados, para que un cambio nuevo en el enum no quede sin probar.
     *
     * @return iterable<string, array{EstadoFuncion, EstadoFuncion, bool}>
     */
    public static function pares(): iterable
    {
        foreach (EstadoFuncion::cases() as $desde) {
            foreach (EstadoFuncion::cases() as $hacia) {
                $permitida = in_array([$desde->value, $hacia->value], self::PERMITIDAS, true);
                yield "{$desde->value} a {$hacia->value}" => [$desde, $hacia, $permitida];
            }
        }
    }

    #[DataProvider('pares')]
    public function testSoloPermiteLosPasosDelCicloDeVida(EstadoFuncion $desde, EstadoFuncion $hacia, bool $permitida): void
    {
        self::assertSame($permitida, $desde->puedePasarA($hacia));
    }

    public function testCanceladaYFinalizadaSonFinales(): void
    {
        $finales = array_filter(EstadoFuncion::cases(), fn (EstadoFuncion $estado) => $estado->esFinal());

        self::assertSame([EstadoFuncion::Cancelada, EstadoFuncion::Finalizada], array_values($finales));
    }
}
