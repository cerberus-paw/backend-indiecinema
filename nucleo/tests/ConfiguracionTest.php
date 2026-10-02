<?php

declare(strict_types=1);

namespace IndieCinema\Nucleo\Pruebas;

use IndieCinema\Nucleo\Configuracion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ConfiguracionTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function subsistemas(): iterable
    {
        yield 'cuentas' => ['cuentas'];
        yield 'programacion' => ['programacion'];
        yield 'funciones' => ['funciones'];
    }

    private static function ejemplo(string $subsistema): Configuracion
    {
        return Configuracion::desdeArchivo(dirname(__DIR__, 2) . "/{$subsistema}/config/config.ejemplo.ini");
    }

    /**
     * Copiando el ejemplo, el subsistema tiene todo lo que el núcleo exige para arrancar.
     */
    #[DataProvider('subsistemas')]
    public function testElEjemploTieneTodoLoQuePideElNucleo(string $subsistema): void
    {
        $configuracion = self::ejemplo($subsistema);

        self::assertSame($subsistema, $configuracion->requerir('app.subsistema'));
        self::assertTrue($configuracion->enDesarrollo());
        foreach (['base.host', 'base.esquema', 'base.usuario', 'base.clave', 'interno.token', 'nginx.secreto'] as $clave) {
            self::assertNotNull($configuracion->requerir($clave));
        }
        $configuracion->verificarQueNoQuedenValoresDeEjemplo();
    }

    #[DataProvider('subsistemas')]
    public function testElEjemploTieneLaUrlDeLosOtrosDos(string $subsistema): void
    {
        $configuracion = self::ejemplo($subsistema);

        foreach (array_diff(['cuentas', 'programacion', 'funciones'], [$subsistema]) as $otro) {
            self::assertSame("http://{$otro}:8080", $configuracion->requerir("subsistemas.{$otro}"));
        }
        self::assertNull($configuracion->obtener("subsistemas.{$subsistema}"));
    }

    public function testEnProduccionNoArrancaConValoresDeEjemploYNombraSoloLasClaves(): void
    {
        $configuracion = new Configuracion([
            'app' => ['entorno' => 'produccion'],
            'base' => ['usuario' => 'programacion', 'clave' => 'cambiar'],
            'nginx' => ['secreto' => 'cambiar'],
        ]);

        try {
            $configuracion->verificarQueNoQuedenValoresDeEjemplo();
            self::fail('Arrancó con valores de ejemplo.');
        } catch (RuntimeException $error) {
            self::assertSame('Quedan valores de ejemplo en config.ini: base.clave, nginx.secreto.', $error->getMessage());
        }
    }

    public function testEnProduccionConLosValoresCambiadosArranca(): void
    {
        $configuracion = new Configuracion([
            'app' => ['entorno' => 'produccion'],
            'base' => ['clave' => 'una-clave-de-verdad'],
        ]);

        $configuracion->verificarQueNoQuedenValoresDeEjemplo();
        $this->addToAssertionCount(1);
    }
}
