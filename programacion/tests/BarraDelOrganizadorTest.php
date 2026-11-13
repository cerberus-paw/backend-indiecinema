<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Pruebas;

use IndieCinema\Nucleo\Aplicacion;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\Peticion;
use DateTimeImmutable;
use IndieCinema\Nucleo\Log;
use IndieCinema\Nucleo\Uuid;
use IndieCinema\Programacion\Modelo\DatosDeSala;
use IndieCinema\Programacion\Modelo\Funcion;
use IndieCinema\Programacion\Modelo\Obra;
use IndieCinema\Programacion\Modelo\OrigenCopia;
use IndieCinema\Programacion\Modelo\Sala;
use IndieCinema\Programacion\Modelo\TipoObra;
use IndieCinema\Programacion\Repositorios\RepositorioDeFunciones;
use IndieCinema\Programacion\Repositorios\RepositorioDeObras;
use IndieCinema\Programacion\Repositorios\RepositorioDeSalas;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * El panel del organizador en sus pantallas de programación, de punta a punta (E4-10.12): la
 * aplicación entera con las plantillas del paquete front y un MySQL de verdad. Cada pantalla tiene
 * la barra lateral en lugar del encabezado del sitio, con el logo a la portada, las secciones que
 * tienen pantalla, la actual marcada como la página actual y la salida. La de las reservas de una
 * función, que es de funciones, tiene la misma prueba allá (PanelDeReservasTest).
 *
 * Se comparan los enlaces completos de la barra, así uno de más (o uno que lleva a una pantalla
 * que no existe) rompe la prueba.
 */
final class BarraDelOrganizadorTest extends TestCase
{
    use ConBaseDePruebas;

    private const SECRETO = 'secreto-de-nginx';
    private const CSRF = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';
    private const ORGANIZADORA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    /** El logo, a la portada, y las secciones: Mis salas, Mis funciones, Programar función, Reportes y Publicar una sala. */
    private const ENLACES = ['/', '/organizador/funciones', '/organizador/funciones/nueva', '/organizador/salas', '/recaudacion/reportes', '/solicitar-sala'];

    private Configuracion $configuracion;
    private BaseDeDatos $base;
    private RepositorioDeFunciones $funciones;
    private Sala $sala;
    private string $obra;

    /** @var array<string, string> lo que va en las rutas de las pantallas: {sala}, {en_votacion}, {en_acuerdo} y {programada} */
    private array $ids = [];

    protected function setUp(): void
    {
        $this->configuracion = new Configuracion([
            'app' => ['subsistema' => 'programacion', 'entorno' => 'desarrollo'],
            'base' => self::baseDePruebas(),
            'nginx' => ['secreto' => self::SECRETO],
            // «Mis funciones» pregunta a funciones por el acuerdo de fecha: sin respuesta, la lista sale igual.
            'subsistemas' => ['funciones' => 'http://127.0.0.1:1'],
            'interno' => ['token' => 'token-de-programacion'],
        ]);
        $this->base = self::baseConTablasNuevas($this->configuracion);
        $this->funciones = new RepositorioDeFunciones($this->base);

        $salas = new RepositorioDeSalas($this->base);
        $sala = Sala::nueva(self::ORGANIZADORA, new DatosDeSala('El Galpón', 'Un galpón.', 'Av. San Martín 450', 'Luján', 60, 2, 120, 15), 'sala.jpg');
        $salas->agregar($sala);
        $this->base->ejecutar('UPDATE sala SET habilitada = TRUE WHERE id = ?', [$sala->id]);
        $this->sala = $salas->buscar($sala->id) ?? self::fail('No se guardó la sala.');

        $genero = Uuid::nuevo();
        $this->base->ejecutar('INSERT INTO genero (id, nombre, descripcion) VALUES (?, ?, ?)', [$genero, 'Drama', 'Obras de drama.']);
        $obra = Obra::nueva(self::ORGANIZADORA, 'Zama', 'Sinopsis.', 2017, 'Lucrecia Martel', 115, TipoObra::Pelicula, OrigenCopia::LicenciaAdquirida, [$genero]);
        (new RepositorioDeObras($this->base))->agregar($obra);
        $this->obra = $obra->id;

        $enAcuerdo = $this->enVotacion();
        $enAcuerdo->cerrarVotacionTitulos([$this->obra]);
        $this->funciones->guardar($enAcuerdo);
        $programada = $this->enVotacion();
        $programada->cerrarVotacionTitulos([$this->obra]);
        $this->funciones->guardar($programada);
        $programada->fijarFechaHora(new DateTimeImmutable('+30 days 20:00'), new DateTimeImmutable());
        $this->funciones->guardar($programada);

        $this->ids = [
            '{sala}' => $this->sala->id,
            '{en_votacion}' => $this->enVotacion()->id,
            '{en_acuerdo}' => $enAcuerdo->id,
            '{programada}' => $programada->id,
        ];
    }

    /**
     * Una función nueva en la sala, en votación de títulos.
     */
    private function enVotacion(): Funcion
    {
        $funcion = $this->sala->crearFuncion([$this->obra], 40, 3500.0);
        $this->funciones->agregar($funcion);

        return $funcion;
    }

    /**
     * La página entera, como la ve quien está en sesión con ese rol.
     */
    private function pagina(string $ruta, string $rol = 'organizador'): string
    {
        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion, new Log('programacion', fopen('php://memory', 'w')));
        $respuesta = $aplicacion->atender(new Peticion('GET', strtr($ruta, $this->ids), [], [], [
            'X-Nginx-Secreto' => self::SECRETO,
            'X-Usuario-Id' => self::ORGANIZADORA,
            'X-Rol' => $rol,
            'X-Usuario-Nombre' => rawurlencode('Ana Prueba'),
        ], ['csrf' => self::CSRF]));

        self::assertSame(200, $respuesta->estado(), $respuesta->cuerpo());

        return $respuesta->cuerpo();
    }

    /**
     * La barra lateral del panel, o '' si la página no la tiene.
     */
    private static function barra(string $pagina): string
    {
        preg_match('#<aside[^>]*aria-label="Panel del organizador"[^>]*>.*?</aside>#s', $pagina, $barra);

        return $barra[0] ?? '';
    }

    /**
     * Los enlaces de la barra, sin repetir y ordenados.
     *
     * @return list<string>
     */
    private static function enlaces(string $barra): array
    {
        preg_match_all('#href="([^"]*)"#', $barra, $enlaces);
        $unicos = array_values(array_unique($enlaces[1]));
        sort($unicos);

        return $unicos;
    }

    /**
     * Los enlaces marcados como la página actual.
     *
     * @return list<string>
     */
    private static function actuales(string $barra): array
    {
        preg_match_all('#<a href="([^"]*)"[^>]*aria-current="page"#', $barra, $actuales);

        return $actuales[1];
    }

    /**
     * @return iterable<string, array{string, string}> la ruta de la pantalla y la sección que marca
     */
    public static function pantallas(): iterable
    {
        yield 'Mis salas' => ['/organizador/salas', '/organizador/salas'];
        yield 'editar sala' => ['/organizador/salas/{sala}/editar', '/organizador/salas'];
        yield 'Mis funciones' => ['/organizador/funciones', '/organizador/funciones'];
        yield 'programar función' => ['/organizador/funciones/nueva', '/organizador/funciones/nueva'];
        // La edición usa el formulario de programar, pero es de una función que ya está en la lista.
        yield 'editar función' => ['/organizador/funciones/{programada}/editar', '/organizador/funciones'];
        yield 'proponer franjas' => ['/organizador/funciones/{en_acuerdo}/proponer-franjas', '/organizador/funciones'];
        yield 'cancelar función' => ['/organizador/funciones/{en_votacion}/cancelar', '/organizador/funciones'];
    }

    #[DataProvider('pantallas')]
    public function testCadaPantallaTieneLaBarraConSusEnlacesYLaSeccionActualMarcada(string $ruta, string $seccion): void
    {
        $pagina = $this->pagina($ruta);
        $barra = self::barra($pagina);

        self::assertSame(self::ENLACES, self::enlaces($barra));
        self::assertSame([$seccion], self::actuales($barra));
        // Quién está en sesión y la salida, que en el sitio están en el encabezado.
        self::assertStringContainsString('Ana Prueba', $barra);
        self::assertStringContainsString('action="/cuenta/salir"', $barra);
        // La barra reemplaza al encabezado del sitio.
        self::assertStringNotContainsString('aria-label="Navegación principal"', $pagina);
    }

    public function testElModeradorVeElMismoPanelPorqueAlcanzaElRolDeOrganizador(): void
    {
        $barra = self::barra($this->pagina('/organizador/funciones', 'moderador'));

        self::assertSame(self::ENLACES, self::enlaces($barra));
        self::assertSame(['/organizador/funciones'], self::actuales($barra));
    }
}
