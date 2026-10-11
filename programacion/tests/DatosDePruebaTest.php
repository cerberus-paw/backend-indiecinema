<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Pruebas;

use DateTimeImmutable;
use IndieCinema\Nucleo\Uuid;
use IndieCinema\Programacion\Modelo\EstadoFuncion;
use IndieCinema\Programacion\Modelo\Funcion;
use IndieCinema\Programacion\Modelo\Obra;
use IndieCinema\Programacion\Modelo\OrigenCopia;
use IndieCinema\Programacion\Modelo\Sala;
use IndieCinema\Programacion\Modelo\TipoObra;
use IndieCinema\Programacion\Servicios\DatosDePrueba;
use PHPUnit\Framework\TestCase;

/**
 * El juego de datos de demostración: 4 salas en localidades distintas, 12 obras con afiche y 15
 * funciones futuras. Las reglas no se repiten acá: cada obra y cada función pasa por el modelo,
 * como si se cargara desde la web, y el modelo rechaza lo que no las cumple.
 */
final class DatosDePruebaTest extends TestCase
{
    /** El formato de ArchivosDeSalas: un nombre al azar, nunca el que mandó el usuario. */
    private const NOMBRE_DE_IMAGEN = '/^[0-9a-f]{32}\.(jpg|png|webp)$/D';

    public function testHayCuatroSalasHabilitadasDeDistintasLocalidades(): void
    {
        $salas = DatosDePrueba::SALAS;

        self::assertCount(4, $salas);
        self::assertCount(4, array_unique(array_column($salas, 'localidad')), 'Las salas tienen que ser de localidades distintas.');
        foreach ($salas as $sala) {
            self::assertTrue(Uuid::esValido($sala['id']), "El id de {$sala['nombre']} tiene que ser un UUID.");
            self::assertTrue($sala['habilitada'], "{$sala['nombre']} tiene que estar habilitada.");
            self::assertImagenVersionada(DatosDePrueba::rutaSala($sala['imagen']), $sala['imagen']);
        }
    }

    /**
     * El id es el de Omar Organizador en los datos de prueba de cuentas, que tiene su propia
     * prueba con el mismo valor: si cambia en un lado, tiene que cambiar en el otro, o el
     * organizador de prueba deja de ver sus salas.
     */
    public function testLasSalasYLasObrasSonDelOrganizadorDePruebaDeCuentas(): void
    {
        self::assertSame('00000000-0000-4000-8000-000000000002', DatosDePrueba::ORGANIZADOR_DEFECTO_ID);
        foreach (DatosDePrueba::SALAS as $sala) {
            self::assertSame(DatosDePrueba::ORGANIZADOR_DEFECTO_ID, $sala['organizador_id']);
        }
        foreach (DatosDePrueba::OBRAS as $obra) {
            self::assertSame(DatosDePrueba::ORGANIZADOR_DEFECTO_ID, $obra['dueno_id']);
        }
    }

    public function testHayDoceObrasConAficheQueCumplenLasReglasDeLaObra(): void
    {
        $obras = DatosDePrueba::OBRAS;
        $generos = array_column(DatosDePrueba::GENEROS, 'id', 'nombre');

        self::assertCount(12, $obras);
        foreach ($obras as $obra) {
            self::assertTrue(Uuid::esValido($obra['id']), "El id de {$obra['titulo']} tiene que ser un UUID.");
            foreach ($obra['generos'] as $genero) {
                self::assertArrayHasKey($genero, $generos, "El género {$genero} tiene que estar entre los de prueba.");
            }
            // Lanza ReglaIncumplida si la obra no se pudiera cargar desde la web.
            Obra::nueva(
                $obra['dueno_id'],
                $obra['titulo'],
                $obra['sinopsis'],
                $obra['anio'],
                $obra['director'],
                $obra['duracion'],
                TipoObra::from($obra['tipo']),
                OrigenCopia::from($obra['origen_copia']),
                array_map(static fn (string $genero): string => $generos[$genero], $obra['generos']),
                $obra['afiche'],
            );
            self::assertImagenVersionada(DatosDePrueba::rutaAfiche($obra['afiche']), $obra['afiche']);
        }
    }

    /**
     * Cada función se arma como en la web: la crea su sala, se cierra la votación con sus obras y
     * se le fija la fecha. El modelo controla que la sala esté habilitada, que el cupo entre, que
     * el precio no sea negativo, que no se repitan obras y que la fecha sea futura.
     */
    public function testHayQuinceFuncionesFuturasQueCumplenLasReglasDeLaFuncion(): void
    {
        $salas = [];
        foreach (DatosDePrueba::salas() as $sala) {
            $salas[$sala->id] = $sala;
        }
        $obras = array_column(DatosDePrueba::OBRAS, null, 'id');
        $funciones = DatosDePrueba::funciones();
        $ahora = new DateTimeImmutable();

        self::assertCount(15, $funciones);
        foreach ($funciones as $funcion) {
            self::assertTrue(Uuid::esValido($funcion->id), 'El id de la función tiene que ser un UUID.');
            self::assertArrayHasKey($funcion->salaId, $salas);
            foreach ($funcion->obrasIds as $obraId) {
                self::assertArrayHasKey($obraId, $obras, 'Las obras de la función tienen que estar entre las de prueba.');
            }

            $comoEnLaWeb = $salas[$funcion->salaId]->crearFuncion($funcion->obrasIds, $funcion->cupo, $funcion->precioBase);
            $comoEnLaWeb->cerrarVotacionTitulos($funcion->obrasIds);
            $comoEnLaWeb->fijarFechaHora($funcion->fechaHora ?? self::fail('La función no tiene fecha.'), $ahora);

            self::assertSame(EstadoFuncion::Programada, $funcion->estado);
            self::assertSame($comoEnLaWeb->estado, $funcion->estado);
        }
    }

    /**
     * Lo que el modelo no controla pero en la demo se vería mal: más obras de las que la sala
     * proyecta por función, o que no entren en la duración de la función.
     */
    public function testLasObrasDeCadaFuncionEntranEnLaSala(): void
    {
        $salas = array_column(DatosDePrueba::SALAS, null, 'id');
        $obras = array_column(DatosDePrueba::OBRAS, null, 'id');

        foreach (DatosDePrueba::FUNCIONES as $funcion) {
            $sala = $salas[$funcion['sala_id']];
            $titulos = implode(' + ', array_map(static fn (array $obra): string => $obras[$obra['obra_id']]['titulo'], $funcion['obras']));
            $duracion = array_sum(array_map(static fn (array $obra): int => $obras[$obra['obra_id']]['duracion'], $funcion['obras']));

            self::assertLessThanOrEqual($sala['peliculas_por_funcion'], count($funcion['obras']), "{$titulos} son demasiadas obras para {$sala['nombre']}.");
            self::assertLessThanOrEqual($sala['duracion_funcion'], $duracion, "{$titulos} ({$duracion} min) no entra en una función de {$sala['nombre']}.");
        }
    }

    public function testLasFechasSeCuentanDesdeElDiaDeLaCarga(): void
    {
        $hoy = new DateTimeImmutable('2030-01-01');

        foreach (DatosDePrueba::funciones($hoy) as $i => $funcion) {
            $datos = DatosDePrueba::FUNCIONES[$i];
            self::assertEquals(new DateTimeImmutable("2030-01-01 {$datos['hora']}")->modify("+{$datos['dias']} days"), $funcion->fechaHora);
        }
    }

    public function testLosModelosSeArmanConLosDatos(): void
    {
        self::assertContainsOnlyInstancesOf(Sala::class, DatosDePrueba::salas());
        self::assertContainsOnlyInstancesOf(Obra::class, DatosDePrueba::obras());
        self::assertContainsOnlyInstancesOf(Funcion::class, DatosDePrueba::funciones());
    }

    private static function assertImagenVersionada(string $ruta, string $nombre): void
    {
        self::assertMatchesRegularExpression(self::NOMBRE_DE_IMAGEN, $nombre);
        self::assertFileExists($ruta);
        self::assertNotFalse(getimagesize($ruta), "{$nombre} no es una imagen.");
    }
}
