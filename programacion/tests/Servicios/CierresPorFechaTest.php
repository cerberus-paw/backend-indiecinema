<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Pruebas\Servicios;

use DateTimeImmutable;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Uuid;
use IndieCinema\Programacion\Modelo\DatosDeSala;
use IndieCinema\Programacion\Modelo\Funcion;
use IndieCinema\Programacion\Modelo\Obra;
use IndieCinema\Programacion\Modelo\OrigenCopia;
use IndieCinema\Programacion\Modelo\Sala;
use IndieCinema\Programacion\Modelo\TipoObra;
use IndieCinema\Programacion\Pruebas\ConBaseDePruebas;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Programacion\Repositorios\RepositorioDeFunciones;
use IndieCinema\Programacion\Repositorios\RepositorioDeObras;
use IndieCinema\Programacion\Repositorios\RepositorioDeSalas;
use IndieCinema\Programacion\Repositorios\RepositorioDeVotos;
use IndieCinema\Programacion\Servicios\CierresPorFecha;
use PHPUnit\Framework\TestCase;

/**
 * La tarea que cierra las votaciones de títulos vencidas (EF-03.1), con un MySQL de verdad.
 */
final class CierresPorFechaTest extends TestCase
{
    use ConBaseDePruebas;

    private const ORGANIZADORA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    private BaseDeDatos $base;
    private Sala $sala;

    /** @var array<string, string> título => id */
    private array $obras = [];

    protected function setUp(): void
    {
        $this->base = self::baseConTablasNuevas(new Configuracion([
            'app' => ['subsistema' => 'programacion', 'entorno' => 'desarrollo'],
            'base' => self::baseDePruebas(),
        ]));

        $this->sala = Sala::nueva(self::ORGANIZADORA, new DatosDeSala('Cine Club El Galpón', 'Un galpón.', 'Av. San Martín 450', 'Luján', 60, 1, 180, 15), 'sala.jpg');
        (new RepositorioDeSalas($this->base))->agregar($this->sala);
        $this->base->ejecutar('UPDATE sala SET habilitada = TRUE WHERE id = ?', [$this->sala->id]);

        $generoId = Uuid::nuevo();
        $this->base->ejecutar('INSERT INTO genero (id, nombre, descripcion) VALUES (?, ?, ?)', [$generoId, 'Drama', 'Obras de drama.']);
        foreach (['Zama', 'La ciénaga', 'Nosferatu'] as $titulo) {
            $obra = Obra::nueva(self::ORGANIZADORA, $titulo, 'Sinopsis.', 2001, 'Alguien', 100, TipoObra::Pelicula, OrigenCopia::LicenciaAdquirida, [$generoId]);
            (new RepositorioDeObras($this->base))->agregar($obra);
            $this->obras[$titulo] = $obra->id;
        }
    }

    private function cierres(): CierresPorFecha
    {
        return new CierresPorFecha(new RepositorioDeFunciones($this->base), new RepositorioDeVotos($this->base));
    }

    /**
     * Una votación con las tres candidatas que cierra en $cuando. Se crea con un cierre futuro y se
     * le cambia por SQL: el modelo no deja abrir una votación que ya cerró.
     */
    private function votacion(?string $cuando): string
    {
        $funcion = Funcion::nueva($this->sala->id, array_values($this->obras), 40, 3500.0, new DateTimeImmutable('+10 days'));
        (new RepositorioDeFunciones($this->base))->agregar($funcion);
        $this->base->ejecutar(
            'UPDATE funcion SET votacion_cierra_en = ? WHERE id = ?',
            [$cuando === null ? null : new DateTimeImmutable($cuando), $funcion->id],
        );

        return $funcion->id;
    }

    private function votar(string $funcionId, string $titulo, int $veces): void
    {
        for ($voto = 0; $voto < $veces; ++$voto) {
            (new RepositorioDeVotos($this->base))->votar($funcionId, Uuid::nuevo(), $this->obras[$titulo]);
        }
    }

    private function estadoDe(string $id): string
    {
        return (string) $this->base->valor('SELECT estado FROM funcion WHERE id = ?', [$id]);
    }

    /**
     * @return list<string> los títulos elegidos
     */
    private function elegidas(string $id): array
    {
        return array_column($this->base->filas(
            "SELECT o.titulo FROM funcion_obra fo JOIN obra o ON o.id = fo.obra_id
             WHERE fo.funcion_id = ? AND fo.estado = 'elegida' ORDER BY o.titulo",
            [$id],
        ), 'titulo');
    }

    public function testLaVotacionVencidaPasaAlAcuerdoConLaMasVotada(): void
    {
        $vencida = $this->votacion('-1 hour');
        $this->votar($vencida, 'Nosferatu', 3);
        $this->votar($vencida, 'Zama', 1);

        self::assertSame(1, $this->cierres()->cerrarVotaciones());

        self::assertSame('en_acuerdo_fecha', $this->estadoDe($vencida));
        self::assertSame(['Nosferatu'], $this->elegidas($vencida));
    }

    public function testNoTocaLasQueNoVencieronNiLasQueNoTienenCierre(): void
    {
        $abierta = $this->votacion('+1 hour');
        $sinCierre = $this->votacion(null);

        self::assertSame(0, $this->cierres()->cerrarVotaciones());

        self::assertSame('en_votacion_titulos', $this->estadoDe($abierta));
        self::assertSame('en_votacion_titulos', $this->estadoDe($sinCierre));
    }

    /**
     * La que el organizador cerró antes a mano, o canceló, ya no está en votación: la tarea no la
     * vuelve a cerrar ni le cambia la obra.
     */
    public function testNoVuelveACerrarLaQueYaCerroElOrganizadorNiLaCancelada(): void
    {
        $cerrada = $this->votacion('-1 hour');
        $this->votar($cerrada, 'Zama', 2);
        $funciones = new RepositorioDeFunciones($this->base);
        $funcion = $funciones->buscar($cerrada) ?? self::fail('No está la función.');
        $this->cierres()->cerrarVotacion($funcion);
        $this->votar($cerrada, 'Nosferatu', 5);

        $cancelada = $this->votacion('-1 hour');
        $this->base->ejecutar("UPDATE funcion SET estado = 'cancelada' WHERE id = ?", [$cancelada]);

        self::assertSame(0, $this->cierres()->cerrarVotaciones());
        self::assertSame(['Zama'], $this->elegidas($cerrada));
        self::assertSame('cancelada', $this->estadoDe($cancelada));
    }

    /**
     * Sin votos gana la primera candidata, como cuando la cierra el organizador (obraMasVotada()).
     */
    public function testSinVotosGanaLaPrimeraCandidata(): void
    {
        $vencida = $this->votacion('-1 hour');
        $candidatas = array_values($this->obras);
        $primera = array_search(min($candidatas), $this->obras, true);

        $this->cierres()->cerrarVotaciones();

        self::assertSame('en_acuerdo_fecha', $this->estadoDe($vencida));
        self::assertSame([$primera], $this->elegidas($vencida));
    }

    public function testCorrerlaDosVecesNoHaceNadaLaSegunda(): void
    {
        $this->votacion('-2 hours');
        $this->votacion('-1 hour');

        self::assertSame(2, $this->cierres()->cerrarVotaciones());
        self::assertSame(0, $this->cierres()->cerrarVotaciones());
    }
}
