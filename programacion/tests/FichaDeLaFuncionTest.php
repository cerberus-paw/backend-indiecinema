<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Pruebas;

use DOMDocument;
use DOMXPath;
use IndieCinema\Nucleo\Aplicacion;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Log;
use IndieCinema\Nucleo\Pruebas\Dobles\PaginasParaValidar;
use IndieCinema\Programacion\Repositorios\RepositorioDeFunciones;
use IndieCinema\Programacion\Repositorios\RepositorioDeGeneros;
use IndieCinema\Programacion\Repositorios\RepositorioDeObras;
use IndieCinema\Programacion\Repositorios\RepositorioDeSalas;
use IndieCinema\Programacion\Servicios\DatosDePrueba;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * La ficha de una función programada como el wireframe «funcion-programada» (E4-10.10): los
 * recuadros de fecha, horario, duración total y entrada, y la reserva en una columna al costado;
 * una finalizada o cancelada, sin ese costado. De punta a punta: la aplicación entera con las
 * plantillas del paquete front y un MySQL de verdad con los datos de demostración. Funciones no
 * responde (un puerto en el que no escucha nadie): la ficha se ve igual, sin los lugares libres.
 */
final class FichaDeLaFuncionTest extends TestCase
{
    use ConBaseDePruebas;

    private BaseDeDatos $base;
    private string $carpeta;

    protected function setUp(): void
    {
        $this->carpeta = sys_get_temp_dir() . '/archivos-' . bin2hex(random_bytes(4));
        $this->base = self::baseConTablasNuevas($this->configuracion());
        DatosDePrueba::para(
            $this->configuracion(),
            new RepositorioDeGeneros($this->base),
            new RepositorioDeSalas($this->base),
            new RepositorioDeObras($this->base),
            new RepositorioDeFunciones($this->base),
        )->cargar();
    }

    protected function tearDown(): void
    {
        foreach (['salas', 'afiches'] as $subcarpeta) {
            array_map('unlink', glob("{$this->carpeta}/{$subcarpeta}/*") ?: []);
            @rmdir("{$this->carpeta}/{$subcarpeta}");
        }
        @rmdir($this->carpeta);
    }

    private function configuracion(): Configuracion
    {
        return new Configuracion([
            'app' => ['subsistema' => 'programacion', 'entorno' => 'desarrollo'],
            'archivos' => ['carpeta' => $this->carpeta],
            'base' => self::baseDePruebas(),
            'nginx' => ['secreto' => 'secreto-de-nginx'],
            'subsistemas' => ['funciones' => 'http://127.0.0.1:9'],
            'interno' => ['token' => 'token-de-programacion'],
        ]);
    }

    /**
     * La ficha, lista para buscar en ella como la lee un lector de pantalla.
     *
     * @param bool $conSesion si la mira un espectador que ingresó; si no, un visitante
     */
    private function ficha(string $id, bool $conSesion = false): DOMXPath
    {
        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion(), new Log('programacion', fopen('php://memory', 'w')));
        $cabeceras = $conSesion ? [
            'X-Nginx-Secreto' => 'secreto-de-nginx',
            'X-Usuario-Id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'X-Rol' => 'espectador',
            'X-Usuario-Nombre' => rawurlencode('Ana Prueba'),
        ] : [];
        $respuesta = $aplicacion->atender(new Peticion('GET', "/cartelera/{$id}", [], [], $cabeceras, [], []));
        self::assertSame(200, $respuesta->estado());
        PaginasParaValidar::guardar($conSesion ? 'ficha-de-una-funcion-con-sesion' : 'ficha-de-una-funcion', $respuesta->cuerpo());

        $documento = new DOMDocument();
        @$documento->loadHTML('<?xml encoding="UTF-8">' . $respuesta->cuerpo());

        return new DOMXPath($documento);
    }

    /**
     * Una función programada de la cartelera, puesta el sábado 18 de septiembre de 2027 a las
     * 20:30, con dos obras de 95 y 85 minutos y la entrada a $ 3.500.
     */
    private function programadaConocida(string $estado = 'programada'): string
    {
        $id = (string) $this->base->valor(
            "SELECT f.id FROM funcion f WHERE f.estado = 'programada' AND f.fecha_hora > NOW()
             AND (SELECT COUNT(*) FROM funcion_obra fo WHERE fo.funcion_id = f.id) >= 2
             ORDER BY f.fecha_hora, f.id LIMIT 1",
        );
        $obras = array_map(
            static fn (array $fila): string => (string) $fila['obra_id'],
            $this->base->filas('SELECT obra_id FROM funcion_obra WHERE funcion_id = ? ORDER BY obra_id', [$id]),
        );
        $this->base->ejecutar('DELETE FROM funcion_obra WHERE funcion_id = ? AND obra_id NOT IN (?, ?)', [$id, $obras[0], $obras[1]]);
        $this->base->ejecutar('UPDATE obra SET duracion = 95 WHERE id = ?', [$obras[0]]);
        $this->base->ejecutar('UPDATE obra SET duracion = 85 WHERE id = ?', [$obras[1]]);
        $this->base->ejecutar(
            "UPDATE funcion SET fecha_hora = '2027-09-18 20:30:00', precio_base = 3500, estado = ? WHERE id = ?",
            [$estado, $id],
        );

        return $id;
    }

    /**
     * La misma función, pero abierta a votación de títulos: sus obras pasan a ser las candidatas,
     * todavía no hay ninguna elegida, y no tiene fecha hasta que la votación cierre.
     */
    private function enVotacionConocida(): string
    {
        $id = $this->programadaConocida();
        $this->base->ejecutar("UPDATE funcion SET estado = 'en_votacion_titulos', fecha_hora = NULL WHERE id = ?", [$id]);
        $this->base->ejecutar("UPDATE funcion_obra SET estado = 'candidata' WHERE funcion_id = ?", [$id]);

        return $id;
    }

    /**
     * Lo que dice el recuadro con ese rótulo, o null si no está.
     */
    private static function recuadro(DOMXPath $ficha, string $rotulo): ?string
    {
        $valor = $ficha->query("//main//dt[normalize-space() = '{$rotulo}']/following-sibling::dd[1]")->item(0);

        return $valor === null ? null : trim((string) preg_replace('/\s+/u', ' ', $valor->textContent));
    }

    public function testUnaProgramadaMuestraLaFechaSolaEnSuRecuadro(): void
    {
        $ficha = $this->ficha($this->programadaConocida());

        self::assertSame('Sáb 18 sep', self::recuadro($ficha, 'Fecha'));
    }

    public function testElHorarioTerminaCuandoTerminanSusObras(): void
    {
        // 20:30 más 95 y 85 minutos.
        $ficha = $this->ficha($this->programadaConocida());

        self::assertSame('20:30 a 23:30', self::recuadro($ficha, 'Horario'));
        self::assertSame('180 min', self::recuadro($ficha, 'Duración total'));
    }

    /**
     * En votación de títulos las obras del detalle son las candidatas: sumar sus duraciones daría
     * la de todo lo que compite y no la de la función, que todavía no se sabe. Sin fecha, tampoco
     * hay día ni horario.
     */
    public function testEnVotacionDeTitulosNoHayNiDuracionTotalNiHorario(): void
    {
        // Las mismas dos obras de 95 y 85 minutos, ahora candidatas: no son «180 min».
        $ficha = $this->ficha($this->enVotacionConocida());

        self::assertNull(self::recuadro($ficha, 'Duración total'));
        self::assertNull(self::recuadro($ficha, 'Horario'));
        self::assertNull(self::recuadro($ficha, 'Fecha'));
    }

    public function testLaEntradaVaEnSuRecuadroYLaReservaAlCostado(): void
    {
        $ficha = $this->ficha($this->programadaConocida(), conSesion: true);

        self::assertSame('$ 3.500', self::recuadro($ficha, 'Entrada'));
        // El formulario de siempre, con el atributo del que depende el componente de reserva.
        self::assertSame(1, $ficha->query('//main//aside//form[@data-formulario-reserva]')->length);
    }

    public function testSinSesionElCostadoTieneElEnlaceParaIngresar(): void
    {
        $ficha = $this->ficha($this->programadaConocida());

        self::assertSame(1, $ficha->query("//main//aside//a[@href = '/cuenta/ingresar']")->length);
        self::assertSame(0, $ficha->query('//main//form[@data-formulario-reserva]')->length);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function sinReserva(): iterable
    {
        yield 'finalizada' => ['finalizada'];
        yield 'cancelada' => ['cancelada'];
    }

    #[DataProvider('sinReserva')]
    public function testUnaFinalizadaOCanceladaVaEnUnaColumna(string $estado): void
    {
        $ficha = $this->ficha($this->programadaConocida($estado), conSesion: true);

        self::assertSame(0, $ficha->query('//main//aside')->length);
        // El detalle es el mismo.
        self::assertSame('Sáb 18 sep', self::recuadro($ficha, 'Fecha'));
        self::assertSame('20:30 a 23:30', self::recuadro($ficha, 'Horario'));
    }
}
