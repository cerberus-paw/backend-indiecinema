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
use IndieCinema\Programacion\Servicios\AcuerdosDeFunciones;
use IndieCinema\Programacion\Servicios\CierresPorFecha;
use IndieCinema\Nucleo\Http\ClienteInterno;
use IndieCinema\Nucleo\Log;
use PHPUnit\Framework\TestCase;

/**
 * La tarea de los cierres por fecha, con un MySQL de verdad: las votaciones de títulos vencidas
 * (EF-03.1) y los acuerdos de fecha vencidos (EF-03.2), con unos acuerdos de funciones en memoria
 * que anotan lo que se les pide.
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

    // ---- Acuerdos de fecha vencidos (EF-03.2) -----------------------------------------------

    /**
     * Los acuerdos de funciones, en memoria: el de cada función es el que la prueba puso en
     * $porFuncion, y lo que se pide queda en $pedidos.
     */
    private function acuerdos(): AcuerdosEnMemoria
    {
        return new AcuerdosEnMemoria(new ClienteInterno('programacion', 'token', ['funciones' => 'http://127.0.0.1:1'], new Log('programacion', fopen('php://memory', 'w'))));
    }

    private function cierresConAcuerdos(AcuerdosEnMemoria $acuerdos): CierresPorFecha
    {
        return new CierresPorFecha(new RepositorioDeFunciones($this->base), new RepositorioDeVotos($this->base), $acuerdos);
    }

    /**
     * Una función en acuerdo de fecha, con Zama elegida.
     */
    private function enAcuerdo(): string
    {
        $id = $this->votacion('+1 day');
        $funciones = new RepositorioDeFunciones($this->base);
        $funcion = $funciones->buscar($id) ?? self::fail('No está la función.');
        $funcion->cerrarVotacionTitulos([$this->obras['Zama']]);
        $funciones->guardar($funcion);

        return $id;
    }

    /**
     * Un acuerdo como lo responde funciones: mínimo 2, franjas mañana y pasado, la fecha límite
     * y los votos que se pidan.
     *
     * @return array<string, mixed>
     */
    private static function acuerdo(string $estado, string $limite, int $votos, ?string $cerradoEn = null): array
    {
        $manana = (new DateTimeImmutable('+1 day'))->format('Y-m-d');
        $pasado = (new DateTimeImmutable('+2 days'))->format('Y-m-d');

        return [
            'id' => 'acuerdo-1',
            'minimo_asistentes' => 2,
            'estado' => $estado,
            'fecha_limite' => (new DateTimeImmutable($limite))->format('Y-m-d H:i:s'),
            'cerrado_en' => $cerradoEn === null ? null : (new DateTimeImmutable($cerradoEn))->format('Y-m-d H:i:s'),
            'franja_ganadora' => $estado === 'confirmado' ? ['id' => 'franja-1', 'inicio' => "{$manana} 20:00:00"] : null,
            'franjas' => [
                ['id' => 'franja-1', 'inicio' => "{$manana} 20:00:00", 'votos' => $votos],
                ['id' => 'franja-2', 'inicio' => "{$pasado} 21:00:00", 'votos' => 0],
            ],
        ];
    }

    private function fechaDe(string $id): ?string
    {
        $fecha = $this->base->valor('SELECT fecha_hora FROM funcion WHERE id = ?', [$id]);

        return $fecha === null ? null : (string) $fecha;
    }

    public function testElAcuerdoVencidoConElMinimoProgramaLaFuncionEnLaFranjaGanadora(): void
    {
        $id = $this->enAcuerdo();
        $acuerdos = $this->acuerdos();
        $acuerdos->porFuncion[$id] = self::acuerdo('abierto', '-1 hour', 2);

        self::assertSame(['programadas' => 1, 'canceladas' => 0], $this->cierresConAcuerdos($acuerdos)->cerrarAcuerdos());

        self::assertSame('programada', $this->estadoDe($id));
        self::assertSame((new DateTimeImmutable('+1 day'))->format('Y-m-d') . ' 20:00:00', $this->fechaDe($id));
        self::assertSame([['confirmar', 'acuerdo-1']], $acuerdos->pedidos);
    }

    public function testElAcuerdoVencidoSinElMinimoCancelaLaFuncionYAvisa(): void
    {
        $id = $this->enAcuerdo();
        $acuerdos = $this->acuerdos();
        // Funciones ya lo cerró sin mínimo al leerlo, después de la fecha límite.
        $acuerdos->porFuncion[$id] = self::acuerdo('sin_minimo', '-1 hour', 1, cerradoEn: '-5 minutes');

        self::assertSame(['programadas' => 0, 'canceladas' => 1], $this->cierresConAcuerdos($acuerdos)->cerrarAcuerdos());

        self::assertSame('cancelada', $this->estadoDe($id));
        self::assertSame([['avisar-cancelacion', 'acuerdo-1', 'Zama']], $acuerdos->pedidos);
    }

    /**
     * El que el organizador cerró sin mínimo antes de la fecha límite es para proponer otras
     * franjas (E3-13.F8): no se cancela. Y el que todavía no venció, ni el que no tiene franjas,
     * no se tocan.
     */
    public function testNoTocaLosQueNoVencieronNiElCerradoAManoAntesDelLimite(): void
    {
        $abierto = $this->enAcuerdo();
        $cerradoAMano = $this->enAcuerdo();
        $sinFranjas = $this->enAcuerdo();
        $acuerdos = $this->acuerdos();
        $acuerdos->porFuncion[$abierto] = self::acuerdo('abierto', '+1 hour', 2);
        $acuerdos->porFuncion[$cerradoAMano] = self::acuerdo('sin_minimo', '-1 hour', 0, cerradoEn: '-2 hours');

        self::assertSame(['programadas' => 0, 'canceladas' => 0], $this->cierresConAcuerdos($acuerdos)->cerrarAcuerdos());

        self::assertSame(['en_acuerdo_fecha', 'en_acuerdo_fecha', 'en_acuerdo_fecha'], array_map($this->estadoDe(...), [$abierto, $cerradoAMano, $sinFranjas]));
        self::assertSame([], $acuerdos->pedidos);
    }

    /**
     * La pasada anterior confirmó en funciones y se cortó antes de guardar: ésta termina el paso,
     * sin volver a confirmar.
     */
    public function testTerminaUnAcuerdoQueQuedoConfirmadoSinProgramar(): void
    {
        $id = $this->enAcuerdo();
        $acuerdos = $this->acuerdos();
        $acuerdos->porFuncion[$id] = self::acuerdo('confirmado', '-1 hour', 2, cerradoEn: '-30 minutes');

        self::assertSame(['programadas' => 1, 'canceladas' => 0], $this->cierresConAcuerdos($acuerdos)->cerrarAcuerdos());

        self::assertSame('programada', $this->estadoDe($id));
        self::assertSame([], $acuerdos->pedidos);
    }

    /**
     * Si funciones no responde al avisar, la función sigue en acuerdo: la próxima pasada lo
     * vuelve a pedir, y funciones no repite los correos.
     */
    public function testSiFuncionesNoRespondeLaFuncionSigueEnAcuerdo(): void
    {
        $id = $this->enAcuerdo();
        $acuerdos = $this->acuerdos();
        $acuerdos->porFuncion[$id] = self::acuerdo('sin_minimo', '-1 hour', 1, cerradoEn: '-5 minutes');
        $acuerdos->caido = true;

        try {
            $this->cierresConAcuerdos($acuerdos)->cerrarAcuerdos();
            self::fail('Tenía que cortar la pasada.');
        } catch (\RuntimeException) {
        }

        self::assertSame('en_acuerdo_fecha', $this->estadoDe($id));
    }
}

/**
 * Los acuerdos de funciones en memoria, para CierresPorFechaTest.
 */
final class AcuerdosEnMemoria extends AcuerdosDeFunciones
{
    /** @var array<string, array<string, mixed>> */
    public array $porFuncion = [];

    /** @var list<list<string>> */
    public array $pedidos = [];

    public bool $caido = false;

    public function obtenerPorFuncion(string $funcionId, ?string $usuarioId = null): ?array
    {
        return $this->porFuncion[$funcionId] ?? null;
    }

    public function confirmar(string $acuerdoId): array
    {
        $this->pedidos[] = ['confirmar', $acuerdoId];

        return [];
    }

    public function cerrarSinMinimo(string $acuerdoId): array
    {
        $this->pedidos[] = ['cerrar-sin-minimo', $acuerdoId];

        return ['id' => $acuerdoId, 'estado' => 'sin_minimo'];
    }

    public function avisarCancelacion(string $acuerdoId, string $titulo): void
    {
        if ($this->caido) {
            throw new \RuntimeException('funciones no responde.');
        }
        $this->pedidos[] = ['avisar-cancelacion', $acuerdoId, $titulo];
    }
}
