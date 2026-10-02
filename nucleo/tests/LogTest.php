<?php

declare(strict_types=1);

namespace IndieCinema\Nucleo\Pruebas;

use IndieCinema\Nucleo\Log;
use PHPUnit\Framework\TestCase;
use Psr\Log\InvalidArgumentException;
use RuntimeException;

final class LogTest extends TestCase
{
    /** @var resource */
    private $salida;

    private Log $log;

    protected function setUp(): void
    {
        $this->salida = fopen('php://memory', 'w+');
        $this->log = new Log('programacion', $this->salida);
    }

    private function escrito(): string
    {
        rewind($this->salida);

        return (string) stream_get_contents($this->salida);
    }

    public function testEscribeUnaLineaConLaFechaElCanalYElNivel(): void
    {
        $this->log->info('arrancó');

        self::assertMatchesRegularExpression('/^\[\d{4}-\d\d-\d\dT[^\]]+\] programacion\.INFO arrancó\n$/', $this->escrito());
    }

    public function testReemplazaLoQueNombraElMensajeYNadaMas(): void
    {
        $this->log->warning('{metodo} {ruta} {sinValor}', ['metodo' => 'GET', 'ruta' => '/salas', 'correo' => 'ana@ejemplo.com']);

        self::assertStringContainsString('WARNING GET /salas {sinValor}', $this->escrito());
        self::assertStringNotContainsString('ana@ejemplo.com', $this->escrito());
    }

    public function testUnValorConSaltosDeLineaNoInventaOtraEntrada(): void
    {
        $this->log->info('ruta {ruta}', ['ruta' => "/x\n[2026-01-01] programacion.ERROR falso"]);

        self::assertSame(1, substr_count($this->escrito(), "\n"));
        self::assertStringContainsString('/x\n[2026-01-01]', $this->escrito());
    }

    public function testLaExcepcionVaConSuTrazaConSangria(): void
    {
        $this->log->error('falló', ['exception' => new RuntimeException('se cortó la base')]);

        $lineas = explode("\n", rtrim($this->escrito()));
        self::assertStringEndsWith('programacion.ERROR falló', $lineas[0]);
        self::assertStringStartsWith('    RuntimeException: se cortó la base', $lineas[1]);
        foreach (array_slice($lineas, 1) as $linea) {
            self::assertStringStartsWith('    ', $linea);
        }
    }

    public function testUnNivelQueNoEsDePsr3Falla(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->log->log('grave', 'algo');
    }
}
