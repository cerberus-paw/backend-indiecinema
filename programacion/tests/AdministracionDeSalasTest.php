<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Pruebas;

use IndieCinema\Nucleo\Aplicacion;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Log;
use IndieCinema\Nucleo\Uuid;
use IndieCinema\Programacion\Modelo\DatosDeSala;
use IndieCinema\Programacion\Modelo\Sala;
use IndieCinema\Programacion\Repositorios\RepositorioDeSalas;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Las salas del administrador de punta a punta: la aplicación entera con las rutas de
 * programación, las plantillas del paquete front y un MySQL de verdad. En desarrollo, para que
 * Twig falle si la plantilla usa una variable que el controlador no le pasa.
 */
final class AdministracionDeSalasTest extends TestCase
{
    use ConBaseDePruebas;

    private const SECRETO = 'secreto-de-nginx';
    private const CSRF = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';
    private const ADMINISTRADOR = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const ORGANIZADORA = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

    private Configuracion $configuracion;
    private BaseDeDatos $base;
    private RepositorioDeSalas $salas;

    /** @var resource */
    private $salidaDelLog;

    protected function setUp(): void
    {
        $this->configuracion = new Configuracion([
            'app' => ['subsistema' => 'programacion', 'entorno' => 'desarrollo'],
            'base' => self::baseDePruebas(),
            'nginx' => ['secreto' => self::SECRETO],
        ]);
        $this->base = self::baseConTablasNuevas($this->configuracion);
        $this->salas = new RepositorioDeSalas($this->base);
        $this->salidaDelLog = fopen('php://memory', 'w+');
    }

    private function pedir(string $metodo, string $url, ?string $rol = 'administrador', string $usuario = self::ADMINISTRADOR, array $cuerpo = []): Respuesta
    {
        $cabeceras = $rol === null ? [] : ['X-Nginx-Secreto' => self::SECRETO, 'X-Usuario-Id' => $usuario, 'X-Rol' => $rol];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $consulta);
        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion, new Log('programacion', $this->salidaDelLog));

        return $aplicacion->atender(new Peticion($metodo, (string) parse_url($url, PHP_URL_PATH), $consulta, $cuerpo, $cabeceras, ['csrf' => self::CSRF]));
    }

    private function enviar(string $url, ?string $rol = 'administrador', string $usuario = self::ADMINISTRADOR): Respuesta
    {
        return $this->pedir('POST', $url, $rol, $usuario, ['_csrf' => self::CSRF]);
    }

    /**
     * @param string $creada cuándo se cargó, para el orden de las que esperan
     */
    private function agregar(string $nombre, bool $habilitada = false, string $creada = '2026-10-01 10:00:00'): string
    {
        $id = Uuid::nuevo();
        $this->salas->agregar(new Sala($id, self::ORGANIZADORA, new DatosDeSala($nombre, 'Una sala de prueba.', 'Calle 1', 'Luján', 40, 2, 120, 15), null, $habilitada));
        $this->base->ejecutar('UPDATE sala SET creada_en = ? WHERE id = ?', [$creada, $id]);

        return $id;
    }

    private function habilitada(string $id): bool
    {
        return (bool) $this->base->valor('SELECT habilitada FROM sala WHERE id = ?', [$id]);
    }

    /**
     * Los nombres de las filas de la tabla, en orden.
     *
     * @return list<string>
     */
    private static function nombres(Respuesta $respuesta): array
    {
        preg_match_all('#class="tabla-admin-principal">([^<]*)</(?:a|span)>#', $respuesta->cuerpo(), $nombres);

        return $nombres[1];
    }

    private function log(): string
    {
        rewind($this->salidaDelLog);

        return (string) stream_get_contents($this->salidaDelLog);
    }

    public function testPrimeroLasQueEsperanYLaQueMasEsperoArriba(): void
    {
        $this->agregar('Zeta', habilitada: true);
        $this->agregar('Nueva', creada: '2026-10-10 10:00:00');
        $this->agregar('Alfa', habilitada: true);
        $this->agregar('Vieja', creada: '2026-09-01 10:00:00');

        $respuesta = $this->pedir('GET', '/administracion/salas');

        self::assertSame(200, $respuesta->estado(), $respuesta->cuerpo());
        self::assertSame(['Vieja', 'Nueva', 'Alfa', 'Zeta'], self::nombres($respuesta));
    }

    public function testCadaFilaTieneElBotonQueCorrespondeConElToken(): void
    {
        $esperando = $this->agregar('Esperando');
        $publicada = $this->agregar('Publicada', habilitada: true);

        $cuerpo = $this->pedir('GET', '/administracion/salas')->cuerpo();

        self::assertStringContainsString("action=\"/administracion/salas/{$esperando}/habilitar\"", $cuerpo);
        self::assertStringContainsString("action=\"/administracion/salas/{$publicada}/deshabilitar\"", $cuerpo);
        self::assertSame(2, substr_count($cuerpo, 'name="_csrf" value="' . self::CSRF . '"') - substr_count($cuerpo, 'action="/cuenta/salir"'));
        // Sólo la publicada lleva a su ficha: una sin habilitar sólo la ve su organizador.
        self::assertStringContainsString("href=\"/salas/{$publicada}\"", $cuerpo);
        self::assertStringNotContainsString("href=\"/salas/{$esperando}\"", $cuerpo);
    }

    public function testSinSalasLoDice(): void
    {
        self::assertStringContainsString('Todavía no hay salas cargadas.', $this->pedir('GET', '/administracion/salas')->cuerpo());
    }

    public function testHabilitarLaPublicaYQuedaEnElLog(): void
    {
        $id = $this->agregar('Cine Club Luján');
        self::assertStringNotContainsString('Cine Club Luján', $this->pedir('GET', '/salas', null)->cuerpo());

        $respuesta = $this->enviar("/administracion/salas/{$id}/habilitar");

        self::assertSame(303, $respuesta->estado());
        self::assertSame("/administracion/salas?habilitada={$id}", $respuesta->cabecera('Location'));
        self::assertTrue($this->habilitada($id));
        // El listado público la muestra.
        self::assertStringContainsString('Cine Club Luján', $this->pedir('GET', '/salas', null)->cuerpo());
        self::assertStringContainsString(self::ADMINISTRADOR . " habilitó la sala {$id}.", $this->log());
        // Y el aviso, al volver al listado.
        self::assertStringContainsString('Habilitaste «Cine Club Luján»', $this->pedir('GET', "/administracion/salas?habilitada={$id}")->cuerpo());
    }

    public function testDeshabilitarLaRetiraYQuedaEnElLog(): void
    {
        $id = $this->agregar('Cine Club Luján', habilitada: true);

        $respuesta = $this->enviar("/administracion/salas/{$id}/deshabilitar");

        self::assertSame(303, $respuesta->estado());
        self::assertFalse($this->habilitada($id));
        self::assertStringNotContainsString('Cine Club Luján', $this->pedir('GET', '/salas', null)->cuerpo());
        self::assertStringContainsString(self::ADMINISTRADOR . " deshabilitó la sala {$id}.", $this->log());
        self::assertStringContainsString('Deshabilitaste «Cine Club Luján»', $this->pedir('GET', "/administracion/salas?deshabilitada={$id}")->cuerpo());
    }

    public function testRepetirElCambioNoLoVuelveARegistrar(): void
    {
        $id = $this->agregar('Cine Club Luján', habilitada: true);

        self::assertSame(303, $this->enviar("/administracion/salas/{$id}/habilitar")->estado());

        self::assertTrue($this->habilitada($id));
        self::assertStringNotContainsString('habilitó', $this->log());
    }

    public function testHabilitarNoTocaLosDatosDeLaSala(): void
    {
        $id = $this->agregar('Cine Club Luján');

        $this->enviar("/administracion/salas/{$id}/habilitar");

        $fila = $this->base->fila('SELECT nombre, capacidad, organizador_id FROM sala WHERE id = ?', [$id]);
        self::assertSame(['nombre' => 'Cine Club Luján', 'capacidad' => 40, 'organizador_id' => self::ORGANIZADORA], $fila);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function salasQueNoExisten(): iterable
    {
        yield 'un id que no está' => [Uuid::nuevo()];
        yield 'no es un id' => ['abc'];
    }

    #[DataProvider('salasQueNoExisten')]
    public function testUnaSalaQueNoExisteDa404(string $id): void
    {
        self::assertSame(404, $this->enviar("/administracion/salas/{$id}/habilitar")->estado());
    }

    public function testUnaSalaDadaDeBajaNoSeListaNiSeHabilita(): void
    {
        $id = $this->agregar('Cerrada');
        $this->base->ejecutar('UPDATE sala SET dada_de_baja_en = NOW() WHERE id = ?', [$id]);

        self::assertSame([], self::nombres($this->pedir('GET', '/administracion/salas')));
        self::assertSame(404, $this->enviar("/administracion/salas/{$id}/habilitar")->estado());
    }

    public function testSinElTokenCsrfSeRechaza(): void
    {
        $id = $this->agregar('Cine Club Luján');

        $respuesta = $this->pedir('POST', "/administracion/salas/{$id}/habilitar", cuerpo: ['_csrf' => str_repeat('d', 64)]);

        self::assertSame(403, $respuesta->estado());
        self::assertFalse($this->habilitada($id));
    }

    /**
     * @return iterable<string, array{?string, int}>
     */
    public static function otrosRoles(): iterable
    {
        yield 'sin sesión' => [null, 401];
        yield 'espectador' => ['espectador', 403];
        yield 'organizador' => ['organizador', 403];
        yield 'moderador' => ['moderador', 403];
    }

    #[DataProvider('otrosRoles')]
    public function testSoloEntraElAdministrador(?string $rol, int $estado): void
    {
        $id = $this->agregar('Cine Club Luján');

        self::assertSame($estado, $this->pedir('GET', '/administracion/salas', $rol, self::ORGANIZADORA)->estado());
        // Ni siquiera la dueña de la sala la puede habilitar.
        self::assertSame($estado, $this->enviar("/administracion/salas/{$id}/habilitar", $rol, self::ORGANIZADORA)->estado());
        self::assertFalse($this->habilitada($id));
    }
}
