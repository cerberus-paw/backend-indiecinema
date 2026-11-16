<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Pruebas;

use DateTimeImmutable;
use IndieCinema\Nucleo\Aplicacion;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Log;
use IndieCinema\Nucleo\Pruebas\Dobles\PaginasParaValidar;
use IndieCinema\Recaudacion\Modelo\ParametroDeReparto;
use IndieCinema\Recaudacion\Repositorios\RepositorioDeParametrosDeReparto;
use PHPUnit\Framework\TestCase;

/**
 * «Reparto» del administrador de punta a punta (EF-08.1 / IC-559): la aplicación de recaudación con
 * sus plantillas Twig y un MySQL de verdad. No hace falta ningún otro subsistema: el parámetro del
 * reparto es de recaudación sola.
 *
 * Lo que tiene que quedar probado es el criterio de la tarea: el administrador cambia los
 * porcentajes y las funciones anteriores a la vigencia nueva se siguen repartiendo con los viejos.
 */
final class ParametrosDeRepartoTest extends TestCase
{
    use ConBaseDePruebas;

    private const SECRETO = 'secreto-de-nginx';
    private const CSRF = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';
    private const ADMINISTRADORA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const ORGANIZADOR = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
    private const RUTA = '/administracion/reparto';

    private BaseDeDatos $base;

    private RepositorioDeParametrosDeReparto $repositorio;

    /** @var resource */
    private $salidaDelLog;

    protected function setUp(): void
    {
        $this->salidaDelLog = fopen('php://memory', 'w+');
        $this->base = self::baseConTablasNuevas($this->configuracion());
        $this->repositorio = new RepositorioDeParametrosDeReparto($this->base);
    }

    private function configuracion(): Configuracion
    {
        return new Configuracion([
            'app' => ['subsistema' => 'recaudacion', 'prefijo' => '/recaudacion', 'entorno' => 'desarrollo'],
            'base' => self::baseDePruebas(),
            'nginx' => ['secreto' => self::SECRETO],
            'interno' => ['token' => 'token-de-recaudacion'],
        ]);
    }

    /**
     * @param array<string, mixed> $cuerpo
     */
    private function pedir(
        string $metodo,
        string $url,
        string $rol = 'administrador',
        string $usuario = self::ADMINISTRADORA,
        array $cuerpo = [],
        bool $conCsrf = true,
    ): Respuesta {
        $cabeceras = [
            'X-Nginx-Secreto' => self::SECRETO,
            'X-Usuario-Id' => $usuario,
            'X-Rol' => $rol,
            'X-Usuario-Nombre' => rawurlencode('Ada Administradora'),
        ];
        $campos = $metodo === 'POST' && $conCsrf ? ['_csrf' => self::CSRF, ...$cuerpo] : $cuerpo;
        parse_str((string) parse_url($url, PHP_URL_QUERY), $consulta);
        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion(), new Log('recaudacion', $this->salidaDelLog));

        return PaginasParaValidar::anotar($metodo, $url, $aplicacion->atender(new Peticion(
            $metodo,
            (string) parse_url($url, PHP_URL_PATH),
            $consulta,
            $campos,
            $cabeceras,
            ['csrf' => self::CSRF],
        )));
    }

    /**
     * @param array<string, string> $cambios lo que se manda distinto de un reparto válido
     *
     * @return array<string, string>
     */
    private static function formulario(array $cambios = []): array
    {
        return [
            'sala' => '60',
            'dueno' => '20',
            'plataforma' => '20',
            'vigente_desde' => (new DateTimeImmutable('+1 day'))->format('Y-m-d\TH:i'),
            ...$cambios,
        ];
    }

    /**
     * @return array{int, int, int} sala, dueño y plataforma en centésimas de punto
     */
    private function repartoDe(string $cuando): array
    {
        $parametro = $this->repositorio->vigenteEn(new DateTimeImmutable($cuando));
        self::assertNotNull($parametro, "No hay reparto vigente en «{$cuando}».");

        return [$parametro->sala, $parametro->dueno, $parametro->plataforma];
    }

    public function testElAdministradorVeElRepartoDeLaMigracionConSuHistorialYElEnlaceDelMenu(): void
    {
        $respuesta = $this->pedir('GET', self::RUTA);

        self::assertSame(200, $respuesta->estado(), $respuesta->cuerpo());
        $cuerpo = $respuesta->cuerpo();
        self::assertStringContainsString('70 %', $cuerpo);
        self::assertStringContainsString('15 %', $cuerpo);
        self::assertStringContainsString('rige ahora', $cuerpo);
        // La carga de la migración no es de ningún administrador.
        self::assertStringContainsString('carga inicial del sitio', $cuerpo);
        // La entrada de la barra del administrador, con el prefijo que repone el subsistema.
        self::assertStringContainsString('href="/recaudacion/administracion/reparto"', $cuerpo);
    }

    public function testElFormularioLlegaCompletadoConElRepartoQueRige(): void
    {
        $cuerpo = $this->pedir('GET', self::RUTA)->cuerpo();

        self::assertStringContainsString('name="sala"', $cuerpo);
        self::assertStringContainsString('value="70"', $cuerpo);
        self::assertStringContainsString('value="15"', $cuerpo);
    }

    public function testCargarOtroRepartoNoMueveElDeLasFuncionesAnteriores(): void
    {
        $respuesta = $this->pedir('POST', self::RUTA, cuerpo: self::formulario());

        self::assertSame(302, $respuesta->estado(), $respuesta->cuerpo());
        self::assertSame('/recaudacion/administracion/reparto?guardado=1', $respuesta->cabecera('Location'));
        // Lo que ya pasó, y lo de hoy, siguen con el 70/15/15 de la migración…
        self::assertSame([7000, 1500, 1500], $this->repartoDe('-1 month'));
        self::assertSame([7000, 1500, 1500], $this->repartoDe('now'));
        // …y desde la vigencia nueva rige el 60/20/20.
        self::assertSame([6000, 2000, 2000], $this->repartoDe('+2 days'));
    }

    public function testElRepartoCargadoQuedaConLaFirmaDeQuienLoCargo(): void
    {
        $this->pedir('POST', self::RUTA, cuerpo: self::formulario());

        $parametro = $this->repositorio->vigenteEn(new DateTimeImmutable('+2 days'));
        self::assertNotNull($parametro);
        self::assertSame(self::ADMINISTRADORA, $parametro->cargadoPor);
    }

    public function testElHistorialMuestraElQueTodaviaNoRige(): void
    {
        $this->pedir('POST', self::RUTA, cuerpo: self::formulario());

        $cuerpo = $this->pedir('GET', self::RUTA . '?guardado=1')->cuerpo();
        self::assertStringContainsString('todavía no rige', $cuerpo);
        self::assertStringContainsString('60 %', $cuerpo);
        self::assertStringContainsString('El reparto quedó cargado', $cuerpo);
    }

    public function testUnRepartoQueNoSumaCienVuelveConElErrorYNoSeGuarda(): void
    {
        $respuesta = $this->pedir('POST', self::RUTA, cuerpo: self::formulario(['plataforma' => '19']));

        self::assertSame(422, $respuesta->estado());
        self::assertStringContainsString('sumar 100', $respuesta->cuerpo());
        self::assertSame(1, $this->base->valor('SELECT COUNT(*) FROM parametro_reparto'));
    }

    public function testUnaVigenciaQueYaPasoNoSeGuarda(): void
    {
        $ayer = (new DateTimeImmutable('-1 day'))->format('Y-m-d\TH:i');

        $respuesta = $this->pedir('POST', self::RUTA, cuerpo: self::formulario(['vigente_desde' => $ayer]));

        self::assertSame(422, $respuesta->estado());
        self::assertStringContainsString('no puede empezar antes de ahora', $respuesta->cuerpo());
        self::assertSame(1, $this->base->valor('SELECT COUNT(*) FROM parametro_reparto'));
    }

    public function testDosRepartosConLaMismaVigenciaNoSeGuardan(): void
    {
        $formulario = self::formulario();
        $this->pedir('POST', self::RUTA, cuerpo: $formulario);

        $respuesta = $this->pedir('POST', self::RUTA, cuerpo: [...$formulario, 'sala' => '50', 'dueno' => '25', 'plataforma' => '25']);

        self::assertSame(422, $respuesta->estado());
        self::assertStringContainsString('Ya hay un reparto que empieza a regir en ese momento', $respuesta->cuerpo());
        self::assertSame(2, $this->base->valor('SELECT COUNT(*) FROM parametro_reparto'));
    }

    public function testUnPorcentajeQueNoEsUnoVuelveConElErrorDeSuCampo(): void
    {
        $respuesta = $this->pedir('POST', self::RUTA, cuerpo: self::formulario(['sala' => 'la mitad']));

        self::assertSame(422, $respuesta->estado());
        self::assertStringContainsString('El porcentaje de la sala va de 0 a 100', $respuesta->cuerpo());
        self::assertSame(1, $this->base->valor('SELECT COUNT(*) FROM parametro_reparto'));
    }

    public function testUnaVigenciaQueNoEsUnMomentoNoVuelveAlCampo(): void
    {
        $respuesta = $this->pedir('POST', self::RUTA, cuerpo: self::formulario(['vigente_desde' => 'mañana']));

        self::assertSame(422, $respuesta->estado());
        self::assertStringContainsString('no son válidas', $respuesta->cuerpo());
        // En un campo de fecha y hora, un valor así no vale en el HTML (lo valida el W3C en el CI).
        self::assertStringNotContainsString('value="mañana"', $respuesta->cuerpo());
    }

    public function testSinElTokenCsrfNoSeGuardaNada(): void
    {
        $respuesta = $this->pedir('POST', self::RUTA, cuerpo: self::formulario(), conCsrf: false);

        self::assertSame(403, $respuesta->estado());
        self::assertSame(1, $this->base->valor('SELECT COUNT(*) FROM parametro_reparto'));
    }

    public function testElOrganizadorNoLlegaAlReparto(): void
    {
        self::assertSame(403, $this->pedir('GET', self::RUTA, rol: 'organizador', usuario: self::ORGANIZADOR)->estado());
        self::assertSame(403, $this->pedir(
            'POST',
            self::RUTA,
            rol: 'organizador',
            usuario: self::ORGANIZADOR,
            cuerpo: self::formulario(),
        )->estado());
        self::assertSame(1, $this->base->valor('SELECT COUNT(*) FROM parametro_reparto'));
    }

    public function testElRepartoSeEscribeYSeLeeConLosMismosDecimales(): void
    {
        $this->pedir('POST', self::RUTA, cuerpo: self::formulario(['sala' => '33,33', 'dueno' => '33.33', 'plataforma' => '33,34']));

        self::assertSame([3333, 3333, 3334], $this->repartoDe('+2 days'));
        self::assertSame(ParametroDeReparto::TOTAL, array_sum($this->repartoDe('+2 days')));
    }
}
