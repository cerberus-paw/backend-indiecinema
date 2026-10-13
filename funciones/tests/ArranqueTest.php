<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Pruebas;

use IndieCinema\Nucleo\Aplicacion;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Log;
use PHPUnit\Framework\TestCase;

/**
 * Funciones arranca con el núcleo, sus rutas y el paquete front, con la configuración del
 * ejemplo: el mismo archivo que se copia a config.ini. Todavía no tiene pantallas, así que alcanza
 * con la API interna y la página de error.
 */
final class ArranqueTest extends TestCase
{
    private const TOKEN_DE_EJEMPLO = 'cambiar';
    private const SECRETO_DE_EJEMPLO = 'cambiar';

    /**
     * @param array<string, string> $cabeceras
     */
    private function pedir(string $ruta, array $cabeceras = []): Respuesta
    {
        $configuracion = Configuracion::desdeArchivo(dirname(__DIR__) . '/config/config.ejemplo.ini');
        $aplicacion = new Aplicacion(dirname(__DIR__), $configuracion, new Log('funciones', fopen('php://memory', 'w')));

        return $aplicacion->atender(new Peticion('GET', $ruta, [], [], $cabeceras, [], []));
    }

    public function testRespondeAlEstadoInternoConElTokenDeUnSubsistemaQueLoLlama(): void
    {
        $respuesta = $this->pedir('/interno/estado', [
            'X-Llamador' => 'programacion',
            'Authorization' => 'Bearer ' . self::TOKEN_DE_EJEMPLO,
        ]);

        self::assertSame(200, $respuesta->estado(), $respuesta->cuerpo());
        self::assertSame('funciones', json_decode($respuesta->cuerpo(), true)['subsistema'] ?? null);
    }

    public function testSinTokenOConUnLlamadorQueNoEstaNoResponde(): void
    {
        self::assertSame(403, $this->pedir('/interno/estado')->estado());
        self::assertSame(403, $this->pedir('/interno/estado', [
            'X-Llamador' => 'nginx',
            'Authorization' => 'Bearer ' . self::TOKEN_DE_EJEMPLO,
        ])->estado());
    }

    /**
     * Sin pantallas todavía: la portada del subsistema es la página de error del sitio, con el
     * encabezado y el pie del paquete front.
     */
    public function testSinPantallasRespondeLaPaginaDeErrorDelSitio(): void
    {
        $respuesta = $this->pedir('/');

        self::assertSame(404, $respuesta->estado());
        self::assertStringContainsString('<header class="encabezado', $respuesta->cuerpo());
        self::assertStringContainsString('<footer', $respuesta->cuerpo());
    }

    /**
     * La página llega con la sesión que verificó nginx: el encabezado muestra al usuario. Sin el
     * secreto de nginx, las mismas cabeceras no se creen y llega como visitante.
     */
    public function testLaPaginaMuestraLaSesionQueMandaNginx(): void
    {
        $sesion = [
            'X-Usuario-Id' => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',
            'X-Rol' => 'espectador',
            'X-Usuario-Nombre' => rawurlencode('Eva Prueba'),
        ];

        $conSesion = $this->pedir('/', ['X-Nginx-Secreto' => self::SECRETO_DE_EJEMPLO] + $sesion)->cuerpo();
        $sinElSecreto = $this->pedir('/', $sesion)->cuerpo();

        self::assertStringContainsString('Eva Prueba', $conSesion);
        self::assertStringNotContainsString('href="/cuenta/ingresar"', $conSesion);
        self::assertStringNotContainsString('Eva Prueba', $sinElSecreto);
        self::assertStringContainsString('href="/cuenta/ingresar"', $sinElSecreto);
    }
}
