<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Pruebas;

use IndieCinema\Nucleo\Aplicacion;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Log;
use IndieCinema\Nucleo\Uuid;
use PHPUnit\Framework\TestCase;

/**
 * La reserva de punta a punta (E4-01.3): las rutas de funciones con la sesión que manda nginx, el
 * token CSRF, la base de verdad y una programación de mentira que da 40 lugares a 3500.
 */
final class ReservasTest extends TestCase
{
    use ConBaseDePruebas;
    use ConProgramacionDePrueba;

    private const SECRETO = 'secreto-de-nginx';
    private const CSRF = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const EVA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const ELIAS = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
    private const PROXIMA = '12121212-1212-4212-8212-121212121212';
    private const EMPEZADA = '34343434-3434-4434-8434-343434343434';

    private Configuracion $configuracion;
    private BaseDeDatos $base;

    protected function setUp(): void
    {
        $this->configuracion = new Configuracion([
            'app' => ['subsistema' => 'funciones', 'prefijo' => '/funcion', 'entorno' => 'desarrollo'],
            'base' => self::baseDePruebas(),
            'nginx' => ['secreto' => self::SECRETO],
            'subsistemas' => ['programacion' => self::$url],
            'interno' => ['token' => 'token-de-funciones'],
        ]);
        $this->base = self::baseConTablasNuevas($this->configuracion);
    }

    /**
     * @param array<string, string> $cuerpo
     */
    private function pedir(string $metodo, string $ruta, ?string $usuario = self::EVA, array $cuerpo = []): Respuesta
    {
        $cabeceras = $usuario === null ? [] : ['X-Nginx-Secreto' => self::SECRETO, 'X-Usuario-Id' => $usuario, 'X-Rol' => 'espectador'];
        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion, new Log('funciones', fopen('php://memory', 'w')));

        parse_str((string) parse_url($ruta, PHP_URL_QUERY), $consulta);

        return $aplicacion->atender(new Peticion($metodo, (string) parse_url($ruta, PHP_URL_PATH), $consulta, $cuerpo, $cabeceras, ['csrf' => self::CSRF], []));
    }

    private function reservar(string $cantidad, string $funcion = self::PROXIMA, ?string $usuario = self::EVA): Respuesta
    {
        return $this->pedir('POST', "/reservar/{$funcion}", $usuario, ['_csrf' => self::CSRF, 'cantidad' => $cantidad]);
    }

    /**
     * Una reserva hecha por la página, como la haría el espectador.
     */
    private function reservada(string $cantidad = '1', ?string $usuario = self::EVA): string
    {
        self::assertSame(303, $this->reservar($cantidad, usuario: $usuario)->estado());

        return (string) $this->base->valor('SELECT id FROM reserva WHERE usuario_id = ? ORDER BY creada_en DESC, id LIMIT 1', [$usuario]);
    }

    private function cancelar(string $id, ?string $usuario = self::EVA, string $csrf = self::CSRF): Respuesta
    {
        return $this->pedir('POST', "/reservas/{$id}/cancelar", $usuario, ['_csrf' => $csrf]);
    }

    private function estado(string $id): string
    {
        return (string) $this->base->valor('SELECT estado FROM reserva WHERE id = ?', [$id]);
    }

    /**
     * Ocupa lugares de una función sin pasar por la página, como si los hubieran reservado otros.
     */
    private function ocupar(string $funcion, int $cantidad, string $usuario = self::ELIAS): string
    {
        $id = Uuid::nuevo();
        $this->base->ejecutar(
            'INSERT INTO reserva (id, funcion_id, usuario_id, codigo, cantidad, total, creada_en) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$id, $funcion, $usuario, strtoupper(substr(bin2hex(random_bytes(4)), 0, 8)), $cantidad, '0.00', '2026-10-19 10:00:00'],
        );

        return $id;
    }

    private function reservas(): int
    {
        return (int) $this->base->valor('SELECT COUNT(*) FROM reserva');
    }

    public function testSinSesionNoSeReserva(): void
    {
        self::assertSame(401, $this->pedir('GET', '/reservar/' . self::PROXIMA, null)->estado());
        self::assertSame(401, $this->reservar('1', usuario: null)->estado());
        self::assertSame(0, $this->reservas());
    }

    public function testLaPaginaDeReservaTieneLaFuncionYElFormulario(): void
    {
        $respuesta = $this->pedir('GET', '/reservar/' . self::PROXIMA);

        self::assertSame(200, $respuesta->estado());
        $cuerpo = $respuesta->cuerpo();
        self::assertStringContainsString('Reservá tus lugares', $cuerpo);
        self::assertStringContainsString('Cine Club El Galpón · Luján', $cuerpo);
        self::assertStringContainsString('$ 3.500 por lugar', $cuerpo);
        self::assertStringContainsString('href="/cartelera/' . self::PROXIMA . '"', $cuerpo);
        self::assertStringContainsString('action="/funcion/reservar/' . self::PROXIMA . '" method="POST"', $cuerpo);
        self::assertStringContainsString('value="' . self::CSRF . '"', $cuerpo);
        self::assertMatchesRegularExpression('/name="cantidad"[^>]*min="1"[^>]*max="40"/s', $cuerpo);
    }

    public function testReservarLlevaALaConfirmacionConElCodigo(): void
    {
        $respuesta = $this->reservar('2');

        self::assertSame(303, $respuesta->estado());
        $id = (string) $this->base->valor('SELECT id FROM reserva');
        self::assertSame("/funcion/reservas/{$id}", $respuesta->cabecera('Location'));
        self::assertSame(2, $this->base->valor('SELECT cantidad FROM reserva WHERE id = ?', [$id]));
        self::assertSame(self::EVA, $this->base->valor('SELECT usuario_id FROM reserva WHERE id = ?', [$id]));

        $confirmacion = $this->pedir('GET', "/reservas/{$id}");
        self::assertSame(200, $confirmacion->estado());
        $codigo = (string) $this->base->valor('SELECT codigo FROM reserva WHERE id = ?', [$id]);
        $cuerpo = $confirmacion->cuerpo();
        self::assertStringContainsString('Reserva confirmada', $cuerpo);
        self::assertStringContainsString(substr($codigo, 0, 4) . '-' . substr($codigo, 4), $cuerpo);
        self::assertStringContainsString('$ 7.000', $cuerpo);
        self::assertStringContainsString('Cine Club El Galpón · Luján', $cuerpo);
    }

    public function testLaReservaDeOtroEsUn404(): void
    {
        $this->reservar('1');
        $id = (string) $this->base->valor('SELECT id FROM reserva');

        self::assertSame(404, $this->pedir('GET', "/reservas/{$id}", self::ELIAS)->estado());
        self::assertSame(404, $this->pedir('GET', '/reservas/' . Uuid::nuevo())->estado());
        self::assertSame(404, $this->pedir('GET', '/reservas/no-es-un-id')->estado());
    }

    public function testSinElTokenCsrfNoSeReserva(): void
    {
        $respuesta = $this->pedir('POST', '/reservar/' . self::PROXIMA, self::EVA, ['_csrf' => 'otro', 'cantidad' => '1']);

        self::assertSame(403, $respuesta->estado());
        self::assertSame(0, $this->reservas());
    }

    public function testUnaCantidadInvalidaSeRechazaEnElServidor(): void
    {
        foreach (['', 'dos', '0', '-1', '1.5', '41', "1' OR 1=1"] as $cantidad) {
            $respuesta = $this->reservar($cantidad);

            self::assertSame(422, $respuesta->estado(), "Aceptó «{$cantidad}».");
            self::assertStringContainsString('Ingresá entre 1 y 40 lugares.', $respuesta->cuerpo());
        }
        self::assertSame(0, $this->reservas());
    }

    public function testSinLugaresSuficientesLoDiceYNoReserva(): void
    {
        $this->base->ejecutar(
            "INSERT INTO reserva (id, funcion_id, usuario_id, codigo, cantidad, total, creada_en) VALUES (?, ?, ?, 'ZZZZZZZZ', 39, '0.00', '2026-10-19 10:00:00')",
            [Uuid::nuevo(), self::PROXIMA, self::ELIAS],
        );

        $respuesta = $this->reservar('2');

        self::assertSame(422, $respuesta->estado());
        self::assertStringContainsString('Queda 1 lugar.', $respuesta->cuerpo());
        // Vuelve con lo que había puesto.
        self::assertMatchesRegularExpression('/name="cantidad"[^>]*value="2"/s', $respuesta->cuerpo());
        self::assertSame(1, $this->reservas());
    }

    public function testUnaFuncionQueYaEmpezoNoMuestraElFormulario(): void
    {
        $pagina = $this->pedir('GET', '/reservar/' . self::EMPEZADA);
        self::assertSame(200, $pagina->estado());
        self::assertStringContainsString('La función ya empezó: ya no se puede reservar.', $pagina->cuerpo());
        self::assertStringNotContainsString('name="cantidad"', $pagina->cuerpo());

        $respuesta = $this->reservar('1', self::EMPEZADA);
        self::assertSame(422, $respuesta->estado());
        self::assertSame(0, $this->reservas());
    }

    public function testUnaFuncionQueNoExisteEsUn404(): void
    {
        self::assertSame(404, $this->pedir('GET', '/reservar/' . Uuid::nuevo())->estado());
        self::assertSame(404, $this->reservar('1', Uuid::nuevo())->estado());
    }

    public function testElComprobanteOfreceCancelarYLaPantallaLoConfirma(): void
    {
        $id = $this->reservada('2');

        self::assertStringContainsString('href="/funcion/reservas/' . $id . '/cancelar"', $this->pedir('GET', "/reservas/{$id}")->cuerpo());
        $pantalla = $this->pedir('GET', "/reservas/{$id}/cancelar");
        self::assertSame(200, $pantalla->estado());
        self::assertStringContainsString('¿Querés cancelar esta reserva?', $pantalla->cuerpo());
        self::assertStringContainsString('action="/funcion/reservas/' . $id . '/cancelar" method="POST"', $pantalla->cuerpo());
        // Mirarla no la cancela.
        self::assertSame('confirmada', $this->estado($id));
    }

    public function testCancelarLaDejaCanceladaYLoAvisa(): void
    {
        $id = $this->reservada('2');

        $respuesta = $this->cancelar($id);

        self::assertSame(303, $respuesta->estado());
        self::assertSame("/funcion/reservas/{$id}?cancelada=1", $respuesta->cabecera('Location'));
        self::assertSame('cancelada', $this->estado($id));
        $comprobante = $this->pedir('GET', "/reservas/{$id}?cancelada=1")->cuerpo();
        self::assertStringContainsString('Cancelaste la reserva', $comprobante);
        self::assertStringContainsString('Reserva cancelada', $comprobante);
        self::assertStringNotContainsString('/cancelar"', $comprobante);
    }

    /**
     * El criterio de la subtarea: con la función completa, cancelar deja el lugar para otro.
     */
    public function testElLugarCanceladoVuelveAQuedarLibre(): void
    {
        $id = $this->reservada('1');
        $this->ocupar(self::PROXIMA, 39);
        self::assertStringContainsString('La función está agotada.', $this->reservar('1', usuario: self::ELIAS)->cuerpo());

        $this->cancelar($id);

        self::assertSame(303, $this->reservar('1', usuario: self::ELIAS)->estado());
    }

    public function testLaReservaDeOtroNoSeCancela(): void
    {
        $id = $this->reservada();

        self::assertSame(404, $this->pedir('GET', "/reservas/{$id}/cancelar", self::ELIAS)->estado());
        self::assertSame(404, $this->cancelar($id, self::ELIAS)->estado());
        self::assertSame('confirmada', $this->estado($id));
    }

    public function testSinElTokenCsrfNoSeCancela(): void
    {
        $id = $this->reservada();

        self::assertSame(403, $this->cancelar($id, csrf: 'otro')->estado());
        self::assertSame('confirmada', $this->estado($id));
    }

    public function testUnaCanceladaOConAsistenciaNoSeVuelveACancelar(): void
    {
        $cancelada = $this->reservada();
        $this->cancelar($cancelada);
        $conAsistencia = $this->reservada();
        $this->base->ejecutar("UPDATE reserva SET estado = 'asistio' WHERE id = ?", [$conAsistencia]);

        foreach ([$cancelada => 'La reserva ya está cancelada.', $conAsistencia => 'Ya registraron tu asistencia: la reserva no se puede cancelar.'] as $id => $mensaje) {
            self::assertSame(404, $this->pedir('GET', "/reservas/{$id}/cancelar")->estado());
            $respuesta = $this->cancelar($id);
            self::assertSame(422, $respuesta->estado());
            self::assertStringContainsString($mensaje, $respuesta->cuerpo());
            self::assertStringNotContainsString('action="/funcion/reservas/' . $id . '/cancelar"', $respuesta->cuerpo());
        }
        self::assertSame('asistio', $this->estado($conAsistencia));
    }

    public function testCuandoLaFuncionEmpezoYaNoSeCancela(): void
    {
        $id = $this->ocupar(self::EMPEZADA, 2, self::EVA);

        self::assertStringNotContainsString('/cancelar"', $this->pedir('GET', "/reservas/{$id}")->cuerpo());
        self::assertSame(404, $this->pedir('GET', "/reservas/{$id}/cancelar")->estado());
        $respuesta = $this->cancelar($id);
        self::assertSame(422, $respuesta->estado());
        self::assertStringContainsString('La función ya empezó: la reserva ya no se puede cancelar.', $respuesta->cuerpo());
        self::assertSame('confirmada', $this->estado($id));
    }
}
