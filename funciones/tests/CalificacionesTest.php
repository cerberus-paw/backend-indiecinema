<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Pruebas;

use IndieCinema\Nucleo\Aplicacion;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Log;
use IndieCinema\Nucleo\Pruebas\Dobles\PaginasParaValidar;
use IndieCinema\Nucleo\Uuid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Calificar una función de punta a punta (EF-04.2): con la sesión que manda nginx, el token CSRF,
 * la base de verdad y una programación de mentira, en la que FINALIZADA terminó hace dos días,
 * VIEJA hace cuarenta y EMPEZADA todavía no se finalizó.
 */
final class CalificacionesTest extends TestCase
{
    use ConBaseDePruebas;
    use ConProgramacionDePrueba;

    private const SECRETO = 'secreto-de-nginx';
    private const CSRF = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const EVA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const ELIAS = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
    private const FINALIZADA = '13131313-1313-4313-8313-131313131313';
    private const VIEJA = '24242424-2424-4424-8424-242424242424';
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
            // «Mis reservas» arma también los avisos por correo, aunque acá no sale ninguno.
            'correo' => ['servidor' => 'smtp://127.0.0.1:9', 'remitente' => 'no-responder@indiecinema.test'],
            'sitio' => ['url' => 'http://localhost:8080'],
        ]);
        $this->base = self::baseConTablasNuevas($this->configuracion);
    }

    /**
     * @param array<string, string> $cuerpo
     */
    private function pedir(string $metodo, string $ruta, array $cuerpo = [], string $usuario = self::EVA, bool $conCsrf = true): Respuesta
    {
        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion, new Log('funciones', fopen('php://memory', 'w')));
        if ($metodo === 'POST' && $conCsrf) {
            $cuerpo = ['_csrf' => self::CSRF, ...$cuerpo];
        }

        parse_str((string) parse_url($ruta, PHP_URL_QUERY), $consulta);

        return PaginasParaValidar::anotar($metodo, $ruta, $aplicacion->atender(new Peticion($metodo, (string) parse_url($ruta, PHP_URL_PATH), $consulta, $cuerpo, [
            'X-Nginx-Secreto' => self::SECRETO,
            'X-Usuario-Id' => $usuario,
            'X-Rol' => 'espectador',
        ], ['csrf' => self::CSRF], [])));
    }

    /**
     * Una reserva hecha sin pasar por la página.
     *
     * @return string su id
     */
    private function reserva(string $funcion, string $estado = 'asistio', string $usuario = self::EVA): string
    {
        $id = Uuid::nuevo();
        $this->base->ejecutar(
            'INSERT INTO reserva (id, funcion_id, usuario_id, codigo, cantidad, total, estado, creada_en) VALUES (?, ?, ?, ?, 1, 3500, ?, UTC_TIMESTAMP())',
            [$id, $funcion, $usuario, strtoupper(substr(bin2hex(random_bytes(4)), 0, 8)), $estado],
        );

        return $id;
    }

    private function calificaciones(): int
    {
        return (int) $this->base->valor('SELECT COUNT(*) FROM calificacion');
    }

    public function testQuienAsistioCalificaUnaVezYQuedaConLaSalaElOrganizadorYLasObras(): void
    {
        $reserva = $this->reserva(self::FINALIZADA);

        $formulario = $this->pedir('GET', "/reservas/{$reserva}/calificar");
        self::assertSame(200, $formulario->estado(), $formulario->cuerpo());
        self::assertStringContainsString('Calificar la función', $formulario->cuerpo());
        self::assertStringContainsString('Cine Club El Galpón', $formulario->cuerpo());

        $respuesta = $this->pedir('POST', "/reservas/{$reserva}/calificar", ['puntaje' => '4', 'comentario' => "  Muy linda la copia.\n  "]);

        self::assertSame(303, $respuesta->estado(), $respuesta->cuerpo());
        self::assertSame('/funcion/reservas?calificada=1', $respuesta->cabecera('Location'));
        $fila = $this->base->fila('SELECT * FROM calificacion');
        self::assertNotNull($fila);
        self::assertSame([self::FINALIZADA, self::EVA, $reserva, 4, 'Muy linda la copia.', 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee', 'ffffffff-ffff-4fff-8fff-ffffffffffff'], [
            $fila['funcion_id'], $fila['usuario_id'], $fila['reserva_id'], (int) $fila['puntaje'], $fila['comentario'], $fila['sala_id'], $fila['organizador_id'],
        ]);
        self::assertSame(['0b0b0b0b-0b0b-40b0-80b0-0b0b0b0b0b0b'], array_column($this->base->filas('SELECT obra_id FROM calificacion_obra'), 'obra_id'));

        // Una sola vez: ni el formulario ni el envío.
        self::assertSame(403, $this->pedir('GET', "/reservas/{$reserva}/calificar")->estado());
        $otraVez = $this->pedir('POST', "/reservas/{$reserva}/calificar", ['puntaje' => '1']);
        self::assertSame(403, $otraVez->estado());
        self::assertStringContainsString('Ya calificaste esta función.', $otraVez->cuerpo());
        self::assertSame(1, $this->calificaciones());
    }

    public function testMisReservasOfreceCalificarSoloLoQueFaltaYAvisaAlGuardar(): void
    {
        $paraCalificar = $this->reserva(self::FINALIZADA);
        $sinAsistencia = $this->reserva(self::EMPEZADA, 'confirmada');
        $fueraDePlazo = $this->reserva(self::VIEJA);

        $antes = $this->pedir('GET', '/reservas')->cuerpo();
        self::assertStringContainsString("/funcion/reservas/{$paraCalificar}/calificar", $antes);
        self::assertStringNotContainsString("/funcion/reservas/{$sinAsistencia}/calificar", $antes);
        self::assertStringNotContainsString("/funcion/reservas/{$fueraDePlazo}/calificar", $antes);

        $this->pedir('POST', "/reservas/{$paraCalificar}/calificar", ['puntaje' => '5']);

        $despues = $this->pedir('GET', '/reservas?calificada=1');
        self::assertSame(200, $despues->estado());
        self::assertStringNotContainsString("/funcion/reservas/{$paraCalificar}/calificar", $despues->cuerpo());
        self::assertStringContainsString('¡Gracias por calificar!', $despues->cuerpo());
    }

    public function testMisReservasMuestraLoQueCalificoEnLaReservaConLaQueFue(): void
    {
        $conLaQueFue = $this->reserva(self::FINALIZADA);
        $otraDeLaMisma = $this->reserva(self::FINALIZADA);
        $ausente = $this->reserva(self::FINALIZADA, 'ausente');
        $this->pedir('POST', "/reservas/{$conLaQueFue}/calificar", ['puntaje' => '4', 'comentario' => 'Buen <debate> después.']);

        $cuerpo = $this->pedir('GET', '/reservas')->cuerpo();

        self::assertSame(1, substr_count($cuerpo, 'Calificaste 4 de 5.'));
        self::assertStringContainsString('«Buen &lt;debate&gt; después.»', $cuerpo);
        // La otra reserva de la misma función ya no ofrece calificar.
        self::assertStringNotContainsString("/funcion/reservas/{$otraDeLaMisma}/calificar", $cuerpo);
        self::assertStringContainsString('Reservaste y no asististe', $cuerpo);
        self::assertStringContainsString($ausente, $cuerpo);
    }

    public function testElComentarioEsOpcional(): void
    {
        $reserva = $this->reserva(self::FINALIZADA);

        self::assertSame(303, $this->pedir('POST', "/reservas/{$reserva}/calificar", ['puntaje' => '5', 'comentario' => '   '])->estado());
        self::assertNull($this->base->valor('SELECT comentario FROM calificacion'));
    }

    public function testConDosReservasDeLaMismaFuncionCalificaUnaSolaVez(): void
    {
        $una = $this->reserva(self::FINALIZADA);
        $otra = $this->reserva(self::FINALIZADA);

        self::assertSame(303, $this->pedir('POST', "/reservas/{$una}/calificar", ['puntaje' => '3'])->estado());
        self::assertSame(403, $this->pedir('POST', "/reservas/{$otra}/calificar", ['puntaje' => '3'])->estado());
        self::assertSame(1, $this->calificaciones());
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function sinDerechoACalificar(): iterable
    {
        yield 'confirmada sin asistencia' => [self::FINALIZADA, 'confirmada', 'Sólo puede calificar la función quien tiene la asistencia registrada.'];
        yield 'ausente' => [self::FINALIZADA, 'ausente', 'Sólo puede calificar la función quien tiene la asistencia registrada.'];
        yield 'cancelada' => [self::FINALIZADA, 'cancelada', 'Sólo puede calificar la función quien tiene la asistencia registrada.'];
        yield 'la función no se finalizó' => [self::EMPEZADA, 'asistio', 'La función todavía no terminó'];
        yield 'pasaron más de 30 días' => [self::VIEJA, 'asistio', 'Pasaron más de 30 días desde la función'];
    }

    #[DataProvider('sinDerechoACalificar')]
    public function testSoloCalificaQuienAsistioAUnaFuncionFinalizadaDentroDelPlazo(string $funcion, string $estado, string $motivo): void
    {
        $reserva = $this->reserva($funcion, $estado);

        $formulario = $this->pedir('GET', "/reservas/{$reserva}/calificar");
        $envio = $this->pedir('POST', "/reservas/{$reserva}/calificar", ['puntaje' => '5']);

        self::assertSame(403, $formulario->estado());
        self::assertStringContainsString($motivo, $formulario->cuerpo());
        self::assertSame(403, $envio->estado());
        self::assertSame(0, $this->calificaciones());
    }

    public function testLaReservaDeOtroEspectadorEs404(): void
    {
        $deElias = $this->reserva(self::FINALIZADA, usuario: self::ELIAS);

        self::assertSame(404, $this->pedir('GET', "/reservas/{$deElias}/calificar")->estado());
        self::assertSame(404, $this->pedir('POST', "/reservas/{$deElias}/calificar", ['puntaje' => '5'])->estado());
        self::assertSame(404, $this->pedir('GET', '/reservas/no-es-un-uuid/calificar')->estado());
        self::assertSame(0, $this->calificaciones());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function puntajesInvalidos(): iterable
    {
        yield 'cero' => ['0'];
        yield 'seis' => ['6'];
        yield 'texto' => ['abc'];
        yield 'con decimales' => ['4.5'];
        yield 'vacío' => [''];
    }

    #[DataProvider('puntajesInvalidos')]
    public function testElServidorRechazaUnPuntajeFueraDe1A5(string $puntaje): void
    {
        $reserva = $this->reserva(self::FINALIZADA);

        $respuesta = $this->pedir('POST', "/reservas/{$reserva}/calificar", ['puntaje' => $puntaje, 'comentario' => 'Lo que escribí']);

        self::assertSame(422, $respuesta->estado());
        self::assertStringContainsString('Elegí un puntaje del 1 al 5.', $respuesta->cuerpo());
        // Lo escrito vuelve, para no tener que escribirlo de nuevo.
        self::assertStringContainsString('Lo que escribí', $respuesta->cuerpo());
        self::assertSame(0, $this->calificaciones());
    }

    public function testElComentarioTieneHasta500Caracteres(): void
    {
        $reserva = $this->reserva(self::FINALIZADA);

        $largo = $this->pedir('POST', "/reservas/{$reserva}/calificar", ['puntaje' => '4', 'comentario' => str_repeat('á', 501)]);
        self::assertSame(422, $largo->estado());
        self::assertStringContainsString('El comentario puede tener hasta 500 caracteres.', $largo->cuerpo());
        self::assertSame(0, $this->calificaciones());

        // 500 con tildes son 500, aunque sean más bytes.
        self::assertSame(303, $this->pedir('POST', "/reservas/{$reserva}/calificar", ['puntaje' => '4', 'comentario' => str_repeat('á', 500)])->estado());
    }

    public function testSinElTokenCsrfNoCalifica(): void
    {
        $reserva = $this->reserva(self::FINALIZADA);

        self::assertSame(403, $this->pedir('POST', "/reservas/{$reserva}/calificar", ['puntaje' => '5'], conCsrf: false)->estado());
        self::assertSame(0, $this->calificaciones());
    }

    public function testElComentarioSeEscapaEnElFormulario(): void
    {
        $reserva = $this->reserva(self::FINALIZADA);

        $respuesta = $this->pedir('POST', "/reservas/{$reserva}/calificar", ['puntaje' => '9', 'comentario' => '<script>alert(1)</script>']);

        self::assertStringNotContainsString('<script>alert(1)</script>', $respuesta->cuerpo());
        self::assertStringContainsString('&lt;script&gt;', $respuesta->cuerpo());
    }
}
