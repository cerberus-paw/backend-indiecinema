<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Pruebas\Repositorios;

use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Uuid;
use IndieCinema\Programacion\Modelo\Obra;
use IndieCinema\Programacion\Modelo\OrigenCopia;
use IndieCinema\Programacion\Modelo\TipoObra;
use IndieCinema\Programacion\Pruebas\ConBaseDePruebas;
use IndieCinema\Programacion\Repositorios\RepositorioDeObras;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Las obras con sus géneros, guardadas y leídas contra un MySQL de verdad.
 */
final class RepositorioDeObrasTest extends TestCase
{
    use ConBaseDePruebas;

    private const DUENA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const OTRO_DUENO = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

    private BaseDeDatos $base;
    private RepositorioDeObras $obras;
    private string $drama;
    private string $comedia;

    protected function setUp(): void
    {
        $this->base = self::baseConTablasNuevas(new Configuracion(['base' => self::baseDePruebas()]));
        $this->obras = new RepositorioDeObras($this->base);
        $this->drama = $this->insertarGenero('Drama');
        $this->comedia = $this->insertarGenero('Comedia');
    }

    private function insertarGenero(string $nombre): string
    {
        $id = Uuid::nuevo();
        $this->base->ejecutar('INSERT INTO genero (id, nombre, descripcion) VALUES (?, ?, ?)', [$id, $nombre, "Obras de {$nombre}."]);

        return $id;
    }

    /**
     * @param list<string>|null $generosIds por defecto, drama y comedia
     */
    private function obra(string $titulo = 'La ciénaga', string $duenoId = self::DUENA, ?array $generosIds = null, ?string $afiche = 'afiche.jpg'): Obra
    {
        $generosIds ??= [$this->drama, $this->comedia];
        // Ordenados como los lee el repositorio, para comparar la obra entera.
        sort($generosIds);

        return Obra::nueva(
            $duenoId,
            $titulo,
            'Una familia burguesa en decadencia pasa sus días de verano en su finca de Salta.',
            2001,
            'Lucrecia Martel',
            103,
            TipoObra::Pelicula,
            OrigenCopia::LicenciaAdquirida,
            $generosIds,
            $afiche,
        );
    }

    public function testGuardaYLeeUnaObraConSusGeneros(): void
    {
        $obra = $this->obra();
        $this->obras->agregar($obra);

        self::assertEquals($obra, $this->obras->buscar($obra->id));
    }

    public function testGuardaUnaObraSinAfiche(): void
    {
        $obra = $this->obra(afiche: null);
        $this->obras->agregar($obra);

        self::assertNull($this->obras->buscar($obra->id)?->afiche);
    }

    public function testUnaObraQueNoExisteEsNull(): void
    {
        self::assertNull($this->obras->buscar(Uuid::nuevo()));
    }

    public function testUnaObraDadaDeBajaNoSeVe(): void
    {
        $obra = $this->obra();
        $this->obras->agregar($obra);
        $this->base->ejecutar('UPDATE obra SET dada_de_baja_en = NOW() WHERE id = ?', [$obra->id]);

        self::assertNull($this->obras->buscar($obra->id));
        self::assertSame([], $this->obras->delDueno(self::DUENA));
    }

    public function testLasObrasDeUnDuenoPorTituloConSusGeneros(): void
    {
        $zama = $this->obra('Zama', generosIds: [$this->drama]);
        $cienaga = $this->obra('La ciénaga');
        $ajena = $this->obra('Relatos salvajes', self::OTRO_DUENO);
        $this->obras->agregar($zama);
        $this->obras->agregar($cienaga);
        $this->obras->agregar($ajena);

        self::assertEquals([$cienaga, $zama], $this->obras->delDueno(self::DUENA));
    }

    public function testConUnGeneroQueNoExisteNoGuardaNada(): void
    {
        $obra = $this->obra(generosIds: [$this->drama, Uuid::nuevo()]);

        try {
            $this->obras->agregar($obra);
            self::fail('Tenía que rechazar el género.');
        } catch (PDOException) {
            self::assertSame(0, $this->base->valor('SELECT COUNT(*) FROM obra'));
            self::assertSame(0, $this->base->valor('SELECT COUNT(*) FROM obra_genero'));
        }
    }
}
