<?php

declare(strict_types=1);

namespace IndieCinema\Nucleo\Pruebas\Csrf;

use IndieCinema\Nucleo\Aplicacion;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Log;
use IndieCinema\Nucleo\Ruteo\Router;
use IndieCinema\Nucleo\Ruteo\Ruta;
use IndieCinema\Nucleo\Seguridad\ProteccionCsrf;
use IndieCinema\Nucleo\Seguridad\Rol;
use IndieCinema\Nucleo\Uuid;

/**
 * Que ninguna ruta que cambie datos pase sin el token CSRF (E4-11.1), en el subsistema que use
 * este trait. No se escribe ruta por ruta: se recorren las que declara su rutas.php, así una ruta
 * nueva queda cubierta el día que se agrega y no el día que alguien se acuerda de probarla.
 *
 * El pedido va con la sesión que corresponde al rol mínimo de cada ruta, porque el control de
 * acceso corre antes que el del token: sin sesión, un POST a una ruta del panel se rechazaría por
 * el rol y la prueba no estaría probando nada.
 *
 * Se prueban las dos formas de mandar el token, que son las dos que acepta ProteccionCsrf: el
 * campo oculto de un formulario y la cabecera X-CSRF-Token del JavaScript. Sin ninguna de las
 * dos, las dos tienen que dar 403.
 *
 * Y una tercera prueba manda el token bien, para que las otras dos no pasen por el motivo
 * equivocado: lo que tiene que cambiar es el token y nada más.
 *
 * No necesita la base: el token se revisa antes de llegar al controlador.
 *
 * Quien lo use declara de dónde salen sus rutas y con qué configuración se arma su aplicación.
 */
trait CompruebaElCsrfDeTodaRuta
{
    /** El que nginx le pone a los pedidos que pasa: sin él no hay sesión. */
    private const SECRETO_PARA_EL_CSRF = 'secreto-de-nginx';

    /** 64 caracteres hexadecimales, que es lo que ProteccionCsrf da por válido. */
    private const TOKEN_PARA_EL_CSRF = 'abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789';

    private const USUARIO_PARA_EL_CSRF = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    /** El de ExcepcionHttp::csrfInvalido(), para no confundirlo con otro 403. */
    private const MENSAJE_DEL_CSRF = 'La página venció. Volvé a cargarla y probá de nuevo.';

    /** La carpeta del subsistema, donde está su rutas.php. */
    abstract protected static function raizDelSubsistema(): string;

    /**
     * La configuración mínima con la que se arma su aplicación.
     *
     * @return array<string, array<string, string>>
     */
    abstract protected static function configuracionDelSubsistema(): array;

    public function testNingunaRutaQueCambiaDatosPasaSinElTokenCsrf(): void
    {
        $rutas = self::rutasQueCambianDatos();

        // Si el filtro dejara la lista vacía, las dos pruebas de abajo pasarían sin probar nada.
        self::assertNotEmpty($rutas);
        foreach ($rutas as $ruta) {
            $respuesta = $this->pedirSinToken($ruta);

            self::assertSame(403, $respuesta->estado(), "{$ruta->metodo} {$ruta->patron}");
            self::assertStringContainsString(self::MENSAJE_DEL_CSRF, $respuesta->cuerpo(), "{$ruta->metodo} {$ruta->patron}");
        }
    }

    public function testTampocoPasaUnPedidoDeFetchSinElToken(): void
    {
        foreach (self::rutasQueCambianDatos() as $ruta) {
            // Como lo manda el JavaScript: pide JSON y el token iría en la cabecera, no en el cuerpo.
            $respuesta = $this->pedirSinToken($ruta, ['Accept' => 'application/json']);

            self::assertSame(403, $respuesta->estado(), "{$ruta->metodo} {$ruta->patron}");
        }
    }

    public function testConElTokenElRechazoYaNoEsElDelCsrf(): void
    {
        foreach (self::rutasQueCambianDatos() as $ruta) {
            // Por el campo del formulario y por la cabecera, que son las dos que acepta.
            $conCampo = $this->pedir($ruta, [ProteccionCsrf::CAMPO => self::TOKEN_PARA_EL_CSRF], []);
            $conCabecera = $this->pedir($ruta, [], [ProteccionCsrf::CABECERA => self::TOKEN_PARA_EL_CSRF]);

            // Qué contesta después depende del controlador y de la base, que acá no hay: lo único
            // que se afirma es que el token dejó de ser el motivo.
            foreach ([$conCampo, $conCabecera] as $respuesta) {
                self::assertStringNotContainsString(self::MENSAJE_DEL_CSRF, $respuesta->cuerpo(), "{$ruta->metodo} {$ruta->patron}");
            }
        }
    }

    /**
     * Las que cambian datos: todo lo que no sea GET, HEAD ni OPTIONS. Las /interno/ quedan afuera
     * porque no las llama un navegador sino otro subsistema, con su token de servicio, y
     * ProteccionCsrf las saltea a propósito.
     *
     * @return list<Ruta>
     */
    private static function rutasQueCambianDatos(): array
    {
        $router = new Router();
        (require static::raizDelSubsistema() . '/rutas.php')($router);

        return array_values(array_filter(
            $router->rutas(),
            static fn (Ruta $ruta): bool => !in_array($ruta->metodo, ['GET', 'HEAD', 'OPTIONS'], true) && !$ruta->esInterna(),
        ));
    }

    /**
     * @param array<string, string> $cabeceras las que se agregan a las de la sesión
     */
    private function pedirSinToken(Ruta $ruta, array $cabeceras = []): Respuesta
    {
        return $this->pedir($ruta, [], $cabeceras);
    }

    /**
     * El pedido a la ruta, con un id cualquiera en cada parámetro y con la sesión que pide su rol
     * mínimo. La cookie del token está siempre: lo que cambia es si el pedido lo devuelve.
     *
     * @param array<string, string> $cuerpo
     * @param array<string, string> $cabeceras
     */
    private function pedir(Ruta $ruta, array $cuerpo, array $cabeceras): Respuesta
    {
        $sesion = $ruta->rolMinimo === Rol::Visitante ? [] : [
            'X-Nginx-Secreto' => self::SECRETO_PARA_EL_CSRF,
            'X-Usuario-Id' => self::USUARIO_PARA_EL_CSRF,
            'X-Rol' => $ruta->rolMinimo->value,
        ];
        $url = (string) preg_replace_callback('/\{\w+\}/', static fn (): string => Uuid::nuevo(), $ruta->patron);
        $aplicacion = new Aplicacion(
            static::raizDelSubsistema(),
            new Configuracion(static::configuracionDelSubsistema()),
            new Log('pruebas', fopen('php://memory', 'w')),
        );

        return $aplicacion->atender(new Peticion(
            $ruta->metodo,
            $url,
            [],
            $cuerpo,
            [...$sesion, ...$cabeceras],
            [ProteccionCsrf::COOKIE => self::TOKEN_PARA_EL_CSRF],
        ));
    }
}
