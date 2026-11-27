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
use IndieCinema\Nucleo\Uuid;
use IndieCinema\Recaudacion\Modelo\Mes;
use IndieCinema\Recaudacion\Repositorios\RepositorioDeLiquidaciones;
use IndieCinema\Recaudacion\Repositorios\RepositorioDeRepartos;
use IndieCinema\Recaudacion\Servicios\ServicioDeLiquidaciones;
use PHPUnit\Framework\TestCase;

/**
 * Las liquidaciones por mes de punta a punta (EF-08.3 / IC-561): la aplicación de recaudación con
 * sus plantillas Twig, un MySQL de verdad y un cuentas de mentira para los nombres.
 *
 * Los repartos se cargan a mano, como los habría dejado la tarea de EF-08.2, y con ellos se arman
 * las liquidaciones: lo que se prueba acá es que cada uno vea lo suyo y que el administrador pueda
 * marcar la transferencia.
 */
final class LiquidacionesTest extends TestCase
{
    use ConBaseDePruebas;
    use ConDoblesDeSubsistemas;

    private const SECRETO = 'secreto-de-nginx';
    private const CSRF = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';
    private const ORGANIZADORA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const DUENA = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';
    private const ADMINISTRADORA = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee';
    private const MIAS = '/liquidaciones';
    private const DEL_ADMINISTRADOR = '/administracion/liquidaciones';

    private static string $cuentas;

    private BaseDeDatos $base;

    private RepositorioDeLiquidaciones $liquidaciones;

    /** @var resource */
    private $salidaDelLog;

    public static function setUpBeforeClass(): void
    {
        self::$cuentas = self::levantar('cuentas.php');
    }

    public static function tearDownAfterClass(): void
    {
        self::apagarDobles();
    }

    protected function setUp(): void
    {
        $this->salidaDelLog = fopen('php://memory', 'w+');
        $this->base = self::baseConTablasNuevas($this->configuracion());
        $this->liquidaciones = new RepositorioDeLiquidaciones($this->base);
        $this->cargarRepartos();
        $this->armar();
    }

    private function configuracion(): Configuracion
    {
        return new Configuracion([
            'app' => ['subsistema' => 'recaudacion', 'prefijo' => '/recaudacion', 'entorno' => 'desarrollo'],
            'base' => self::baseDePruebas(),
            'nginx' => ['secreto' => self::SECRETO],
            'subsistemas' => ['cuentas' => self::$cuentas],
            'interno' => ['token' => 'token-de-recaudacion'],
        ]);
    }

    /**
     * Tres funciones repartidas, como las habría dejado la tarea: dos de noviembre y una de
     * octubre. La organizadora cobra por su sala y, en la de octubre, también como dueña de la
     * obra; la otra dueña sólo por sus obras.
     */
    private function cargarRepartos(): void
    {
        $this->reparto('11111111-1111-4111-8111-111111111111', '10000.00', '2026-11-20 23:20:00', '2026-11-20 21:00:00', 'El Galpón', 'Zama', [
            ['sala', self::ORGANIZADORA, '7000.00'],
            ['dueno', self::DUENA, '1500.00'],
            ['plataforma', null, '1500.00'],
        ]);
        $this->reparto('22222222-2222-4222-8222-222222222222', '100.00', '2026-11-21 23:20:00', '2026-11-21 21:00:00', 'El Galpón', 'Rojo, La ciénaga', [
            ['sala', self::ORGANIZADORA, '70.00'],
            ['dueno', self::DUENA, '15.00'],
            ['plataforma', null, '15.00'],
        ]);
        $this->reparto('33333333-3333-4333-8333-333333333333', '3500.00', '2026-10-15 22:00:00', '2026-10-15 20:00:00', 'Cineclub Norte', 'Zama', [
            ['sala', self::ORGANIZADORA, '2450.00'],
            ['dueno', self::ORGANIZADORA, '525.00'],
            ['plataforma', null, '525.00'],
        ]);
    }

    /**
     * @param list<array{string, ?string, string}> $partes rol, beneficiario y monto
     */
    private function reparto(
        string $funcionId,
        string $cobrado,
        string $finalizadaEn,
        string $fechaHora,
        string $sala,
        string $titulos,
        array $partes,
    ): void {
        $repartoId = Uuid::nuevo();
        $this->base->ejecutar(
            'INSERT INTO reparto (id, funcion_id, cobrado, parametro_id, finalizada_en, funcion_fecha_hora, sala_nombre, titulos)
             VALUES (?, ?, ?, NULL, ?, ?, ?, ?)',
            [$repartoId, $funcionId, $cobrado, $finalizadaEn, $fechaHora, $sala, $titulos],
        );
        foreach ($partes as [$rol, $beneficiario, $monto]) {
            $this->base->ejecutar(
                'INSERT INTO reparto_parte (id, reparto_id, rol, beneficiario_id, monto) VALUES (?, ?, ?, ?, ?)',
                [Uuid::nuevo(), $repartoId, $rol, $beneficiario, $monto],
            );
        }
    }

    private function servicio(): ServicioDeLiquidaciones
    {
        return new ServicioDeLiquidaciones($this->liquidaciones, new RepositorioDeRepartos($this->base));
    }

    /** Arma los dos meses, como hace la tarea del reparto al terminar. */
    private function armar(): int
    {
        return $this->servicio()->armar([
            Mes::desdeTexto('2026-11') ?? self::fail('«2026-11» tiene que ser un mes.'),
            Mes::desdeTexto('2026-10') ?? self::fail('«2026-10» tiene que ser un mes.'),
        ]);
    }

    /**
     * @param array<string, mixed> $cuerpo
     */
    private function pedir(
        string $metodo,
        string $url,
        string $rol = 'organizador',
        string $usuario = self::ORGANIZADORA,
        array $cuerpo = [],
        bool $conCsrf = true,
    ): Respuesta {
        $cabeceras = [
            'X-Nginx-Secreto' => self::SECRETO,
            'X-Usuario-Id' => $usuario,
            'X-Rol' => $rol,
            'X-Usuario-Nombre' => rawurlencode('Olga Organizadora'),
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

    private function idDeLaLiquidacion(string $beneficiarioId, string $mes): string
    {
        return (string) $this->base->valor(
            'SELECT id FROM liquidacion WHERE beneficiario_id = ? AND periodo = ?',
            [$beneficiarioId, $mes],
        );
    }

    public function testLaOrganizadoraVeLoSuyoMesPorMesYElDetalleDelMasNuevo(): void
    {
        $respuesta = $this->pedir('GET', self::MIAS);

        self::assertSame(200, $respuesta->estado(), $respuesta->cuerpo());
        $cuerpo = $respuesta->cuerpo();
        // Noviembre: 7.000 de la sala en una función y 70 en la otra.
        self::assertStringContainsString('noviembre de 2026', $cuerpo);
        self::assertStringContainsString('$ 7.070', $cuerpo);
        // Octubre, donde además cobró como dueña de la obra.
        self::assertStringContainsString('$ 2.975', $cuerpo);
        // El detalle arranca en el mes más nuevo, con lo que guardó el reparto de cada función.
        self::assertStringContainsString('El Galpón', $cuerpo);
        self::assertStringContainsString('Zama', $cuerpo);
        self::assertStringContainsString('20 de noviembre de 2026', $cuerpo);
        // Y la entrada del menú del organizador, con el prefijo que repone el subsistema.
        self::assertStringContainsString('href="/recaudacion/liquidaciones"', $cuerpo);
    }

    /**
     * F-03.6: lo que se reparte es lo cobrado menos la comisión de Mercado Pago, y la pantalla lo
     * dice: en la bajada, y en el detalle con lo que se quedó Mercado Pago de cada función. Los
     * repartos de antes de la comisión la tienen en 0.
     */
    public function testElDetalleMuestraLaComisionDeMercadoPago(): void
    {
        $this->base->ejecutar('UPDATE reparto SET comision = ? WHERE funcion_id = ?', ['761.00', '11111111-1111-4111-8111-111111111111']);

        $cuerpo = $this->pedir('GET', self::MIAS)->cuerpo();

        self::assertStringContainsString('Lo que se reparte es lo cobrado menos la comisión de Mercado Pago.', $cuerpo);
        self::assertStringContainsString('<th scope="col">Comisión de Mercado Pago</th>', $cuerpo);
        self::assertStringContainsString('<td data-columna="Comisión de Mercado Pago">$ 761</td>', $cuerpo);
        self::assertStringContainsString('<td data-columna="Comisión de Mercado Pago">$ 0</td>', $cuerpo);
    }

    public function testCadaUnoVeSoloLoSuyo(): void
    {
        $cuerpo = $this->pedir('GET', self::MIAS, usuario: self::DUENA)->cuerpo();

        // 1.500 + 15 de sus obras en noviembre.
        self::assertStringContainsString('$ 1.515', $cuerpo);
        // Lo de la organizadora no aparece por ningún lado.
        self::assertStringNotContainsString('$ 7.070', $cuerpo);
        self::assertStringNotContainsString('$ 2.975', $cuerpo);
    }

    public function testElDetalleEsElDelMesElegido(): void
    {
        $cuerpo = $this->pedir('GET', self::MIAS . '?mes=2026-10')->cuerpo();

        self::assertStringContainsString('De dónde sale lo de octubre de 2026', $cuerpo);
        self::assertStringContainsString('Cineclub Norte', $cuerpo);
        self::assertStringNotContainsString('El Galpón', $cuerpo);
    }

    public function testUnMesQueNoEsSuyoNoAbreElDetalleDeOtro(): void
    {
        // La dueña no tiene nada en octubre: se muestra el mes que sí tiene.
        $cuerpo = $this->pedir('GET', self::MIAS . '?mes=2026-10', usuario: self::DUENA)->cuerpo();

        self::assertStringContainsString('De dónde sale lo de noviembre de 2026', $cuerpo);
        self::assertStringNotContainsString('Cineclub Norte', $cuerpo);
    }

    public function testElAdministradorVeTodasLasDelMesConSusNombres(): void
    {
        $respuesta = $this->pedir('GET', self::DEL_ADMINISTRADOR, rol: 'administrador', usuario: self::ADMINISTRADORA);

        self::assertSame(200, $respuesta->estado(), $respuesta->cuerpo());
        $cuerpo = $respuesta->cuerpo();
        self::assertStringContainsString('Olga Organizadora', $cuerpo);
        self::assertStringContainsString('Dalia Dueña', $cuerpo);
        self::assertStringContainsString('$ 7.070', $cuerpo);
        self::assertStringContainsString('$ 1.515', $cuerpo);
        // Lo que falta transferir en el mes: las dos juntas.
        self::assertStringContainsString('$ 8.585', $cuerpo);
    }

    public function testElAdministradorMarcaUnaComoTransferidaConSuNota(): void
    {
        $id = $this->idDeLaLiquidacion(self::ORGANIZADORA, '2026-11');

        $respuesta = $this->pedir(
            'POST',
            self::DEL_ADMINISTRADOR . "/{$id}/transferida",
            rol: 'administrador',
            usuario: self::ADMINISTRADORA,
            cuerpo: ['nota' => 'Transferencia 00123'],
        );

        self::assertSame(303, $respuesta->estado(), $respuesta->cuerpo());
        self::assertSame(
            '/recaudacion/administracion/liquidaciones?mes=2026-11&transferida=1',
            $respuesta->cabecera('Location'),
        );
        $fila = $this->base->fila('SELECT estado, transferida_en, nota FROM liquidacion WHERE id = ?', [$id]);
        self::assertNotNull($fila);
        self::assertSame('transferida', (string) $fila['estado']);
        self::assertSame('Transferencia 00123', (string) $fila['nota']);
        self::assertNotNull($fila['transferida_en']);
        // Y la organizadora lo ve en su pantalla.
        self::assertStringContainsString('Transferida', $this->pedir('GET', self::MIAS)->cuerpo());
        // Queda en el log quién la marcó (EF-18.1), con los ids y sin la nota.
        rewind($this->salidaDelLog);
        $log = (string) stream_get_contents($this->salidaDelLog);
        self::assertStringContainsString(self::ADMINISTRADORA . " marcó como transferida la liquidación {$id}.", $log);
        self::assertStringNotContainsString('Transferencia 00123', $log);
    }

    public function testMarcarlaDeNuevoNoPisaLaFechaNiLaNota(): void
    {
        $id = $this->idDeLaLiquidacion(self::ORGANIZADORA, '2026-11');
        $marcar = fn (string $nota): Respuesta => $this->pedir(
            'POST',
            self::DEL_ADMINISTRADOR . "/{$id}/transferida",
            rol: 'administrador',
            usuario: self::ADMINISTRADORA,
            cuerpo: ['nota' => $nota],
        );
        $marcar('La primera');

        $respuesta = $marcar('La segunda');

        self::assertSame(
            '/recaudacion/administracion/liquidaciones?mes=2026-11&ya=1',
            $respuesta->cabecera('Location'),
        );
        self::assertSame('La primera', (string) $this->base->valor('SELECT nota FROM liquidacion WHERE id = ?', [$id]));
    }

    /**
     * Lo que pide EF-08.3: una vez transferida, el monto es el que se pagó. Si después se reparte
     * otra función de ese mes, la liquidación ya transferida no se mueve.
     */
    public function testUnaLiquidacionTransferidaNoSeVuelveAArmar(): void
    {
        $id = $this->idDeLaLiquidacion(self::ORGANIZADORA, '2026-11');
        $this->liquidaciones->marcarTransferida($id, 'Pagada', new DateTimeImmutable('2026-12-01 10:00:00'));

        $this->reparto('44444444-4444-4444-8444-444444444444', '1000.00', '2026-11-25 23:00:00', '2026-11-25 21:00:00', 'El Galpón', 'Rojo', [
            ['sala', self::ORGANIZADORA, '700.00'],
            ['dueno', self::DUENA, '150.00'],
            ['plataforma', null, '150.00'],
        ]);
        $this->armar();

        self::assertSame('7070.00', (string) $this->base->valor('SELECT monto FROM liquidacion WHERE id = ?', [$id]));
        // La de la dueña seguía pendiente, así que sí se actualizó: 1.515 + 150.
        self::assertSame(
            '1665.00',
            (string) $this->base->valor('SELECT monto FROM liquidacion WHERE id = ?', [$this->idDeLaLiquidacion(self::DUENA, '2026-11')]),
        );
    }

    public function testUnaNotaDemasiadoLargaVuelveConElErrorYNoMarcaNada(): void
    {
        $id = $this->idDeLaLiquidacion(self::ORGANIZADORA, '2026-11');

        $respuesta = $this->pedir(
            'POST',
            self::DEL_ADMINISTRADOR . "/{$id}/transferida",
            rol: 'administrador',
            usuario: self::ADMINISTRADORA,
            cuerpo: ['nota' => str_repeat('a', 501)],
        );

        self::assertSame(422, $respuesta->estado());
        self::assertStringContainsString('hasta 500 caracteres', $respuesta->cuerpo());
        self::assertSame('pendiente', (string) $this->base->valor('SELECT estado FROM liquidacion WHERE id = ?', [$id]));
    }

    public function testUnaLiquidacionQueNoExisteNoSeMarca(): void
    {
        $respuesta = $this->pedir(
            'POST',
            self::DEL_ADMINISTRADOR . '/' . Uuid::nuevo() . '/transferida',
            rol: 'administrador',
            usuario: self::ADMINISTRADORA,
        );

        self::assertSame(404, $respuesta->estado());
    }

    public function testSinElTokenCsrfNoSeMarcaNada(): void
    {
        $id = $this->idDeLaLiquidacion(self::ORGANIZADORA, '2026-11');

        $respuesta = $this->pedir(
            'POST',
            self::DEL_ADMINISTRADOR . "/{$id}/transferida",
            rol: 'administrador',
            usuario: self::ADMINISTRADORA,
            conCsrf: false,
        );

        self::assertSame(403, $respuesta->estado());
        self::assertSame('pendiente', (string) $this->base->valor('SELECT estado FROM liquidacion WHERE id = ?', [$id]));
    }

    public function testElOrganizadorNoLlegaALasDeTodos(): void
    {
        $id = $this->idDeLaLiquidacion(self::ORGANIZADORA, '2026-11');

        self::assertSame(403, $this->pedir('GET', self::DEL_ADMINISTRADOR)->estado());
        self::assertSame(403, $this->pedir('POST', self::DEL_ADMINISTRADOR . "/{$id}/transferida")->estado());
        self::assertSame('pendiente', (string) $this->base->valor('SELECT estado FROM liquidacion WHERE id = ?', [$id]));
    }

    public function testSinLiquidacionesLasDosPantallasLoDicen(): void
    {
        $this->base->ejecutar('DELETE FROM liquidacion');

        self::assertStringContainsString('Todavía no hay nada liquidado', $this->pedir('GET', self::MIAS)->cuerpo());
        self::assertStringContainsString(
            'Todavía no hay liquidaciones',
            $this->pedir('GET', self::DEL_ADMINISTRADOR, rol: 'administrador', usuario: self::ADMINISTRADORA)->cuerpo(),
        );
    }
}
