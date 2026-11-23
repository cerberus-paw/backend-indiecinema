<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Pruebas\Servicios;

use DateTimeImmutable;
use IndieCinema\Recaudacion\Modelo\DatosDelReporte;
use IndieCinema\Recaudacion\Modelo\FuncionFinalizada;
use IndieCinema\Recaudacion\Modelo\FuncionParaProgramar;
use IndieCinema\Recaudacion\Modelo\ObraVotada;
use IndieCinema\Recaudacion\Modelo\Periodo;
use IndieCinema\Recaudacion\Modelo\VentasDeFuncion;
use IndieCinema\Recaudacion\Servicios\Precargas;
use PHPUnit\Framework\TestCase;

/**
 * El enlace del botón «Programarla» (EF-22.17): lo que la recomendación no dice lo completa con lo
 * que la sala hizo en el período. Hoy es el lunes 23/11/2026.
 */
final class PrecargasTest extends TestCase
{
    private const HOY = '2026-11-23';

    /** @var list<FuncionFinalizada> */
    private array $funciones = [];

    /** @var array<string, VentasDeFuncion> */
    private array $ventas = [];

    private function funcion(string $fecha, int $vendidas, string $precio = '3500.00', string $obra = 'zama', string $organizador = 'organizadora', int $cupo = 40): void
    {
        $id = 'funcion-' . (count($this->funciones) + 1);
        $this->funciones[] = new FuncionFinalizada($id, new DateTimeImmutable($fecha), new DateTimeImmutable($fecha), $cupo, $precio, 'sala', 'El Galpón', $organizador, [new ObraVotada($obra, $obra, 'duena', true, 0)], []);
        $this->ventas[$id] = new VentasDeFuncion($vendidas, $vendidas, '0.00', []);
    }

    /** @return array<string, mixed> los parámetros del enlace */
    private function precarga(FuncionParaProgramar $funcion, string $quienMira = 'organizadora'): ?array
    {
        $datos = new DatosDelReporte(Periodo::entre('2026-11-01', '2026-11-30'), $this->funciones, $this->ventas, []);
        $url = (new Precargas($datos, $quienMira, 20, new DateTimeImmutable(self::HOY)))->url($funcion);
        if ($url === null) {
            return null;
        }
        self::assertStringStartsWith('/organizador/funciones/nueva?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $parametros);

        return $parametros;
    }

    public function testElDiaFlojoConDescuentoVaElProximoMiercolesALaHoraDeSiempre(): void
    {
        $this->funcion('2026-11-04 21:00', 10);
        $this->funcion('2026-11-11 21:00', 12);
        $this->funcion('2026-11-07 18:00', 38);

        $precarga = $this->precarga(new FuncionParaProgramar('sala', ['cienaga'], 3, descuento: 20));

        self::assertSame(['programada', 'sala', ['cienaga'], '2026-11-25', '21:00', '2800', '40', 'reporte'], [
            $precarga['modo'], $precarga['sala_id'], $precarga['obras'], $precarga['fecha'], $precarga['hora'], $precarga['precio'], $precarga['cupo'], $precarga['desde'],
        ]);
    }

    public function testOtroHorarioBuscaLaHoraEnLaFranjaQueLlenaMas(): void
    {
        $this->funcion('2026-11-04 18:00', 10);
        $this->funcion('2026-11-07 22:30', 38);
        $this->funcion('2026-11-14 22:30', 36);

        // Los miércoles no tuvo funciones de noche: toma la de la noche de cualquier día.
        self::assertSame('22:30', $this->precarga(new FuncionParaProgramar('sala', [], 3, 'noche'))['hora']);
    }

    public function testSinDiaVaElQueMasLlenaYElPrecioDeEsaObra(): void
    {
        $this->funcion('2026-11-04 21:00', 10, '3000.00');
        $this->funcion('2026-11-06 21:00', 39, '4500.50', 'rojo');
        $this->funcion('2026-11-07 21:00', 30, '3000.00');

        $precarga = $this->precarga(new FuncionParaProgramar('sala', ['rojo']));

        // El viernes vendió 39 de 40: el próximo viernes, con lo que se pagó por «Rojo».
        self::assertSame(['2026-11-27', '4500.50'], [$precarga['fecha'], $precarga['precio']]);
    }

    public function testALaGorraVaConPrecioCeroYUnMiercolesDeHoyEsElDeLaSemanaQueViene(): void
    {
        $this->funcion('2026-11-02 21:00', 10);

        $precarga = $this->precarga(new FuncionParaProgramar('sala', [], 1, precio: 0));

        self::assertSame(['2026-11-30', '0'], [$precarga['fecha'], $precarga['precio']]);
    }

    public function testEnUnaSalaAjenaOSinFuncionesNoHayBoton(): void
    {
        $this->funcion('2026-11-04 21:00', 10, organizador: 'otra');

        self::assertNull($this->precarga(new FuncionParaProgramar('sala')));
        self::assertNull($this->precarga(new FuncionParaProgramar('sala'), 'administradora'));
        self::assertNull($this->precarga(new FuncionParaProgramar('otra-sala'), 'otra'));
        self::assertNotNull($this->precarga(new FuncionParaProgramar('sala'), 'otra'));
    }
}
