<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Pruebas;

use IndieCinema\Cuentas\Modelo\EstadoCuenta;
use IndieCinema\Cuentas\Modelo\PropositoDeToken;
use IndieCinema\Cuentas\Modelo\Usuario;
use IndieCinema\Cuentas\Repositorios\RepositorioDeSesiones;
use IndieCinema\Cuentas\Repositorios\RepositorioDeTokens;
use IndieCinema\Cuentas\Repositorios\RepositorioDeUsuarios;
use IndieCinema\Cuentas\Servicios\ServicioDeSesiones;
use IndieCinema\Cuentas\Servicios\ServicioDeTokens;
use IndieCinema\Nucleo\Aplicacion;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Log;
use IndieCinema\Nucleo\Pruebas\Dobles\PaginasParaValidar;
use IndieCinema\Nucleo\Pruebas\Dobles\ServidorSmtpDePrueba;
use IndieCinema\Nucleo\Seguridad\Rol;
use IndieCinema\Nucleo\Uuid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * «Mi cuenta» (/cuenta/, /cuenta/datos y /cuenta/contrasena) de punta a punta contra MySQL real.
 * «Mi solicitud de sala» (EF-07.6) se la pide a una moderación de mentira, por la red.
 */
final class MiCuentaTest extends TestCase
{
    use ConBaseDePruebas;
    use ConModeracionDePrueba;

    private const SECRETO = 'secreto-de-nginx';
    private const CSRF = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

    private static ServidorSmtpDePrueba $correo;

    private Configuracion $configuracion;
    private BaseDeDatos $base;

    public static function setUpBeforeClass(): void
    {
        self::iniciarModeracion();
        self::$correo = ServidorSmtpDePrueba::iniciar();
    }

    public static function tearDownAfterClass(): void
    {
        self::detenerModeracion();
        self::$correo->detener();
    }

    protected function setUp(): void
    {
        self::reiniciarModeracion();
        self::$correo->vaciar();
        $this->configuracion = new Configuracion([
            'app' => ['subsistema' => 'cuentas', 'prefijo' => '/cuenta', 'entorno' => 'desarrollo'],
            'base' => self::baseDePruebas(),
            'nginx' => ['secreto' => self::SECRETO],
            'subsistemas' => ['moderacion' => self::$urlDeModeracion],
            'interno' => ['token' => 'token-de-cuentas'],
            'sitio' => ['url' => 'https://indiecinema.test'],
            'correo' => ['servidor' => self::$correo->url(), 'remitente' => 'no-responder@indiecinema.test', 'nombre' => 'IndieCinema'],
        ]);
        $this->base = self::baseConTablasNuevas($this->configuracion);
    }

    /**
     * Una cuenta creada en marzo de 2025, con espectador y los roles que se pidan.
     *
     * @param list<Rol> $roles además de espectador
     */
    private function agregar(array $roles = [], bool $verificado = true, string $correo = 'eva@ejemplo.com'): string
    {
        $id = Uuid::nuevo();
        (new RepositorioDeUsuarios($this->base))->agregar(
            new Usuario(
                $id,
                'Eva Ramos',
                $correo,
                password_hash('Clave 2026', PASSWORD_ARGON2ID),
                EstadoCuenta::Activa,
                [Rol::Espectador, ...$roles],
                $verificado ? new \DateTimeImmutable('2025-03-10 18:30:00', new \DateTimeZone('UTC')) : null,
            ),
        );
        $this->base->ejecutar("UPDATE usuario SET creado_en = '2025-03-10 18:30:00' WHERE id = ?", [$id]);

        return $id;
    }

    /**
     * Como llega de nginx: con la sesión, el nombre que muestra el encabezado y el mayor rol.
     *
     * @param array<string, mixed> $cuerpo
     * @param array<string, string> $cookies
     */
    private function pedir(
        ?string $id,
        string $rol = 'espectador',
        string $metodo = 'GET',
        string $ruta = '/',
        array $cuerpo = [],
        array $cookies = [],
    ): Respuesta {
        $cabeceras = $id === null ? [] : [
            'X-Nginx-Secreto' => self::SECRETO,
            'X-Usuario-Id' => $id,
            'X-Rol' => $rol,
            'X-Usuario-Nombre' => rawurlencode('Eva'),
            'X-Correo-Verificado' => '1',
        ];
        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion, new Log('cuentas', fopen('php://memory', 'w')));
        parse_str((string) parse_url($ruta, PHP_URL_QUERY), $consulta);

        return PaginasParaValidar::anotar(
            $metodo,
            "/cuenta{$ruta}",
            $aplicacion->atender(new Peticion(
                $metodo,
                (string) parse_url($ruta, PHP_URL_PATH),
                $consulta,
                $cuerpo,
                $cabeceras,
                $cookies,
                [],
            )),
        );
    }

    /**
     * El contenido de la página, sin el encabezado ni el pie.
     */
    private static function contenido(Respuesta $respuesta): string
    {
        self::assertSame(200, $respuesta->estado(), $respuesta->cuerpo());
        preg_match('#<main.*?</main>#s', $respuesta->cuerpo(), $contenido);

        return $contenido[0] ?? '';
    }

    /**
     * La barra lateral de «Mi cuenta».
     */
    private static function barra(Respuesta $respuesta): string
    {
        preg_match('#<nav[^>]*aria-label="Mi cuenta".*?</nav>#s', self::contenido($respuesta), $barra);

        return $barra[0] ?? '';
    }

    public function testMuestraElNombreElCorreoLosRolesYDesdeCuandoTieneLaCuenta(): void
    {
        $contenido = self::contenido($this->pedir($this->agregar()));

        self::assertStringContainsString('Eva Ramos', $contenido);
        self::assertStringContainsString('eva@ejemplo.com', $contenido);
        self::assertStringContainsString('Desde marzo de 2025', $contenido);
        self::assertStringContainsString('Espectador', $contenido);
        self::assertStringNotContainsString('Organizador', $contenido);
    }

    public function testMuestraTodosLosRolesDeLaCuenta(): void
    {
        $contenido = self::contenido($this->pedir($this->agregar([Rol::Organizador]), 'organizador'));

        self::assertStringContainsString('Espectador', $contenido);
        self::assertStringContainsString('Organizador', $contenido);
    }

    public function testEnlazaAEditarDatosYCambiarContrasena(): void
    {
        $contenido = self::contenido($this->pedir($this->agregar()));

        self::assertStringContainsString('href="/cuenta/datos"', $contenido);
        self::assertStringContainsString('href="/cuenta/contrasena"', $contenido);
        self::assertStringNotContainsString('<form', $contenido);
    }

    public function testEditarNombreValidaYActualizaSinPedirContrasenaNiCerrarSesiones(): void
    {
        $id = $this->agregar();
        $sesiones = new ServicioDeSesiones(new RepositorioDeSesiones($this->base));
        $actual = $sesiones->abrir($id)->identificador;
        $otra = $sesiones->abrir($id)->identificador;

        $pantalla = $this->pedir($id, ruta: '/datos');
        self::assertSame(200, $pantalla->estado());
        PaginasParaValidar::guardar('editar-datos', $pantalla->cuerpo());
        self::assertStringContainsString('value="Eva Ramos"', $pantalla->cuerpo());
        self::assertStringContainsString('value="eva@ejemplo.com"', $pantalla->cuerpo());

        // Protegido con CSRF.
        self::assertSame(403, $this->pedir($id, metodo: 'POST', ruta: '/datos', cuerpo: ['nombre' => 'Nuevo', 'email' => 'eva@ejemplo.com'])->estado());

        // Validación en el servidor.
        $invalido = $this->pedir(
            $id,
            metodo: 'POST',
            ruta: '/datos',
            cuerpo: ['_csrf' => self::CSRF, 'nombre' => '', 'email' => 'mal-correo'],
            cookies: ['csrf' => self::CSRF, 'sesion' => $actual],
        );
        self::assertSame(422, $invalido->estado());
        self::assertStringContainsString('Falta el nombre.', $invalido->cuerpo());
        self::assertStringContainsString('Escribí un correo válido', $invalido->cuerpo());

        // Cambiar sólo el nombre funciona sin contraseña actual ni cerrar sesiones.
        $guardado = $this->pedir(
            $id,
            metodo: 'POST',
            ruta: '/datos',
            cuerpo: ['_csrf' => self::CSRF, 'nombre' => 'Eva Modificada', 'email' => 'eva@ejemplo.com', 'contrasena_actual' => ''],
            cookies: ['csrf' => self::CSRF, 'sesion' => $actual],
        );
        self::assertSame(303, $guardado->estado());
        self::assertSame('/cuenta/datos?guardados=1', $guardado->cabecera('Location'));
        self::assertSame('Eva Modificada', $this->base->valor('SELECT nombre FROM usuario WHERE id = ?', [$id]));
        self::assertNotNull($sesiones->validar($otra));
    }

    public function testCambiarCorreoExigeContrasenaActualEvitaDuplicadosDesverificaYCierraLasDemasSesiones(): void
    {
        $id = $this->agregar(correo: 'eva@ejemplo.com');
        $this->agregar(correo: 'ocupado@ejemplo.com');
        $tokens = new ServicioDeTokens(new RepositorioDeTokens($this->base), new RepositorioDeUsuarios($this->base));
        $tokenViejo = $tokens->emitir($id, PropositoDeToken::Verificar, 'PT24H');

        $sesiones = new ServicioDeSesiones(new RepositorioDeSesiones($this->base));
        $actual = $sesiones->abrir($id)->identificador;
        $otra = $sesiones->abrir($id)->identificador;

        // Sin contraseña actual o incorrecta no cambia el correo.
        $sinClave = $this->pedir(
            $id,
            metodo: 'POST',
            ruta: '/datos',
            cuerpo: ['_csrf' => self::CSRF, 'nombre' => 'Eva Ramos', 'email' => 'eva.nueva@ejemplo.com', 'contrasena_actual' => ''],
            cookies: ['csrf' => self::CSRF, 'sesion' => $actual],
        );
        self::assertSame(422, $sinClave->estado());
        self::assertStringContainsString('Ingresá tu contraseña actual para cambiar el correo.', $sinClave->cuerpo());

        $claveMal = $this->pedir(
            $id,
            metodo: 'POST',
            ruta: '/datos',
            cuerpo: ['_csrf' => self::CSRF, 'nombre' => 'Eva Ramos', 'email' => 'eva.nueva@ejemplo.com', 'contrasena_actual' => 'otra-clave'],
            cookies: ['csrf' => self::CSRF, 'sesion' => $actual],
        );
        self::assertSame(422, $claveMal->estado());
        self::assertStringContainsString('La contraseña actual no es correcta.', $claveMal->cuerpo());

        // Correo ya registrado por otra cuenta.
        $duplicado = $this->pedir(
            $id,
            metodo: 'POST',
            ruta: '/datos',
            cuerpo: ['_csrf' => self::CSRF, 'nombre' => 'Eva Ramos', 'email' => 'ocupado@ejemplo.com', 'contrasena_actual' => 'Clave 2026'],
            cookies: ['csrf' => self::CSRF, 'sesion' => $actual],
        );
        self::assertSame(422, $duplicado->estado());
        self::assertStringContainsString('Ya hay una cuenta con este correo.', $duplicado->cuerpo());

        // Cambio válido: guarda correo, vuelve a «sin verificar», invalida token anterior, envía enlace nuevo y cierra la otra sesión.
        $ok = $this->pedir(
            $id,
            metodo: 'POST',
            ruta: '/datos',
            cuerpo: ['_csrf' => self::CSRF, 'nombre' => 'Eva Ramos', 'email' => 'eva.nueva@ejemplo.com', 'contrasena_actual' => 'Clave 2026'],
            cookies: ['csrf' => self::CSRF, 'sesion' => $actual],
        );
        self::assertSame(303, $ok->estado());
        self::assertSame('eva.nueva@ejemplo.com', $this->base->valor('SELECT correo FROM usuario WHERE id = ?', [$id]));
        self::assertNull($this->base->valor('SELECT correo_verificado_en FROM usuario WHERE id = ?', [$id]));
        self::assertNotNull($this->base->valor('SELECT usado_en FROM token_de_cuenta WHERE huella = ?', [hash('sha256', $tokenViejo)]));
        self::assertCount(1, self::$correo->correos());
        self::assertSame(['<eva.nueva@ejemplo.com>'], self::$correo->correos()[0]['para']);
        self::assertNotNull($sesiones->validar($actual));
        self::assertNull($sesiones->validar($otra));
    }

    public function testCambiarContrasenaValidaReglasExigeActualYCierraLasDemasSesiones(): void
    {
        $id = $this->agregar();
        $sesiones = new ServicioDeSesiones(new RepositorioDeSesiones($this->base));
        $actual = $sesiones->abrir($id)->identificador;
        $otra = $sesiones->abrir($id)->identificador;

        $pantalla = $this->pedir($id, ruta: '/contrasena');
        self::assertSame(200, $pantalla->estado());
        PaginasParaValidar::guardar('cambiar-contrasena', $pantalla->cuerpo());

        // Protegido con CSRF.
        self::assertSame(403, $this->pedir($id, metodo: 'POST', ruta: '/contrasena', cuerpo: ['contrasena_actual' => 'Clave 2026', 'password' => 'Nueva2026', 'password_confirmacion' => 'Nueva2026'])->estado());

        // Reglas de contraseña y coincidencia.
        $reglasMal = $this->pedir(
            $id,
            metodo: 'POST',
            ruta: '/contrasena',
            cuerpo: ['_csrf' => self::CSRF, 'contrasena_actual' => 'Clave 2026', 'password' => 'sololetras', 'password_confirmacion' => 'distinta'],
            cookies: ['csrf' => self::CSRF, 'sesion' => $actual],
        );
        self::assertSame(422, $reglasMal->estado());
        self::assertStringContainsString('letras y números', $reglasMal->cuerpo());
        self::assertStringContainsString('Las contraseñas no coinciden.', $reglasMal->cuerpo());

        // Actual incorrecta.
        $actualMal = $this->pedir(
            $id,
            metodo: 'POST',
            ruta: '/contrasena',
            cuerpo: ['_csrf' => self::CSRF, 'contrasena_actual' => 'equivocada', 'password' => 'NuevaClave2026', 'password_confirmacion' => 'NuevaClave2026'],
            cookies: ['csrf' => self::CSRF, 'sesion' => $actual],
        );
        self::assertSame(422, $actualMal->estado());
        self::assertStringContainsString('La contraseña actual no es correcta.', $actualMal->cuerpo());

        // Cambio válido: actualiza el hash, deja abierta la sesión actual y cierra las demás.
        $ok = $this->pedir(
            $id,
            metodo: 'POST',
            ruta: '/contrasena',
            cuerpo: ['_csrf' => self::CSRF, 'contrasena_actual' => 'Clave 2026', 'password' => 'NuevaClave2026', 'password_confirmacion' => 'NuevaClave2026'],
            cookies: ['csrf' => self::CSRF, 'sesion' => $actual],
        );
        self::assertSame(303, $ok->estado());
        self::assertSame('/cuenta/contrasena?actualizada=1', $ok->cabecera('Location'));

        $hash = (string) $this->base->valor('SELECT contrasena_hash FROM usuario WHERE id = ?', [$id]);
        self::assertTrue(password_verify('NuevaClave2026', $hash));
        self::assertFalse(password_verify('Clave 2026', $hash));
        self::assertNotNull($sesiones->validar($actual));
        self::assertNull($sesiones->validar($otra));
    }

    public function testLaBarraMarcaMisDatosYLlevaAMisReservas(): void
    {
        $barra = self::barra($this->pedir($this->agregar()));

        self::assertMatchesRegularExpression('#<a href="/cuenta/"[^>]*aria-current="page"#', $barra);
        self::assertStringContainsString('href="/funcion/reservas"', $barra);
        self::assertDoesNotMatchRegularExpression('#<a href="/funcion/reservas"[^>]*aria-current#', $barra);
    }

    /**
     * @return iterable<string, array{string, list<Rol>, bool}>
     */
    public static function roles(): iterable
    {
        yield 'espectador' => ['espectador', [], false];
        yield 'organizador' => ['organizador', [Rol::Organizador], true];
        // El moderador alcanza el rol, pero lo suyo es la moderación (IC-662).
        yield 'moderador' => ['moderador', [Rol::Organizador, Rol::Moderador], false];
    }

    public function testElModeradorVaASuModeracionDesdeLaBarra(): void
    {
        $barra = self::barra($this->pedir($this->agregar([Rol::Moderador]), 'moderador'));

        self::assertStringContainsString('href="/moderacion/solicitudes"', $barra);
    }

    /**
     * @param list<Rol> $roles
     */
    #[DataProvider('roles')]
    public function testElPanelDelOrganizadorSoloAQuienAlcanzaElRol(string $rol, array $roles, bool $loVe): void
    {
        $barra = self::barra($this->pedir($this->agregar($roles), $rol));

        self::assertSame($loVe, str_contains($barra, 'href="/organizador/salas"'));
    }

    /**
     * EF-07.6: el estado de la última solicitud de sala, con su motivo y el enlace para verla.
     */
    public function testMuestraMiSolicitudDeSalaConSuEstadoYElEnlace(): void
    {
        $id = $this->agregar();
        self::solicitudesEnModeracion([$id => ['estado' => 'en_revision_manual', 'sala' => 'El Galpón', 'motivo' => 'Coordinamos una <b>visita</b>.', 'fecha' => '2026-10-02T12:00:00+00:00']]);

        $contenido = self::contenido($this->pedir($id));

        self::assertStringContainsString('Mi solicitud de sala', $contenido);
        self::assertStringContainsString('El Galpón', $contenido);
        self::assertStringContainsString('En revisión manual desde el 02/10/2026', $contenido);
        self::assertStringContainsString('Coordinamos una &lt;b&gt;visita&lt;/b&gt;.', $contenido);
        self::assertMatchesRegularExpression('#<a href="/solicitar-sala"[^>]*>Ver la solicitud</a>#', $contenido);
    }

    public function testUnaRechazadaLlevaACorregirlaYReenviarla(): void
    {
        $id = $this->agregar();
        self::solicitudesEnModeracion([$id => ['estado' => 'rechazada', 'sala' => 'El Galpón', 'motivo' => 'Falta la póliza.', 'fecha' => '2026-10-03T15:30:00+00:00']]);

        $contenido = self::contenido($this->pedir($id));

        self::assertStringContainsString('Rechazada el 03/10/2026', $contenido);
        self::assertStringContainsString('Falta la póliza.', $contenido);
        self::assertMatchesRegularExpression('#<a href="/solicitar-sala"[^>]*>Corregirla y reenviarla</a>#', $contenido);
    }

    /**
     * EF-22.10: la devuelta dice hasta cuándo tiene y lleva a completarla.
     */
    public function testUnaDevueltaLlevaACompletarLaDocumentacion(): void
    {
        $id = $this->agregar();
        self::solicitudesEnModeracion([$id => ['estado' => 'devuelta', 'sala' => 'El Galpón', 'motivo' => 'La póliza venció.', 'fecha' => '2026-10-02T12:00:00+00:00', 'vence' => '2026-10-17']]);

        $contenido = self::contenido($this->pedir($id));

        self::assertStringContainsString('Devuelta para completar hasta el 17/10/2026', $contenido);
        self::assertStringContainsString('La póliza venció.', $contenido);
        self::assertMatchesRegularExpression('#<a href="/solicitar-sala"[^>]*>Completar la documentación</a>#', $contenido);
    }

    /**
     * EF-22.10: la vencida dice cuándo venció y lleva a pedir la sala de nuevo.
     */
    public function testUnaVencidaLlevaAPedirLaSalaDeNuevo(): void
    {
        $id = $this->agregar();
        self::solicitudesEnModeracion([$id => ['estado' => 'vencida', 'sala' => 'El Galpón', 'motivo' => 'Venció el plazo para completar la documentación.', 'fecha' => '2026-10-18T06:45:00+00:00']]);

        $contenido = self::contenido($this->pedir($id));

        self::assertStringContainsString('Venció el 18/10/2026', $contenido);
        self::assertMatchesRegularExpression('#<a href="/solicitar-sala"[^>]*>Pedir la sala de nuevo</a>#', $contenido);
    }

    /**
     * Una fecha límite que no es una fecha es una respuesta que no se entiende: «Mi cuenta» se ve
     * sin el bloque.
     */
    public function testUnaFechaLimiteQueNoEsUnaFechaSeVeSinElBloque(): void
    {
        $id = $this->agregar();
        self::solicitudesEnModeracion([$id => ['estado' => 'devuelta', 'sala' => 'El Galpón', 'motivo' => 'La póliza venció.', 'fecha' => '2026-10-02T12:00:00+00:00', 'vence' => 17]]);

        $contenido = self::contenido($this->pedir($id));

        self::assertStringContainsString('Eva Ramos', $contenido);
        self::assertStringNotContainsString('Mi solicitud de sala', $contenido);
    }

    public function testSinSolicitudNoHayBloque(): void
    {
        $contenido = self::contenido($this->pedir($this->agregar()));

        self::assertStringNotContainsString('Mi solicitud de sala', $contenido);
        self::assertStringNotContainsString('/solicitar-sala', $contenido);
    }

    /**
     * Otro servicio caído no deja al usuario sin su cuenta: se ve igual, sin el bloque.
     */
    public function testSiModeracionNoRespondeSeVeIgualSinElBloque(): void
    {
        $id = $this->agregar();
        self::solicitudesEnModeracion([$id => ['estado' => 'pendiente', 'sala' => 'El Galpón', 'motivo' => null, 'fecha' => '2026-10-01T10:00:00+00:00']]);
        self::tirarModeracion();

        $contenido = self::contenido($this->pedir($id));

        self::assertStringContainsString('Eva Ramos', $contenido);
        self::assertStringNotContainsString('Mi solicitud de sala', $contenido);
    }

    public function testSinSesionPideIngresar(): void
    {
        self::assertSame(401, $this->pedir(null)->estado());
    }

    public function testSinCorreoVerificadoMuestraAvisoYReenvio(): void
    {
        $contenido = self::contenido($this->pedir($this->agregar(verificado: false)));

        self::assertStringContainsString('Todavía no verificaste tu correo', $contenido);
        self::assertStringContainsString('action="/cuenta/verificar/reenviar"', $contenido);
        self::assertStringContainsString('Mandarme el enlace otra vez', $contenido);
    }
}
