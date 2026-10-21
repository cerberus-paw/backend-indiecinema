<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Pruebas\Repositorios;

use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Uuid;
use IndieCinema\Programacion\Modelo\DatosDeSala;
use IndieCinema\Programacion\Modelo\Obra;
use IndieCinema\Programacion\Modelo\OrigenCopia;
use IndieCinema\Programacion\Modelo\Sala;
use IndieCinema\Programacion\Modelo\TipoObra;
use IndieCinema\Programacion\Pruebas\ConBaseDePruebas;
use IndieCinema\Programacion\Repositorios\RepositorioDeFunciones;
use IndieCinema\Programacion\Repositorios\RepositorioDeObras;
use IndieCinema\Programacion\Repositorios\RepositorioDeSalas;
use IndieCinema\Programacion\Repositorios\RepositorioDeVotos;
use PHPUnit\Framework\TestCase;

/**
 * Los votos de las funciones en votación de títulos (E3-12.2), contra un MySQL de verdad.
 */
final class RepositorioDeVotosTest extends TestCase
{
    use ConBaseDePruebas;

    private const ORGANIZADOR = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    private BaseDeDatos $base;
    private RepositorioDeVotos $votos;
    private string $funcionId;
    private string $obra1;
    private string $obra2;

    protected function setUp(): void
    {
        $this->base = self::baseConTablasNuevas(new Configuracion(['base' => self::baseDePruebas()]));
        $this->votos = new RepositorioDeVotos($this->base);

        $generoId = Uuid::nuevo();
        $this->base->ejecutar('INSERT INTO genero (id, nombre, descripcion) VALUES (?, ?, ?)', [$generoId, 'Drama', 'Drama.']);

        $obras = new RepositorioDeObras($this->base);
        $o1 = Obra::nueva(self::ORGANIZADOR, 'Zama', 'Sinopsis.', 2017, 'Lucrecia Martel', 115, TipoObra::Pelicula, OrigenCopia::LicenciaAdquirida, [$generoId]);
        $o2 = Obra::nueva(self::ORGANIZADOR, 'La ciénaga', 'Sinopsis.', 2001, 'Lucrecia Martel', 103, TipoObra::Pelicula, OrigenCopia::LicenciaAdquirida, [$generoId]);
        $obras->agregar($o1);
        $obras->agregar($o2);
        $this->obra1 = $o1->id;
        $this->obra2 = $o2->id;

        $salas = new RepositorioDeSalas($this->base);
        $sala = Sala::nueva(self::ORGANIZADOR, new DatosDeSala('El Galpón', 'Un galpón.', 'Av. San Martín 450', 'Luján', 60, 2, 120, 15), 'sala.jpg');
        $salas->agregar($sala);
        $this->base->ejecutar('UPDATE sala SET habilitada = TRUE WHERE id = ?', [$sala->id]);
        $sala = $salas->buscar($sala->id) ?? self::fail('No se guardó la sala.');

        $funciones = new RepositorioDeFunciones($this->base);
        $funcion = $sala->crearFuncion([$this->obra1, $this->obra2], 40, 2500.0);
        $funciones->agregar($funcion);
        $this->funcionId = $funcion->id;
    }

    public function testRegistrarYConsultarVoto(): void
    {
        $usuarioId = Uuid::nuevo();
        self::assertNull($this->votos->votoDe($this->funcionId, $usuarioId));
        self::assertSame(0, $this->votos->totalDeVotos($this->funcionId));

        $this->votos->votar($this->funcionId, $usuarioId, $this->obra1);

        self::assertSame($this->obra1, $this->votos->votoDe($this->funcionId, $usuarioId));
        self::assertSame(1, $this->votos->totalDeVotos($this->funcionId));
        self::assertSame([$this->obra1 => 1], $this->votos->conteoPorObra($this->funcionId));
    }

    /**
     * Criterio: «Hay un voto por usuario y por funcion, que se puede cambiar mientras la votacion esta abierta».
     */
    public function testCambiarVotoActualizaObraSinDuplicar(): void
    {
        $usuarioId = Uuid::nuevo();
        $this->votos->votar($this->funcionId, $usuarioId, $this->obra1);
        self::assertSame($this->obra1, $this->votos->votoDe($this->funcionId, $usuarioId));
        self::assertSame(1, $this->votos->totalDeVotos($this->funcionId));

        // El usuario cambia su voto a la otra obra
        $this->votos->votar($this->funcionId, $usuarioId, $this->obra2);

        self::assertSame($this->obra2, $this->votos->votoDe($this->funcionId, $usuarioId));
        self::assertSame(1, $this->votos->totalDeVotos($this->funcionId));
        self::assertSame([$this->obra2 => 1], $this->votos->conteoPorObra($this->funcionId));
    }

    public function testConteoDeVotosPorObra(): void
    {
        $usuario1 = Uuid::nuevo();
        $usuario2 = Uuid::nuevo();
        $usuario3 = Uuid::nuevo();

        $this->votos->votar($this->funcionId, $usuario1, $this->obra1);
        $this->votos->votar($this->funcionId, $usuario2, $this->obra1);
        $this->votos->votar($this->funcionId, $usuario3, $this->obra2);

        self::assertSame(3, $this->votos->totalDeVotos($this->funcionId));
        $conteo = $this->votos->conteoPorObra($this->funcionId);
        self::assertSame(2, $conteo[$this->obra1]);
        self::assertSame(1, $conteo[$this->obra2]);
    }

    public function testObraMasVotadaEligeLaQueTieneMasVotos(): void
    {
        $u1 = Uuid::nuevo();
        $u2 = Uuid::nuevo();
        $u3 = Uuid::nuevo();

        $this->votos->votar($this->funcionId, $u1, $this->obra2);
        $this->votos->votar($this->funcionId, $u2, $this->obra2);
        $this->votos->votar($this->funcionId, $u3, $this->obra1);

        $ganadora = $this->votos->obraMasVotada($this->funcionId, [$this->obra1, $this->obra2]);
        self::assertSame($this->obra2, $ganadora);
    }

    public function testObraMasVotadaDesempataDeFormaDeterminista(): void
    {
        $u1 = Uuid::nuevo();
        $u2 = Uuid::nuevo();

        $this->votos->votar($this->funcionId, $u1, $this->obra1);
        $this->votos->votar($this->funcionId, $u2, $this->obra2);

        // Con empate, elige la primera entre las candidatas con mayor puntaje
        self::assertSame($this->obra1, $this->votos->obraMasVotada($this->funcionId, [$this->obra1, $this->obra2]));
        self::assertSame($this->obra2, $this->votos->obraMasVotada($this->funcionId, [$this->obra2, $this->obra1]));
    }

    public function testObraMasVotadaSinVotosEligePrimeraCandidata(): void
    {
        // Sin votos registrados para esta función
        self::assertSame(0, $this->votos->totalDeVotos($this->funcionId));
        self::assertSame($this->obra1, $this->votos->obraMasVotada($this->funcionId, [$this->obra1, $this->obra2]));
    }
}
