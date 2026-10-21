<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Pruebas\Controladores;

use DateTimeImmutable;
use IndieCinema\Programacion\Controladores\CsvDeCartelera;
use IndieCinema\Programacion\Modelo\FuncionEnCartelera;
use IndieCinema\Programacion\Modelo\ObraEnCartelera;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * El CSV de la cartelera: que una planilla lo abra con las tildes bien, cada dato en su columna y
 * sin ejecutar nada de lo que escribieron los organizadores.
 */
final class CsvDeCarteleraTest extends TestCase
{
    private const BOM = "\u{FEFF}";
    private const ENCABEZADOS = "Fecha;Hora;Obras;Sala;Localidad;Precio\r\n";

    /**
     * @param list<string> $titulos
     */
    private static function funcion(array $titulos = ['Metrópolis'], string $sala = 'Cine Club Areco', string $localidad = 'San Antonio de Areco', float $precio = 3500.0): FuncionEnCartelera
    {
        return new FuncionEnCartelera(
            'f',
            new DateTimeImmutable('2026-10-24 20:30:00'),
            $precio,
            's',
            $sala,
            $localidad,
            array_map(static fn (string $titulo): ObraEnCartelera => new ObraEnCartelera('o', $titulo, null), $titulos),
        );
    }

    /**
     * Las filas del archivo como las leería una planilla, sin el BOM ni los encabezados.
     *
     * @return list<list<string|null>>
     */
    private static function filas(string $csv): array
    {
        $archivo = fopen('php://memory', 'w+');
        fwrite($archivo, substr($csv, strlen(self::BOM)));
        rewind($archivo);
        fgetcsv($archivo, null, ';', '"', '');
        $filas = [];
        while (($fila = fgetcsv($archivo, null, ';', '"', '')) !== false) {
            $filas[] = $fila;
        }
        fclose($archivo);

        return $filas;
    }

    public function testEmpiezaConElBomYLosEncabezados(): void
    {
        self::assertSame(self::BOM . self::ENCABEZADOS, CsvDeCartelera::armar([]));
    }

    public function testUnaFilaPorFuncionConTodasSusObras(): void
    {
        $csv = CsvDeCartelera::armar([
            self::funcion(['Viaje a la Luna', 'Metrópolis']),
            self::funcion(['El chico'], 'El Galpón', 'Luján', 0.0),
        ]);

        self::assertSame(
            self::BOM . self::ENCABEZADOS
            . "2026-10-24;20:30;\"Viaje a la Luna + Metrópolis\";\"Cine Club Areco\";\"San Antonio de Areco\";3500\r\n"
            . "2026-10-24;20:30;\"El chico\";\"El Galpón\";Luján;\"A la gorra\"\r\n",
            $csv,
        );
    }

    public function testFuncionEnVotacionSinFechaEnCsv(): void
    {
        $funcion = new FuncionEnCartelera(
            'f-votacion',
            null,
            2000.0,
            's',
            'Cine Club Areco',
            'San Antonio de Areco',
            [new ObraEnCartelera('o', 'Metrópolis', null)],
            \IndieCinema\Programacion\Modelo\EstadoFuncion::EnVotacionTitulos,
        );

        self::assertSame([['A definir', 'A definir', 'Metrópolis', 'Cine Club Areco', 'San Antonio de Areco', '2000']], self::filas(CsvDeCartelera::armar([$funcion])));
    }

    /**
     * Lo que rompería las columnas si no fuera entre comillas.
     *
     * @return iterable<string, array{string}>
     */
    public static function textosConSeparadores(): iterable
    {
        yield 'el separador' => ['Cine; Teatro'];
        yield 'comillas' => ['El "Cine" de Luján'];
        yield 'una coma' => ['Cine, Teatro'];
        yield 'un salto de línea' => ["Cine\nTeatro"];
        yield 'una barra antes de las comillas' => ['Cine \\"Teatro\\"'];
    }

    #[DataProvider('textosConSeparadores')]
    public function testUnTextoConSeparadoresQuedaEnSuColumna(string $sala): void
    {
        self::assertSame([['2026-10-24', '20:30', 'Metrópolis', $sala, 'San Antonio de Areco', '3500']], self::filas(CsvDeCartelera::armar([self::funcion(sala: $sala)])));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function formulas(): iterable
    {
        yield 'un igual' => ['=HYPERLINK("http://example.com","Entradas")'];
        yield 'un más' => ['+1+1'];
        yield 'un menos' => ['-1+1'];
        yield 'una arroba' => ['@SUM(A1:A2)'];
        yield 'un tab' => ["\t=1+1"];
        yield 'un retorno de carro' => ["\r=1+1"];
    }

    #[DataProvider('formulas')]
    public function testUnaFormulaSeEscribeComoTexto(string $titulo): void
    {
        [$fila] = self::filas(CsvDeCartelera::armar([self::funcion([$titulo], $titulo, $titulo)]));

        self::assertSame(["'{$titulo}", "'{$titulo}", "'{$titulo}"], array_slice($fila, 2, 3));
    }

    public function testUnTextoQueNoEmpiezaComoFormulaQuedaIgual(): void
    {
        self::assertSame('Cine 2 = 1 + 1', CsvDeCartelera::sinFormulas('Cine 2 = 1 + 1'));
        self::assertSame('', CsvDeCartelera::sinFormulas(''));
    }

    /**
     * @return iterable<string, array{float, string}>
     */
    public static function precios(): iterable
    {
        yield 'a la gorra' => [0.0, 'A la gorra'];
        yield 'entero' => [800.0, '800'];
        yield 'sin separador de miles' => [12500.0, '12500'];
        yield 'con centavos, con coma' => [1234.5, '1234,50'];
    }

    #[DataProvider('precios')]
    public function testElPrecio(float $precio, string $texto): void
    {
        self::assertSame($texto, CsvDeCartelera::precio($precio));
    }
}
