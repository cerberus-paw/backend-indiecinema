<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Pruebas\Modelo;

use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Recaudacion\Modelo\Umbrales;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Los umbrales de [reportes] (EF-09.1): sin la sección valen los del ejemplo, y uno que no se
 * entiende no deja arrancar.
 */
final class UmbralesTest extends TestCase
{
    public function testSinLaSeccionValenLosDelEjemplo(): void
    {
        $umbrales = Umbrales::desdeConfiguracion(new Configuracion([]));
        $ejemplo = Umbrales::desdeConfiguracion(Configuracion::desdeArchivo(dirname(__DIR__, 2) . '/config/config.ejemplo.ini'));

        self::assertEquals($ejemplo, $umbrales);
        self::assertSame([50, 2, 90, 80, 4.0, 30], [
            $umbrales->diaFlojoOcupacionMaxima,
            $umbrales->diaFlojoFuncionesMinimas,
            $umbrales->repetirOcupacionMinima,
            $umbrales->repetirAsistenciaMinima,
            $umbrales->repetirPromedioMinimo,
            $umbrales->pedidoVotosMinimos,
        ]);
    }

    public function testLeeLosValoresDeLaSeccion(): void
    {
        $umbrales = Umbrales::desdeConfiguracion(new Configuracion(['reportes' => [
            'dia_flojo_ocupacion_maxima' => '40',
            'repetir_promedio_minimo' => '4.5',
            'pedido_votos_minimos' => 25,
        ]]));

        self::assertSame([40, 4.5, 25, 90], [$umbrales->diaFlojoOcupacionMaxima, $umbrales->repetirPromedioMinimo, $umbrales->pedidoVotosMinimos, $umbrales->repetirOcupacionMinima]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function valoresQueNoSirven(): iterable
    {
        yield 'un porcentaje que no es un número' => [['repetir_ocupacion_minima' => 'mucho']];
        yield 'un porcentaje con decimales' => [['repetir_ocupacion_minima' => '90.5']];
        yield 'más de 100 %' => [['dia_flojo_ocupacion_maxima' => 120]];
        yield 'ninguna función' => [['dia_flojo_funciones_minimas' => 0]];
        yield 'un promedio de 6' => [['repetir_promedio_minimo' => 6]];
        yield 'un promedio que no es un número' => [['repetir_promedio_minimo' => 'alto']];
    }

    /**
     * @param array<string, mixed> $reportes
     */
    #[DataProvider('valoresQueNoSirven')]
    public function testUnValorQueNoSirveNoDejaArrancar(array $reportes): void
    {
        $this->expectException(RuntimeException::class);

        Umbrales::desdeConfiguracion(new Configuracion(['reportes' => $reportes]));
    }
}
