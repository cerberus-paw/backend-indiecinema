<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Pruebas;

use Dom\HTMLDocument;
use IndieCinema\Nucleo\Aplicacion;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Log;
use IndieCinema\Nucleo\Pruebas\Dobles\Formularios;
use IndieCinema\Nucleo\Pruebas\Dobles\PaginasParaValidar;
use IndieCinema\Nucleo\Uuid;
use IndieCinema\Programacion\Controladores\FormularioDePedido;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pedidos de títulos ausentes de punta a punta (EF-11.1 / IC-576): alta con título, año y
 * director, detección de parecidos con opción de sumarse, adhesión única sostenida por la clave
 * primaria de adhesion_pedido y opción de dejar de pedirlo.
 */
final class PedidosDeTitulosTest extends TestCase
{
    use ConBaseDePruebas;
    use ConSaltoAlContenido;

    private const SECRETO = 'secreto-de-nginx';
    private const CSRF = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';
    private const ESPECTADORA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const OTRO_ESPECTADOR = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

    private BaseDeDatos $base;

    /** @var resource */
    private $salidaDelLog;

    protected function setUp(): void
    {
        $this->salidaDelLog = fopen('php://memory', 'w+');
        $this->base = self::baseConTablasNuevas($this->configuracion());
    }

    private function configuracion(int $umbral = 5, int $porPagina = 12): Configuracion
    {
        return new Configuracion([
            'app' => ['subsistema' => 'programacion', 'entorno' => 'desarrollo'],
            'base' => self::baseDePruebas(),
            'nginx' => ['secreto' => self::SECRETO],
            'pedidos' => ['umbral' => $umbral, 'por_pagina' => $porPagina],
        ]);
    }

    /**
     * @param array<string, mixed> $cuerpo
     */
    private function pedir(
        string $metodo = 'GET',
        string $url = '/pedidos/nuevo',
        array $cuerpo = [],
        ?string $rol = 'espectador',
        string $usuario = self::ESPECTADORA,
        bool $conCsrf = true,
        bool $json = false,
        int $umbral = 5,
        int $porPagina = 12,
    ): Respuesta {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $consulta);
        $cabeceras = $rol === null ? [] : [
            'X-Nginx-Secreto' => self::SECRETO,
            'X-Usuario-Id' => $usuario,
            'X-Rol' => $rol,
            'X-Usuario-Nombre' => rawurlencode('Eva Prueba'),
        ];
        if ($json) {
            $cabeceras['Accept'] = 'application/json';
        }
        $campos = $metodo === 'POST' && $conCsrf ? ['_csrf' => self::CSRF, ...$cuerpo] : $cuerpo;
        $aplicacion = new Aplicacion(
            dirname(__DIR__),
            $this->configuracion($umbral, $porPagina),
            new Log('programacion', $this->salidaDelLog),
        );

        $respuesta = $aplicacion->atender(new Peticion(
            $metodo,
            (string) parse_url($url, PHP_URL_PATH),
            $consulta,
            $campos,
            $cabeceras,
            ['csrf' => self::CSRF],
        ));

        return $json ? $respuesta : PaginasParaValidar::anotar($metodo, $url, $respuesta);
    }

    private function log(): string
    {
        rewind($this->salidaDelLog);

        return (string) stream_get_contents($this->salidaDelLog);
    }

    public function testMuestraElFormularioConElTokenYLasReglasDelBackend(): void
    {
        $respuesta = $this->pedir();

        self::assertSame(200, $respuesta->estado());
        self::assertSaltaAlContenido($respuesta->cuerpo());
        self::assertStringContainsString('<form action="/pedidos/nuevo" method="post"', $respuesta->cuerpo());
        self::assertStringContainsString('name="_csrf" value="' . self::CSRF . '"', $respuesta->cuerpo());

        $documento = HTMLDocument::createFromString($respuesta->cuerpo(), LIBXML_NOERROR);
        $formulario = $documento->querySelector('form[action="/pedidos/nuevo"]');
        self::assertNotNull($formulario);
        self::assertFalse($formulario->hasAttribute('novalidate'));

        $atributos = ['maximo' => 'maxlength', 'valor_minimo' => 'min', 'valor_maximo' => 'max'];
        foreach (FormularioDePedido::reglas() as $campo => $reglas) {
            $control = $formulario->querySelector("[name=\"{$campo}\"]");
            self::assertNotNull($control, $campo);
            self::assertSame($reglas['requerido'], $control->hasAttribute('required'), $campo);
            foreach ($atributos as $regla => $atributo) {
                self::assertSame(
                    isset($reglas[$regla]) ? (string) $reglas[$regla] : null,
                    $control->getAttribute($atributo),
                    "{$campo}: {$atributo}",
                );
            }
        }
    }

    /**
     * @return iterable<string, array{array<string, string>, string, string}>
     */
    public static function camposInvalidos(): iterable
    {
        yield 'sin título' => [['titulo' => '', 'anio' => '2001', 'director' => 'Lucrecia Martel'], 'titulo', 'Falta el título.'];
        yield 'título mayor a 150' => [['titulo' => str_repeat('a', 151), 'anio' => '2001', 'director' => 'Lucrecia Martel'], 'titulo', 'El título puede tener hasta 150 caracteres.'];
        yield 'año previo a 1888' => [['titulo' => 'La ciénaga', 'anio' => '1887', 'director' => 'Lucrecia Martel'], 'anio', 'El año tiene que estar entre 1888 y el actual.'];
        yield 'año futuro' => [['titulo' => 'La ciénaga', 'anio' => (string) (((int) date('Y')) + 1), 'director' => 'Lucrecia Martel'], 'anio', 'El año tiene que estar entre 1888 y el actual.'];
        yield 'sin director' => [['titulo' => 'La ciénaga', 'anio' => '2001', 'director' => '   '], 'director', 'Falta la dirección.'];
        yield 'director mayor a 100' => [['titulo' => 'La ciénaga', 'anio' => '2001', 'director' => str_repeat('d', 101)], 'director', 'La dirección puede tener hasta 100 caracteres.'];
    }

    /**
     * @param array<string, string> $campos
     */
    #[DataProvider('camposInvalidos')]
    public function testDevuelve422AnteDatosInvalidosYSinGuardar(array $campos, string $campo, string $errorEsperado): void
    {
        $respuesta = $this->pedir('POST', '/pedidos/nuevo', $campos);

        self::assertSame(422, $respuesta->estado());
        self::assertSame(["#{$campo}" => $errorEsperado], Formularios::resumen($respuesta->cuerpo())['enlaces'] ?? null);
        self::assertMatchesRegularExpression('#id="error-' . $campo . '"[^>]*>.*' . preg_quote($errorEsperado, '#') . '#s', $respuesta->cuerpo());
        self::assertSame(0, (int) $this->base->valor('SELECT COUNT(*) FROM pedido_titulo'));
    }

    public function testConErroresVuelveConLoCargadoYEscapado(): void
    {
        $respuesta = $this->pedir('POST', '/pedidos/nuevo', [
            'titulo' => '<script>alert(1)</script>',
            'anio' => '1800',
            'director' => 'Director',
        ]);

        self::assertSame(422, $respuesta->estado());
        self::assertStringContainsString('value="&lt;script&gt;alert(1)&lt;/script&gt;"', $respuesta->cuerpo());
        self::assertStringNotContainsString('<script>alert(1)', $respuesta->cuerpo());
    }

    public function testExigeSesionYTokenCsrf(): void
    {
        self::assertSame(401, $this->pedir('GET', '/pedidos/nuevo', rol: null)->estado());
        self::assertSame(401, $this->pedir('POST', '/pedidos/nuevo', ['titulo' => 'X', 'anio' => '2001', 'director' => 'Y'], rol: null)->estado());
        self::assertSame(403, $this->pedir('POST', '/pedidos/nuevo', ['titulo' => 'X', 'anio' => '2001', 'director' => 'Y'], conCsrf: false)->estado());
        self::assertSame(0, (int) $this->base->valor('SELECT COUNT(*) FROM pedido_titulo'));
    }

    public function testCreaPedidoConAdhesionInicialYDetectaParecidosSinTildesNiMayusculas(): void
    {
        $alta = $this->pedir('POST', '/pedidos/nuevo', [
            'titulo' => 'La ciénaga',
            'anio' => '2001',
            'director' => 'Lucrecia Martel',
        ]);

        self::assertSame(303, $alta->estado());
        $ubicacion = (string) $alta->cabecera('Location');
        self::assertMatchesRegularExpression('#^/pedidos/nuevo\?pedido=[0-9a-f-]{36}&creado=1$#', $ubicacion);
        $pedidoId = (string) $this->base->valor('SELECT id FROM pedido_titulo');
        self::assertSame(1, (int) $this->base->valor('SELECT COUNT(*) FROM adhesion_pedido WHERE pedido_id = ? AND usuario_id = ?', [$pedidoId, self::ESPECTADORA]));

        $confirmacion = $this->pedir('GET', $ubicacion);
        self::assertSame(200, $confirmacion->estado());
        self::assertStringContainsString('Tu pedido quedó registrado con tu adhesión.', $confirmacion->cuerpo());
        self::assertStringContainsString('La ciénaga (2001)', $confirmacion->cuerpo());
        self::assertStringContainsString('Dejar de pedirlo', $confirmacion->cuerpo());

        // Otro espectador pide lo mismo sin tildes ni mayúsculas: no duplica y ofrece «Ya lo pidieron».
        $parecido = $this->pedir('POST', '/pedidos/nuevo', [
            'titulo' => 'la cienaga',
            'anio' => '2001',
            'director' => 'Lucrecia Martel',
        ], usuario: self::OTRO_ESPECTADOR);

        self::assertSame(200, $parecido->estado());
        self::assertSame(1, (int) $this->base->valor('SELECT COUNT(*) FROM pedido_titulo'));
        self::assertStringContainsString('Ya lo pidieron', $parecido->cuerpo());
        self::assertStringContainsString("action=\"/pedidos/{$pedidoId}/sumarme\"", $parecido->cuerpo());
        self::assertStringContainsString('Sumarme', $parecido->cuerpo());
    }

    public function testSoloUnaAdhesionPorUsuarioYSePuedeDejarDePedirlo(): void
    {
        $this->pedir('POST', '/pedidos/nuevo', [
            'titulo' => 'Historias extraordinarias',
            'anio' => '2008',
            'director' => 'Mariano Llinás',
        ]);
        $pedidoId = (string) $this->base->valor('SELECT id FROM pedido_titulo');

        // Un segundo espectador se suma.
        $sumarse = $this->pedir('POST', "/pedidos/{$pedidoId}/sumarme", usuario: self::OTRO_ESPECTADOR);
        self::assertSame(303, $sumarse->estado());
        self::assertSame("/pedidos/nuevo?pedido={$pedidoId}&sumado=1", $sumarse->cabecera('Location'));
        self::assertSame(2, (int) $this->base->valor('SELECT COUNT(*) FROM adhesion_pedido WHERE pedido_id = ?', [$pedidoId]));

        // Si intenta sumarse otra vez (p. ej. desde dos pestañas), la clave única lo frena sin dar 500.
        $repetidoHtml = $this->pedir('POST', "/pedidos/{$pedidoId}/sumarme", usuario: self::OTRO_ESPECTADOR);
        self::assertSame(200, $repetidoHtml->estado());
        self::assertStringContainsString('Ya te sumaste a este pedido.', $repetidoHtml->cuerpo());
        self::assertSame(2, (int) $this->base->valor('SELECT COUNT(*) FROM adhesion_pedido WHERE pedido_id = ?', [$pedidoId]));

        $repetidoJson = $this->pedir('POST', "/pedidos/{$pedidoId}/sumarme", usuario: self::OTRO_ESPECTADOR, json: true);
        self::assertSame(409, $repetidoJson->estado());
        self::assertStringContainsString('Ya te sumaste a este pedido.', $repetidoJson->cuerpo());
        self::assertSame(2, (int) $this->base->valor('SELECT COUNT(*) FROM adhesion_pedido WHERE pedido_id = ?', [$pedidoId]));

        // Dejar de pedirlo con quitar=1 quita su adhesión y le permite volver a sumarse después.
        $quitar = $this->pedir('POST', "/pedidos/{$pedidoId}/sumarme", ['quitar' => '1'], usuario: self::OTRO_ESPECTADOR);
        self::assertSame(303, $quitar->estado());
        self::assertSame("/pedidos/nuevo?pedido={$pedidoId}&quitado=1", $quitar->cabecera('Location'));
        self::assertSame(1, (int) $this->base->valor('SELECT COUNT(*) FROM adhesion_pedido WHERE pedido_id = ?', [$pedidoId]));

        $volverASumar = $this->pedir('POST', "/pedidos/{$pedidoId}/sumarme", usuario: self::OTRO_ESPECTADOR, json: true);
        self::assertSame(200, $volverASumar->estado());
        self::assertSame(2, (int) $this->base->valor('SELECT COUNT(*) FROM adhesion_pedido WHERE pedido_id = ?', [$pedidoId]));

        // Ruta dedicada /pedidos/{id}/quitar también funciona en JSON.
        $quitarDirecto = $this->pedir('POST', "/pedidos/{$pedidoId}/quitar", usuario: self::OTRO_ESPECTADOR, json: true);
        self::assertSame(200, $quitarDirecto->estado());
        self::assertSame(1, (int) $this->base->valor('SELECT COUNT(*) FROM adhesion_pedido WHERE pedido_id = ?', [$pedidoId]));
    }

    public function testElLogNoGuardaDatosPersonales(): void
    {
        $this->pedir('POST', '/pedidos/nuevo', [
            'titulo' => 'Los rubios',
            'anio' => '2003',
            'director' => 'Albertina Carri',
        ]);

        self::assertStringContainsString(self::ESPECTADORA, $this->log());
        self::assertStringNotContainsString('Eva Prueba', $this->log());
    }

    public function testRankingOrdenaPorAdhesionesFiltraPaginaYPasaAActivoConElUmbral(): void
    {
        $tercerUsuario = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';

        // Tres pedidos; «Pizza, birra, faso» recibirá 3 adhesiones y alcanzará el umbral=3.
        $this->pedir('POST', '/pedidos/nuevo', ['titulo' => 'Mundo grúa', 'anio' => '1999', 'director' => 'Pablo Trapero'], umbral: 3);
        $mundoGruaId = (string) $this->base->valor("SELECT id FROM pedido_titulo WHERE titulo = 'Mundo grúa'");

        $this->pedir('POST', '/pedidos/nuevo', ['titulo' => 'Pizza, birra, faso', 'anio' => '1998', 'director' => 'Adrián Caetano'], umbral: 3);
        $pizzaId = (string) $this->base->valor("SELECT id FROM pedido_titulo WHERE titulo = 'Pizza, birra, faso'");

        $this->pedir('POST', '/pedidos/nuevo', ['titulo' => 'Nueve reinas', 'anio' => '2000', 'director' => 'Fabián Bielinsky'], umbral: 3);
        $nueveReinasId = (string) $this->base->valor("SELECT id FROM pedido_titulo WHERE titulo = 'Nueve reinas'");

        // «Nueve reinas» queda con 2 adhesiones (sigue abierto con umbral=3).
        $this->pedir('POST', "/pedidos/{$nueveReinasId}/sumarme", ['origen' => 'ranking'], usuario: self::OTRO_ESPECTADOR, umbral: 3);
        self::assertSame('abierto', (string) $this->base->valor('SELECT estado FROM pedido_titulo WHERE id = ?', [$nueveReinasId]));

        // «Pizza, birra, faso» suma 2da adhesión (sigue abierto) y con la 3ra pasa a «activo» en la misma transacción.
        $this->pedir('POST', "/pedidos/{$pizzaId}/sumarme", ['origen' => 'ranking'], usuario: self::OTRO_ESPECTADOR, umbral: 3);
        self::assertSame('abierto', (string) $this->base->valor('SELECT estado FROM pedido_titulo WHERE id = ?', [$pizzaId]));
        self::assertNull($this->base->valor('SELECT activado_en FROM pedido_titulo WHERE id = ?', [$pizzaId]));

        $tercera = $this->pedir('POST', "/pedidos/{$pizzaId}/sumarme", ['origen' => 'ranking'], usuario: $tercerUsuario, umbral: 3);
        self::assertSame(303, $tercera->estado());
        self::assertSame('/pedidos?sumado=1', $tercera->cabecera('Location'));
        self::assertSame('activo', (string) $this->base->valor('SELECT estado FROM pedido_titulo WHERE id = ?', [$pizzaId]));
        self::assertNotNull($this->base->valor('SELECT activado_en FROM pedido_titulo WHERE id = ?', [$pizzaId]));

        // Ranking público sin sesión: ordenado 3 -> 2 -> 1 y con la marca «Activo».
        $rankingJson = $this->pedir('GET', '/pedidos', rol: null, json: true, umbral: 3, porPagina: 2);
        self::assertSame(200, $rankingJson->estado());
        self::assertStringContainsString('Accept', (string) $rankingJson->cabecera('Vary'));
        $datos = json_decode($rankingJson->cuerpo(), true);
        self::assertSame([$pizzaId, $nueveReinasId], array_column($datos['pedidos'], 'id'));
        self::assertTrue($datos['pedidos'][0]['es_activo']);
        self::assertFalse($datos['pedidos'][1]['es_activo']);
        self::assertSame(2, $datos['paginado']['total_paginas']);

        // Segunda página trae el tercero.
        $pagina2 = $this->pedir('GET', '/pedidos?pagina=2', rol: null, json: true, umbral: 3, porPagina: 2);
        self::assertSame(200, $pagina2->estado());
        $datos2 = json_decode($pagina2->cuerpo(), true);
        self::assertSame([$mundoGruaId], array_column($datos2['pedidos'], 'id'));

        // Ranking HTML muestra los resultados, el paginado, el buscador y la marca «Activo».
        $html = $this->pedir('GET', '/pedidos?sumado=1', rol: 'espectador', umbral: 3, porPagina: 2);
        self::assertSame(200, $html->estado());
        self::assertSaltaAlContenido($html->cuerpo());
        self::assertStringContainsString('Te sumaste al pedido.', $html->cuerpo());
        self::assertStringContainsString('Pizza, birra, faso', $html->cuerpo());
        self::assertStringContainsString('Activo', $html->cuerpo());
        self::assertStringContainsString('Dejar de pedirlo', $html->cuerpo());
        self::assertStringContainsString('?pagina=2', $html->cuerpo());

        // Buscador ?q= filtra por título o director.
        $busqueda = $this->pedir('GET', '/pedidos?q=Trapero', rol: null, json: true, umbral: 3);
        $datosBusqueda = json_decode($busqueda->cuerpo(), true);
        self::assertSame([$mundoGruaId], array_column($datosBusqueda['pedidos'], 'id'));

        // Páginas inválidas o fuera de rango devuelven 404 tanto en HTML como en JSON.
        self::assertSame(404, $this->pedir('GET', '/pedidos?pagina=0', rol: null)->estado());
        self::assertSame(404, $this->pedir('GET', '/pedidos?pagina=abc', rol: null, json: true)->estado());
        self::assertSame(404, $this->pedir('GET', '/pedidos?pagina=9', rol: null, json: true, porPagina: 2)->estado());
    }

    public function testPedidoTomadoSaleDelRankingYEnlazaALaObraEnElCatalogo(): void
    {
        $this->pedir('POST', '/pedidos/nuevo', ['titulo' => 'La ciénaga', 'anio' => '2001', 'director' => 'Lucrecia Martel']);
        $this->pedir('POST', '/pedidos/nuevo', ['titulo' => 'Zama', 'anio' => '2017', 'director' => 'Lucrecia Martel']);
        $tomadoId = (string) $this->base->valor("SELECT id FROM pedido_titulo WHERE titulo = 'La ciénaga'");
        $activoId = (string) $this->base->valor("SELECT id FROM pedido_titulo WHERE titulo = 'Zama'");

        $obraId = Uuid::nuevo();
        $this->base->ejecutar(
            "INSERT INTO obra (id, dueno_id, titulo, tipo, sinopsis, anio, director, duracion, origen_copia)
             VALUES (?, ?, 'La ciénaga', 'pelicula', 'Sinopsis de prueba.', 2001, 'Lucrecia Martel', 103, 'licencia_adquirida')",
            [$obraId, self::OTRO_ESPECTADOR],
        );
        $this->base->ejecutar(
            "UPDATE pedido_titulo SET estado = 'tomado', obra_id = ?, tomado_por = ?, tomado_en = UTC_TIMESTAMP() WHERE id = ?",
            [$obraId, self::OTRO_ESPECTADOR, $tomadoId],
        );

        $json = $this->pedir('GET', '/pedidos', rol: null, json: true);
        $datos = json_decode($json->cuerpo(), true);
        self::assertSame([$activoId], array_column($datos['pedidos'], 'id'));
        self::assertSame([$tomadoId], array_column($datos['tomados'], 'id'));
        self::assertSame("/obras/{$obraId}", $datos['tomados'][0]['url_obra']);

        $html = $this->pedir('GET', '/pedidos', rol: null);
        self::assertSame(200, $html->estado());
        self::assertStringContainsString("href=\"/obras/{$obraId}\"", $html->cuerpo());
        self::assertStringContainsString('Títulos pedidos que ya están en el catálogo', $html->cuerpo());
    }
}
