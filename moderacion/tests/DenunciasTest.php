<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Pruebas;

use IndieCinema\Nucleo\Aplicacion;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Log;
use IndieCinema\Nucleo\Pruebas\Dobles\PaginasParaValidar;
use IndieCinema\Nucleo\Pruebas\Dobles\ServidorSmtpDePrueba;
use IndieCinema\Nucleo\Uuid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Las denuncias (EF-07.8), de punta a punta: cualquiera con sesión denuncia una sala, una función,
 * una obra o un comentario, y la pestaña «Denuncias» de la bandeja de moderación las junta por lo
 * denunciado para que un moderador las desestime o actúe. Con la aplicación entera, un MySQL de
 * verdad, los subsistemas dueños de lo denunciado de mentira y un SMTP de prueba.
 */
final class DenunciasTest extends TestCase
{
    use ConBaseDePruebas;
    use ConServidoresDePrueba;

    private const SECRETO = 'secreto-de-nginx';
    private const CSRF = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';
    // Los que conoce Dobles/cuentas.php.
    private const ESPECTADORA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const ORGANIZADOR = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
    private const MODERADORA = 'ffffffff-ffff-4fff-8fff-ffffffffffff';
    private const ADMINISTRADOR = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee';

    private static ServidorSmtpDePrueba $correo;

    private BaseDeDatos $base;

    /** @var resource */
    private $salidaDelLog;

    public static function setUpBeforeClass(): void
    {
        self::iniciarServidores();
        self::$correo = ServidorSmtpDePrueba::iniciar();
    }

    public static function tearDownAfterClass(): void
    {
        self::detenerServidores();
        self::$correo->detener();
    }

    protected function setUp(): void
    {
        self::reiniciarServidores();
        self::$correo->vaciar();
        $this->base = self::baseConTablasNuevas($this->configuracion());
        $this->salidaDelLog = fopen('php://memory', 'w+');
    }

    private function configuracion(): Configuracion
    {
        return new Configuracion([
            'app' => ['subsistema' => 'moderacion', 'prefijo' => '/moderacion', 'entorno' => 'desarrollo'],
            'archivos' => ['carpeta' => sys_get_temp_dir() . '/sin-archivos'],
            'base' => self::baseDePruebas(),
            'nginx' => ['secreto' => self::SECRETO],
            'subsistemas' => ['cuentas' => self::$urls['cuentas'], 'programacion' => self::$urls['programacion'], 'funciones' => self::$urls['funciones']],
            'interno' => ['token' => 'token-de-moderacion'],
            'correo' => ['servidor' => self::$correo->url(), 'remitente' => 'no-responder@indiecinema.test'],
            'denuncias' => ['aviso_cantidad' => 3, 'cartelera_cantidad' => 5, 'dias' => 30],
        ]);
    }

    /**
     * @param array<string, mixed> $cuerpo
     */
    private function pedir(string $metodo, string $url, ?string $rol = 'espectador', array $cuerpo = [], ?string $usuario = null): Respuesta
    {
        $usuario ??= match ($rol) {
            'administrador' => self::ADMINISTRADOR,
            'moderador' => self::MODERADORA,
            default => self::ESPECTADORA,
        };
        $cabeceras = $rol === null ? [] : ['X-Nginx-Secreto' => self::SECRETO, 'X-Usuario-Id' => $usuario, 'X-Rol' => $rol];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $consulta);
        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion(), new Log('moderacion', $this->salidaDelLog));

        return PaginasParaValidar::anotar($metodo, "/moderacion{$url}", $aplicacion->atender(new Peticion($metodo, (string) parse_url($url, PHP_URL_PATH), $consulta, $cuerpo, $cabeceras, ['csrf' => self::CSRF])));
    }

    /**
     * @param array<string, string> $campos
     */
    private function denunciar(string $tipo, string $id, array $campos, ?string $rol = 'espectador', ?string $usuario = null): Respuesta
    {
        return $this->pedir('POST', '/denuncias/nueva?' . http_build_query(['tipo' => $tipo, 'id' => $id]), $rol, $campos + ['_csrf' => self::CSRF], $usuario);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function denuncias(): array
    {
        return $this->base->filas('SELECT tipo, objeto_id, titulo, usuario_id, categoria, detalle, estado FROM denuncia ORDER BY creada_en, id');
    }

    /**
     * @return list<array<string, mixed>> la gravedad y la sala de cada una, en orden
     */
    private function gravedades(): array
    {
        return $this->base->filas('SELECT objeto_id, categoria, gravedad, sala_id FROM denuncia ORDER BY creada_en, id');
    }

    /**
     * Una denuncia hecha en ese momento (UTC), por el formulario.
     */
    private function denunciada(string $tipo, string $id, string $usuario, string $cuando, string $categoria = 'ofensivo', string $detalle = ''): void
    {
        self::assertSame(303, $this->denunciar($tipo, $id, ['categoria' => $categoria, 'detalle' => $detalle], usuario: $usuario)->estado());
        $this->base->ejecutar('UPDATE denuncia SET creada_en = ? WHERE objeto_id = ? AND usuario_id = ?', [$cuando, $id, $usuario]);
    }

    /**
     * @param array<string, string> $campos
     */
    private function resolver(string $tipo, string $id, array $campos, string $rol = 'moderador', ?string $usuario = null): Respuesta
    {
        return $this->pedir('POST', "/denuncias/{$tipo}/{$id}/resolver", $rol, $campos + ['_csrf' => self::CSRF], $usuario);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function cerradas(): array
    {
        return $this->base->filas('SELECT objeto_id, estado, accion, motivo, resuelta_por, resuelta_en IS NOT NULL AS con_fecha FROM denuncia WHERE estado <> \'abierta\' ORDER BY creada_en, id');
    }

    /**
     * @return list<array{para: string, asunto: string, texto: string}> los correos que salieron, ya decodificados
     */
    private static function correosEnviados(): array
    {
        return array_map(static function (array $correo): array {
            [$cabeceras, $cuerpo] = explode("\r\n\r\n", $correo['mensaje'], 2);
            $asunto = iconv_mime_decode_headers($cabeceras, 0, 'UTF-8')['Subject'] ?? '';

            return [
                'para' => implode(',', $correo['para']),
                'asunto' => is_array($asunto) ? implode(' ', $asunto) : (string) $asunto,
                'texto' => (string) base64_decode(str_replace("\r\n", '', $cuerpo), true),
            ];
        }, self::$correo->correos());
    }

    /**
     * @return list<string> los títulos de la lista, en orden
     */
    private static function titulos(Respuesta $respuesta): array
    {
        self::assertSame(200, $respuesta->estado(), $respuesta->cuerpo());
        preg_match_all('#class="bandeja-item-sala">([^<]*)</span>#', $respuesta->cuerpo(), $titulos);

        return array_map(html_entity_decode(...), $titulos[1]);
    }

    /**
     * Una sala publicada en programación, del organizador salvo que se diga otro.
     */
    private static function sala(string $nombre, string $organizador = self::ORGANIZADOR, bool $habilitada = true): string
    {
        $id = Uuid::nuevo();
        self::salasEnProgramacion(self::salasDeProgramacion() + [$id => ['nombre' => $nombre, 'localidad' => 'Luján', 'organizador_id' => $organizador, 'habilitada' => $habilitada]]);

        return $id;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function suspensiones(string $salaId): array
    {
        return $this->base->filas('SELECT causa, motivo, abierta_por, levantada_en IS NULL AS activa FROM suspension WHERE sala_id = ? ORDER BY desde', [$salaId]);
    }

    /**
     * Una función de la sala en programación, con esas obras, como la devuelve su API interna.
     *
     * @param list<string> $obras ids de obras que ya están en programación
     */
    private static function funcion(string $salaId, array $obras, string $estado = 'en_votacion_titulos', ?string $fecha = null): string
    {
        $id = Uuid::nuevo();
        $sala = self::salasDeProgramacion()[$salaId];
        self::funcionesEnProgramacion(self::funcionesDeProgramacion() + [$id => [
            'estado' => $estado,
            'fecha_hora' => $fecha,
            'precio_base' => 2000,
            'cupo' => 50,
            'titulos' => array_map(static fn (string $obra): string => (string) self::obrasDeProgramacion()[$obra]['titulo'], $obras),
            'titulos_elegidos' => $estado !== 'en_votacion_titulos',
            'sala' => ['id' => $salaId, 'nombre' => $sala['nombre'], 'localidad' => 'Luján', 'habilitada' => $sala['habilitada'], 'direccion' => 'Av. San Martín 450'],
            'organizador_id' => $sala['organizador_id'],
            'obras_ids' => $obras,
        ]]);

        return $id;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function funcionesDeProgramacion(): array
    {
        return (array) json_decode((string) file_get_contents(self::$funciones), true);
    }

    /**
     * Una calificación con comentario en funciones, de una función de la sala.
     */
    private static function comentario(string $salaId, string $comentario, string $autor = self::ORGANIZADOR, bool $oculto = false): string
    {
        $id = Uuid::nuevo();
        self::calificacionesEnFunciones(self::calificacionesDeFunciones() + [$id => [
            'funcion_id' => Uuid::nuevo(),
            'sala_id' => $salaId,
            'usuario_id' => $autor,
            'espectador' => 'Omar',
            'puntaje' => 1,
            'comentario' => $comentario,
            'fecha' => '2026-11-01',
            'oculto' => $oculto,
        ]]);

        return $id;
    }

    /**
     * Una obra aprobada en programación, del organizador salvo que se diga otro dueño.
     */
    private static function obra(string $titulo, string $dueno = self::ORGANIZADOR, string $estado = 'aprobada'): string
    {
        $id = Uuid::nuevo();
        self::obrasEnProgramacion(self::obrasDeProgramacion() + [$id => ['titulo' => $titulo, 'dueno_id' => $dueno, 'estado' => $estado]]);

        return $id;
    }

    /**
     * EF-22.11: los motivos del lugar sólo se ofrecen en salas y funciones, y en una obra o un
     * comentario no se aceptan. La gravedad no se ve ni se elige.
     */
    public function testCadaTipoOfreceSusMotivos(): void
    {
        $sala = self::sala('El Galpón');
        $del = ['Seguridad del lugar', 'La función no se hizo', 'Cambio de horario o de lugar sin aviso', 'Instalaciones en mal estado (butacas, sonido, imagen)'];
        $de = ['Contenido ofensivo o inapropiado', 'Información falsa o engañosa', 'Derechos de autor', 'Otro'];
        $lugares = ["tipo=sala&id={$sala}", 'tipo=funcion&id=' . self::funcion($sala, [self::obra('Zama')])];
        $otros = ['tipo=obra&id=' . self::obra('La flor'), 'tipo=calificacion&id=' . self::comentario($sala, 'Un insulto.')];

        foreach ([...$lugares, ...$otros] as $consulta) {
            $pantalla = $this->pedir('GET', "/denuncias/nueva?{$consulta}");
            self::assertSame(200, $pantalla->estado(), $pantalla->cuerpo());
            preg_match_all('#name="categoria" value="([a-z_]+)"#', $pantalla->cuerpo(), $valores);
            $conLugar = in_array($consulta, $lugares, true);
            self::assertSame($conLugar ? ['seguridad', 'no_se_hizo', 'ofensivo', 'falsa', 'derechos', 'cambio_sin_aviso', 'instalaciones', 'otro'] : ['ofensivo', 'falsa', 'derechos', 'otro'], $valores[1], $consulta);
            foreach ([...$de, ...($conLugar ? $del : [])] as $motivo) {
                self::assertStringContainsString($motivo, $pantalla->cuerpo(), $consulta);
            }
            self::assertStringNotContainsString('name="gravedad"', $pantalla->cuerpo());
        }
        foreach (['no_se_hizo', 'cambio_sin_aviso', 'instalaciones'] as $delLugar) {
            self::assertSame(422, $this->denunciar('obra', self::obra('Otra'), ['categoria' => $delLugar, 'detalle' => ''])->estado(), $delLugar);
        }
        self::assertSame([], $this->denuncias());
    }

    /**
     * EF-22.11: la gravedad la da el motivo, y la sala es la denunciada o la de la función. Una
     * gravedad que llegue en el pedido no cambia nada.
     */
    public function testLaDenunciaGuardaLaGravedadDelMotivoYLaSala(): void
    {
        $sala = self::sala('El Galpón');
        $funcion = self::funcion($sala, [self::obra('Zama')]);
        $obra = self::obra('La flor');
        $comentario = self::comentario($sala, 'Un insulto.');

        $this->denunciada('sala', $sala, self::ESPECTADORA, '2026-11-01 10:00:00', 'instalaciones');
        $this->denunciada('funcion', $funcion, self::ESPECTADORA, '2026-11-02 10:00:00', 'cambio_sin_aviso');
        $this->denunciada('funcion', $funcion, self::ADMINISTRADOR, '2026-11-03 10:00:00', 'no_se_hizo');
        self::assertSame(303, $this->denunciar('obra', $obra, ['categoria' => 'otro', 'detalle' => 'Algo.', 'gravedad' => 'alta'])->estado());
        $this->base->ejecutar('UPDATE denuncia SET creada_en = ? WHERE objeto_id = ?', ['2026-11-04 10:00:00', $obra]);
        $this->denunciada('calificacion', $comentario, self::ESPECTADORA, '2026-11-05 10:00:00', 'ofensivo');

        self::assertSame([
            ['objeto_id' => $sala, 'categoria' => 'instalaciones', 'gravedad' => 'baja', 'sala_id' => $sala],
            ['objeto_id' => $funcion, 'categoria' => 'cambio_sin_aviso', 'gravedad' => 'media', 'sala_id' => $sala],
            ['objeto_id' => $funcion, 'categoria' => 'no_se_hizo', 'gravedad' => 'alta', 'sala_id' => $sala],
            ['objeto_id' => $obra, 'categoria' => 'otro', 'gravedad' => 'baja', 'sala_id' => null],
            ['objeto_id' => $comentario, 'categoria' => 'ofensivo', 'gravedad' => 'alta', 'sala_id' => null],
        ], $this->gravedades());
    }

    /**
     * EF-22.11: primero lo que tiene la denuncia abierta más grave, después lo que tiene más y
     * después lo que lleva más tiempo. Cada ítem lleva la etiqueta de su más grave, y cada
     * denuncia la suya.
     */
    public function testLaBandejaOrdenaPorGravedadCantidadYAntiguedad(): void
    {
        $baja = self::obra('Baja y vieja');
        $mediaVieja = self::obra('Media vieja');
        $mediaNueva = self::obra('Media nueva');
        $mediaDoble = self::obra('Media doble');
        $alta = self::obra('Alta y nueva');
        $this->denunciada('obra', $baja, self::ESPECTADORA, '2026-11-01 10:00:00', 'otro', 'Algo.');
        $this->denunciada('obra', $mediaVieja, self::ESPECTADORA, '2026-11-02 10:00:00', 'falsa');
        $this->denunciada('obra', $mediaNueva, self::ESPECTADORA, '2026-11-03 10:00:00', 'derechos');
        $this->denunciada('obra', $mediaDoble, self::ESPECTADORA, '2026-11-04 10:00:00', 'falsa');
        $this->denunciada('obra', $mediaDoble, self::ADMINISTRADOR, '2026-11-05 10:00:00', 'otro', 'Algo.');
        $this->denunciada('obra', $alta, self::ESPECTADORA, '2026-11-06 10:00:00', 'otro', 'Algo.');
        $this->denunciada('obra', $alta, self::ADMINISTRADOR, '2026-11-07 10:00:00', 'ofensivo');

        $respuesta = $this->pedir('GET', '/denuncias', 'moderador');

        self::assertSame(['Alta y nueva', 'Media doble', 'Media vieja', 'Media nueva', 'Baja y vieja'], self::titulos($respuesta));
        preg_match_all('#class="estado-cuenta gravedad-([a-z]+)"><span class="sr-only">Gravedad </span>([A-Za-z]+)</span>#', $respuesta->cuerpo(), $etiquetas);
        // Las cinco de la lista, y después las dos de la elegida (la alta), primero la más grave.
        self::assertSame(['alta', 'media', 'media', 'media', 'baja', 'alta', 'baja'], $etiquetas[1]);
        self::assertSame(['Alta', 'Media', 'Media', 'Media', 'Baja', 'Alta', 'Baja'], $etiquetas[2]);
    }

    /**
     * EF-22.11: una sala o una función con una alta abierta va antes que todo, marcada «Para
     * suspender», con «Suspender» elegida. No se suspende sola. Una alta sobre una obra sólo cuenta
     * para el orden.
     */
    public function testUnaAltaSobreUnaSalaOUnaFuncionQuedaParaSuspender(): void
    {
        $sala = self::sala('El Galpón');
        $funcion = self::funcion(self::sala('La Fábrica'), [self::obra('Zama')]);
        $obra = self::obra('La flor');
        $this->denunciada('obra', $obra, self::ESPECTADORA, '2026-11-01 10:00:00', 'ofensivo');
        $this->denunciada('obra', $obra, self::ADMINISTRADOR, '2026-11-02 10:00:00', 'ofensivo');
        $this->denunciada('sala', $sala, self::ESPECTADORA, '2026-11-03 10:00:00', 'falsa');
        $this->denunciada('funcion', $funcion, self::ESPECTADORA, '2026-11-04 10:00:00', 'falsa');
        $this->denunciada('funcion', $funcion, self::ADMINISTRADOR, '2026-11-05 10:00:00', 'no_se_hizo');

        $respuesta = $this->pedir('GET', '/denuncias', 'moderador');

        self::assertSame(['Zama', 'La flor', 'El Galpón'], self::titulos($respuesta));
        // En la lista y en la elegida, que es la función.
        self::assertSame(2, substr_count($respuesta->cuerpo(), '>Para suspender</span>'));
        self::assertMatchesRegularExpression('#value="suspender" required checked#', $respuesta->cuerpo());
        $deLaObra = $this->pedir('GET', "/denuncias?tipo=obra&id={$obra}", 'moderador')->cuerpo();
        self::assertSame(1, substr_count($deLaObra, '>Para suspender</span>'));
        $deLaSala = $this->pedir('GET', "/denuncias?tipo=sala&id={$sala}", 'moderador')->cuerpo();
        self::assertStringNotContainsString('checked', $deLaSala);

        self::assertSame([], $this->cerradas());
        self::assertSame([], $this->suspensiones($funcion));
        self::assertSame([], self::pedidosA('programacion'));
    }

    /**
     * Después de un 422 queda lo que eligió el moderador, no «Suspender».
     */
    public function testParaSuspenderConservaLoElegidoSiVuelveConErrores(): void
    {
        $sala = self::sala('El Galpón');
        $this->denunciada('sala', $sala, self::ESPECTADORA, '2026-11-01 10:00:00', 'seguridad');

        $respuesta = $this->resolver('sala', $sala, ['accion' => 'desestimar', 'motivo' => ' ']);

        self::assertSame(422, $respuesta->estado());
        self::assertMatchesRegularExpression('#value="desestimar" required checked#', $respuesta->cuerpo());
        self::assertDoesNotMatchRegularExpression('#value="suspender" required checked#', $respuesta->cuerpo());
        self::assertSame([], $this->suspensiones($sala));
    }

    /**
     * EF-22.11: «Tomar» cierra todas las abiertas de una sala o una función como resueltas, con la
     * acción tomada y el motivo, sin pedirle nada a otro subsistema ni suspender.
     */
    public function testTomarCierraLasAbiertasComoTomadasSinSancionar(): void
    {
        $sala = self::sala('El Galpón');
        $funcion = self::funcion($sala, [self::obra('Zama')]);
        $this->denunciada('sala', $sala, self::ESPECTADORA, '2026-11-01 10:00:00', 'falsa');
        $this->denunciada('sala', $sala, self::ADMINISTRADOR, '2026-11-02 10:00:00', 'instalaciones');
        $this->denunciada('funcion', $funcion, self::ESPECTADORA, '2026-11-03 10:00:00', 'cambio_sin_aviso');
        self::assertStringContainsString('value="tomar"', $this->pedir('GET', "/denuncias?tipo=sala&id={$sala}", 'moderador')->cuerpo());

        $respuesta = $this->resolver('sala', $sala, ['accion' => 'tomar', 'motivo' => 'Las butacas están rotas.']);
        self::assertSame(303, $respuesta->estado(), $respuesta->cuerpo());
        self::assertSame("/moderacion/denuncias?tomadas={$sala}", $respuesta->cabecera('Location'));
        self::assertSame(303, $this->resolver('funcion', $funcion, ['accion' => 'tomar', 'motivo' => 'Cambiaron la fecha.'])->estado());

        $tomada = static fn (string $id, string $motivo): array => ['objeto_id' => $id, 'estado' => 'resuelta', 'accion' => 'tomada', 'motivo' => $motivo, 'resuelta_por' => self::MODERADORA, 'con_fecha' => 1];
        self::assertSame([
            $tomada($sala, 'Las butacas están rotas.'),
            $tomada($sala, 'Las butacas están rotas.'),
            $tomada($funcion, 'Cambiaron la fecha.'),
        ], $this->cerradas());
        self::assertSame([], self::pedidosA('programacion'));
        self::assertSame([], self::pedidosA('funciones'));
        self::assertSame([], $this->suspensiones($sala));
        self::assertStringContainsString('Tomaste las denuncias de «El Galpón».', $this->pedir('GET', "/denuncias?tomadas={$sala}", 'moderador')->cuerpo());

        $historial = $this->pedir('GET', '/denuncias?ver=historial', 'moderador')->cuerpo();
        self::assertSame(3, substr_count($historial, '>Tomada</span>'));
        self::assertStringContainsString('Las butacas están rotas.', $historial);
    }

    /**
     * Las reglas no cuentan obras ni comentarios: ahí «Tomar» no se ofrece ni se acepta.
     */
    public function testTomarNoSeOfreceNiSeAceptaEnObrasNiComentarios(): void
    {
        $obra = self::obra('La flor');
        $comentario = self::comentario(self::sala('El Galpón'), 'Un insulto.');
        $this->denunciada('obra', $obra, self::ESPECTADORA, '2026-11-01 10:00:00');
        $this->denunciada('calificacion', $comentario, self::ESPECTADORA, '2026-11-02 10:00:00');

        foreach (['obra' => $obra, 'calificacion' => $comentario] as $tipo => $id) {
            self::assertStringNotContainsString('value="tomar"', $this->pedir('GET', "/denuncias?tipo={$tipo}&id={$id}", 'moderador')->cuerpo());
            $respuesta = $this->resolver($tipo, $id, ['accion' => 'tomar', 'motivo' => 'Es válida.']);
            self::assertSame(422, $respuesta->estado());
            self::assertStringContainsString('id="error-accion"', $respuesta->cuerpo());
        }
        self::assertSame([], $this->cerradas());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function motivosQueNoSirven(): iterable
    {
        yield 'sin motivo' => [' '];
        yield 'motivo de más' => [str_repeat('a', 1001)];
    }

    #[DataProvider('motivosQueNoSirven')]
    public function testTomarPideElMotivo(string $motivo): void
    {
        $sala = self::sala('El Galpón');
        $this->denunciada('sala', $sala, self::ESPECTADORA, '2026-11-01 10:00:00', 'falsa');

        $respuesta = $this->resolver('sala', $sala, ['accion' => 'tomar', 'motivo' => $motivo]);

        self::assertSame(422, $respuesta->estado());
        self::assertStringContainsString('id="error-motivo"', $respuesta->cuerpo());
        self::assertSame([], $this->cerradas());
    }

    public function testElModeradorNoTomaLasDeSuSala(): void
    {
        $sala = self::sala('La de Mora', self::MODERADORA);
        $this->denunciada('sala', $sala, self::ESPECTADORA, '2026-11-01 10:00:00', 'falsa');

        self::assertSame(403, $this->resolver('sala', $sala, ['accion' => 'tomar', 'motivo' => 'Es válida.'])->estado());
        self::assertSame([], $this->cerradas());
    }

    /**
     * Hace tantos días, en UTC como creada_en.
     */
    private static function haceDias(int $dias): string
    {
        return gmdate('Y-m-d H:i:s', time() - $dias * 86400);
    }

    /**
     * Una denuncia ya resuelta sobre la sala (o una de sus funciones), escrita directo en la base:
     * cuenta para las reglas sin pasar por ellas.
     */
    private function yaResuelta(string $salaId, string $usuario, string $gravedad = 'media', int $haceDias = 1, string $tipo = 'sala', ?string $objeto = null): void
    {
        $categoria = ['alta' => 'seguridad', 'media' => 'falsa', 'baja' => 'instalaciones'][$gravedad];
        $this->base->ejecutar(
            "INSERT INTO denuncia (id, tipo, objeto_id, sala_id, titulo, usuario_id, categoria, gravedad, creada_en, estado, accion, motivo, resuelta_por, resuelta_en)
             VALUES (?, ?, ?, ?, 'Algo', ?, ?, ?, ?, 'resuelta', 'tomada', 'Ya.', ?, UTC_TIMESTAMP())",
            [Uuid::nuevo(), $tipo, $objeto ?? $salaId, $salaId, $usuario, $categoria, $gravedad, self::haceDias($haceDias), self::MODERADORA],
        );
    }

    /**
     * Denuncia lo de la sala con un usuario nuevo y lo toma el moderador.
     */
    private function tomarUnaNueva(string $tipo, string $id, string $categoria = 'falsa'): void
    {
        $this->denunciada($tipo, $id, Uuid::nuevo(), self::haceDias(1), $categoria);
        self::assertSame(303, $this->resolver($tipo, $id, ['accion' => 'tomar', 'motivo' => 'Es válida.'])->estado());
    }

    /**
     * @return list<string> los asuntos de los correos que salieron
     */
    private static function asuntos(): array
    {
        return array_column(self::correosEnviados(), 'asunto');
    }

    /**
     * EF-22.11: al tercer denunciante con una media tomada en el período, el organizador recibe
     * el aviso, sin quién denunció ni qué escribió. Seguir arriba no lo repite.
     */
    public function testElAvisoSaleAlCruzarElPrimerLimiteYNoSeRepite(): void
    {
        $sala = self::sala('El Galpón');
        $this->tomarUnaNueva('sala', $sala);
        $this->tomarUnaNueva('sala', $sala);
        self::assertSame([], self::correosEnviados());

        $this->denunciada('sala', $sala, self::ESPECTADORA, self::haceDias(1), 'falsa', 'Mienten con la dirección.');
        self::assertSame(303, $this->resolver('sala', $sala, ['accion' => 'tomar', 'motivo' => 'La dirección está mal.'])->estado());

        $correos = self::correosEnviados();
        self::assertCount(1, $correos);
        self::assertSame('<omar@ejemplo.com>', $correos[0]['para']);
        self::assertSame('Tu sala acumula denuncias', $correos[0]['asunto']);
        self::assertStringContainsString('«El Galpón»', $correos[0]['texto']);
        self::assertStringContainsString('últimos 30 días', $correos[0]['texto']);
        self::assertStringContainsString('Si llegan a 5', $correos[0]['texto']);
        self::assertStringNotContainsString('Mienten con la dirección.', $correos[0]['texto']);
        self::assertStringNotContainsString('Eva', $correos[0]['texto']);

        $this->tomarUnaNueva('sala', $sala);
        self::assertCount(1, self::correosEnviados());
    }

    public function testLaCarteleraSaleAlCruzarElSegundoLimite(): void
    {
        $sala = self::sala('El Galpón');
        foreach (range(1, 4) as $_) {
            $this->yaResuelta($sala, Uuid::nuevo());
        }

        $this->tomarUnaNueva('sala', $sala);

        $correos = self::correosEnviados();
        self::assertSame(['Tu sala baja en la cartelera'], array_column($correos, 'asunto'));
        self::assertStringContainsString('«El Galpón»', $correos[0]['texto']);
        self::assertStringContainsString('al final de la cartelera', $correos[0]['texto']);
        self::assertStringContainsString('salgan del período de 30 días', $correos[0]['texto']);
    }

    /**
     * Una resolución que cierra varias puede cruzar los dos límites: salen los dos correos.
     */
    public function testUnaResolucionQueCruzaLosDosMandaLosDos(): void
    {
        $sala = self::sala('El Galpón');
        $this->yaResuelta($sala, Uuid::nuevo());
        $this->yaResuelta($sala, Uuid::nuevo());
        foreach (range(1, 3) as $_) {
            $this->denunciada('sala', $sala, Uuid::nuevo(), self::haceDias(1), 'derechos');
        }

        self::assertSame(303, $this->resolver('sala', $sala, ['accion' => 'tomar', 'motivo' => 'Son válidas.'])->estado());

        self::assertSame(['Tu sala acumula denuncias', 'Tu sala baja en la cartelera'], self::asuntos());
    }

    /**
     * Cuentan denunciantes distintos (una pelea no castiga la sala), sólo en el período, sólo las
     * medias y sólo las resueltas: desestimar cierra pero no suma.
     *
     * @return iterable<string, array{callable(self, string): void}>
     */
    public static function loQueNoSuma(): iterable
    {
        yield 'el mismo denunciante' => [static function (self $prueba, string $sala): void {
            $prueba->yaResuelta($sala, self::ESPECTADORA, haceDias: 5);
            $prueba->denunciada('sala', $sala, self::ESPECTADORA, self::haceDias(1), 'falsa');
            $prueba->resolver('sala', $sala, ['accion' => 'tomar', 'motivo' => 'Otra vez.']);
        }];
        yield 'fuera del período' => [static function (self $prueba, string $sala): void {
            $prueba->yaResuelta($sala, Uuid::nuevo(), haceDias: 31);
            $prueba->yaResuelta($sala, Uuid::nuevo(), haceDias: 60);
        }];
        yield 'una baja y una alta' => [static function (self $prueba, string $sala): void {
            $prueba->denunciada('sala', $sala, Uuid::nuevo(), self::haceDias(1), 'instalaciones');
            $prueba->denunciada('sala', $sala, Uuid::nuevo(), self::haceDias(1), 'seguridad');
            $prueba->resolver('sala', $sala, ['accion' => 'tomar', 'motivo' => 'Son válidas.']);
        }];
        yield 'desestimada' => [static function (self $prueba, string $sala): void {
            $prueba->denunciada('sala', $sala, Uuid::nuevo(), self::haceDias(1), 'falsa');
            $prueba->resolver('sala', $sala, ['accion' => 'desestimar', 'motivo' => 'No es cierto.']);
        }];
    }

    /**
     * @param callable(self, string): void $loQueNoSuma
     */
    #[DataProvider('loQueNoSuma')]
    public function testLoQueNoSumaNoLlegaAlAviso(callable $loQueNoSuma): void
    {
        $sala = self::sala('El Galpón');
        $this->yaResuelta($sala, self::ESPECTADORA);
        $this->yaResuelta($sala, self::ADMINISTRADOR);

        $loQueNoSuma($this, $sala);

        self::assertSame([], self::correosEnviados());
        self::assertSame(0, (int) $this->base->valor("SELECT COUNT(*) FROM denuncia WHERE estado = 'abierta'"));
        // Y una media de otro denunciante, sí.
        $this->tomarUnaNueva('sala', $sala);
        self::assertSame(['Tu sala acumula denuncias'], self::asuntos());
    }

    /**
     * Las denuncias de una función cuentan para su sala.
     */
    public function testLasDeUnaFuncionSumanASuSala(): void
    {
        $sala = self::sala('El Galpón');
        $funcion = self::funcion($sala, [self::obra('Zama')]);
        $this->yaResuelta($sala, Uuid::nuevo());
        $this->yaResuelta($sala, Uuid::nuevo(), tipo: 'funcion', objeto: Uuid::nuevo());

        $this->tomarUnaNueva('funcion', $funcion, 'cambio_sin_aviso');

        self::assertSame(['Tu sala acumula denuncias'], self::asuntos());
    }

    /**
     * Si el correo no sale (cuentas no responde), las denuncias quedan cerradas igual.
     */
    public function testSiElAvisoNoSaleElCierreQuedaIgual(): void
    {
        $sala = self::sala('El Galpón');
        $this->yaResuelta($sala, Uuid::nuevo());
        $this->yaResuelta($sala, Uuid::nuevo());
        $this->denunciada('sala', $sala, Uuid::nuevo(), self::haceDias(1), 'falsa');
        self::tirar('cuentas');

        self::assertSame(303, $this->resolver('sala', $sala, ['accion' => 'tomar', 'motivo' => 'Es válida.'])->estado());

        self::assertSame([], self::correosEnviados());
        self::assertSame(0, (int) $this->base->valor("SELECT COUNT(*) FROM denuncia WHERE estado = 'abierta'"));
    }

    /**
     * En cada sala o función de la bandeja, dónde está su sala respecto de los dos límites. En una
     * obra, no.
     */
    public function testLaBandejaMuestraElContadorDeLaSala(): void
    {
        $sala = self::sala('El Galpón');
        $funcion = self::funcion($sala, [self::obra('Zama')]);
        $this->yaResuelta($sala, Uuid::nuevo());
        $this->yaResuelta($sala, Uuid::nuevo());
        $this->denunciada('funcion', $funcion, self::ESPECTADORA, self::haceDias(1), 'falsa');
        $this->denunciada('obra', self::obra('La flor'), self::ESPECTADORA, self::haceDias(2), 'falsa');

        $pantalla = $this->pedir('GET', "/denuncias?tipo=funcion&id={$funcion}", 'moderador')->cuerpo();

        self::assertSame(1, substr_count($pantalla, 'Sala: 2 de 3 para aviso · 2 de 5 para cartelera'));
        self::assertStringContainsString('Denuncias medias resueltas de la sala en el período: 2 de 3 para aviso · 2 de 5 para cartelera.', $pantalla);
    }

    public function testElHistorialMuestraLaGravedad(): void
    {
        $id = self::obra('La flor');
        $this->denunciada('obra', $id, self::ESPECTADORA, '2026-11-01 10:00:00', 'derechos');
        $this->resolver('obra', $id, ['accion' => 'desestimar', 'motivo' => 'Tiene los derechos.']);

        $historial = $this->pedir('GET', '/denuncias?ver=historial', 'moderador')->cuerpo();

        self::assertStringContainsString('<span class="estado-cuenta gravedad-media"><span class="sr-only">Gravedad </span>Media</span>', $historial);
    }

    public function testElFormularioDeUnaObraMuestraQueSeDenunciaYSusCategorias(): void
    {
        $id = self::obra('La flor');

        $respuesta = $this->pedir('GET', "/denuncias/nueva?tipo=obra&id={$id}");

        self::assertSame(200, $respuesta->estado(), $respuesta->cuerpo());
        $pantalla = $respuesta->cuerpo();
        self::assertStringContainsString('La flor', $pantalla);
        foreach (['Contenido ofensivo o inapropiado', 'Derechos de autor', 'Información falsa o engañosa', 'Otro'] as $categoria) {
            self::assertStringContainsString($categoria, $pantalla);
        }
        self::assertStringNotContainsString('Seguridad del lugar', $pantalla);
        self::assertStringContainsString('name="detalle"', $pantalla);
    }

    public function testNoSeDenunciaLoPropioNiLoQueNoEstaPublicado(): void
    {
        $propia = self::obra('La mía', self::ESPECTADORA);
        $pendiente = self::obra('Pendiente', estado: 'pendiente');
        $rechazada = self::obra('Rechazada', estado: 'rechazada');

        foreach (["tipo=obra&id={$propia}", "tipo=obra&id={$pendiente}", "tipo=obra&id={$rechazada}", 'tipo=obra&id=' . Uuid::nuevo(), 'tipo=obra&id=nada', 'tipo=persona&id=' . Uuid::nuevo(), 'id=' . Uuid::nuevo()] as $consulta) {
            self::assertSame(404, $this->pedir('GET', "/denuncias/nueva?{$consulta}")->estado(), $consulta);
        }
    }

    /**
     * Sin sesión, ingresa y vuelve al formulario (el ticket 07).
     */
    public function testSinSesionLlevaAIngresarYVuelve(): void
    {
        $id = self::obra('La flor');

        $respuesta = $this->pedir('GET', "/denuncias/nueva?tipo=obra&id={$id}", null);

        self::assertSame(401, $respuesta->estado());
        self::assertStringContainsString('href="/cuenta/ingresar?volver=' . rawurlencode("/moderacion/denuncias/nueva?tipo=obra&id={$id}") . '"', $respuesta->cuerpo());
    }

    public function testLaDenunciaQuedaAbiertaYSeConfirmaConElLinkALaFicha(): void
    {
        $id = self::obra('La flor');

        $respuesta = $this->denunciar('obra', $id, ['categoria' => 'derechos', 'detalle' => '  La subieron sin permiso.  ']);

        self::assertSame(303, $respuesta->estado(), $respuesta->cuerpo());
        self::assertSame("/moderacion/denuncias/nueva?tipo=obra&id={$id}&enviada=1", $respuesta->cabecera('Location'));
        self::assertSame(
            [['tipo' => 'obra', 'objeto_id' => $id, 'titulo' => 'La flor', 'usuario_id' => self::ESPECTADORA, 'categoria' => 'derechos', 'detalle' => 'La subieron sin permiso.', 'estado' => 'abierta']],
            $this->denuncias(),
        );
        $confirmacion = $this->pedir('GET', "/denuncias/nueva?tipo=obra&id={$id}&enviada=1")->cuerpo();
        self::assertStringContainsString('Recibimos tu denuncia. La revisa la moderación.', $confirmacion);
        self::assertStringContainsString("href=\"/obras/{$id}\"", $confirmacion);
        self::assertStringNotContainsString('name="categoria"', $confirmacion);
    }

    /**
     * Una abierta por usuario y objeto: repetirla no suma otra.
     */
    public function testUnaRepetidaNoSumaOtra(): void
    {
        $id = self::obra('La flor');
        $this->denunciar('obra', $id, ['categoria' => 'derechos', 'detalle' => '']);

        self::assertStringContainsString('Ya denunciaste esto: la está revisando la moderación.', $this->pedir('GET', "/denuncias/nueva?tipo=obra&id={$id}")->cuerpo());
        self::assertSame(303, $this->denunciar('obra', $id, ['categoria' => 'ofensivo', 'detalle' => ''])->estado());
        self::assertCount(1, $this->denuncias());

        $this->denunciar('obra', $id, ['categoria' => 'ofensivo', 'detalle' => ''], usuario: Uuid::nuevo());
        self::assertCount(2, $this->denuncias());
    }

    /**
     * @return iterable<string, array{array<string, string>, string, string}>
     */
    public static function denunciasIncompletas(): iterable
    {
        yield 'sin categoría' => [['detalle' => 'Algo.'], 'categoria', 'Elegí de qué se trata.'];
        yield 'otra categoría' => [['categoria' => 'seguridad', 'detalle' => 'Algo.'], 'categoria', 'Elegí de qué se trata.'];
        yield 'otro sin detalle' => [['categoria' => 'otro', 'detalle' => '  '], 'detalle', 'Contanos qué pasa: en «Otro» el detalle es obligatorio.'];
        yield 'detalle de más' => [['categoria' => 'ofensivo', 'detalle' => str_repeat('a', 1001)], 'detalle', 'El detalle puede tener hasta 1000 caracteres.'];
    }

    /**
     * @param array<string, string> $campos
     */
    #[DataProvider('denunciasIncompletas')]
    public function testUnaDenunciaIncompletaNoSeGuarda(array $campos, string $campo, string $error): void
    {
        $id = self::obra('La flor');

        $respuesta = $this->denunciar('obra', $id, $campos);

        self::assertSame(422, $respuesta->estado());
        self::assertStringContainsString($error, $respuesta->cuerpo());
        self::assertStringContainsString("id=\"error-{$campo}\"", $respuesta->cuerpo());
        self::assertSame([], $this->denuncias());
    }

    /**
     * Un ítem por lo denunciado; la elegida, con cada denuncia y quién la hizo (cuentas da el
     * nombre y el correo).
     */
    public function testLaBandejaAgrupaPorLoDenunciadoConCadaDenunciaYQuienLaHizo(): void
    {
        $nueva = self::obra('La nueva');
        $vieja = self::obra('La vieja');
        $this->denunciada('obra', $nueva, self::ESPECTADORA, '2026-11-02 10:00:00', 'falsa');
        $this->denunciada('obra', $vieja, self::ESPECTADORA, '2026-11-01 10:00:00', 'derechos', 'No tienen los derechos.');
        $this->denunciada('obra', $vieja, self::ADMINISTRADOR, '2026-11-03 10:00:00', 'falsa');

        $respuesta = $this->pedir('GET', '/denuncias', 'moderador');

        self::assertSame(['La vieja', 'La nueva'], self::titulos($respuesta));
        $pantalla = $respuesta->cuerpo();
        self::assertStringContainsString('2 denuncias', $pantalla);
        self::assertStringContainsString('Rocío Paz', $pantalla);
        self::assertStringContainsString('Derechos de autor', $pantalla);
        self::assertStringContainsString('No tienen los derechos.', $pantalla);
        self::assertStringContainsString('Información falsa o engañosa', $pantalla);
        self::assertStringContainsString('Eva Prueba', $pantalla);
        self::assertStringContainsString('eva@ejemplo.com', $pantalla);
        self::assertStringContainsString("/moderacion/denuncias/obra/{$vieja}/resolver", $pantalla);

        $otra = $this->pedir('GET', "/denuncias?tipo=obra&id={$nueva}", 'moderador')->cuerpo();
        self::assertStringContainsString("/moderacion/denuncias/obra/{$nueva}/resolver", $otra);
        self::assertSame(404, $this->pedir('GET', '/denuncias?tipo=obra&id=' . Uuid::nuevo(), 'moderador')->estado());
    }

    public function testSinDenunciasLoDice(): void
    {
        self::assertStringContainsString('No hay denuncias abiertas.', $this->pedir('GET', '/denuncias', 'moderador')->cuerpo());
    }

    public function testSiCuentasNoRespondeSeVeSinQuienDenuncio(): void
    {
        $this->denunciada('obra', self::obra('La flor'), self::ESPECTADORA, '2026-11-01 10:00:00');
        self::tirar('cuentas');

        $pantalla = $this->pedir('GET', '/denuncias', 'moderador');

        self::assertSame(['La flor'], self::titulos($pantalla));
        self::assertStringNotContainsString('Eva Prueba', $pantalla->cuerpo());
    }

    /**
     * Desestimar cierra todas las abiertas de lo elegido, sin tocar lo denunciado, y quedan en el
     * historial con el motivo.
     */
    public function testDesestimarCierraTodasYQuedanEnElHistorial(): void
    {
        $id = self::obra('La flor');
        $otra = self::obra('Otra');
        $this->denunciada('obra', $id, self::ESPECTADORA, '2026-11-01 10:00:00');
        $this->denunciada('obra', $id, self::ADMINISTRADOR, '2026-11-02 10:00:00');
        $this->denunciada('obra', $otra, self::ESPECTADORA, '2026-11-03 10:00:00');

        $respuesta = $this->resolver('obra', $id, ['accion' => 'desestimar', 'motivo' => 'Tiene los derechos: lo mostró el organizador.']);

        self::assertSame(303, $respuesta->estado(), $respuesta->cuerpo());
        self::assertSame("/moderacion/denuncias?desestimadas={$id}", $respuesta->cabecera('Location'));
        $cerrada = ['objeto_id' => $id, 'estado' => 'desestimada', 'accion' => null, 'motivo' => 'Tiene los derechos: lo mostró el organizador.', 'resuelta_por' => self::MODERADORA, 'con_fecha' => 1];
        self::assertSame([$cerrada, $cerrada], $this->cerradas());
        self::assertSame([], self::pedidosA('programacion'));
        self::assertStringContainsString('Desestimaste las denuncias de «La flor».', $this->pedir('GET', "/denuncias?desestimadas={$id}", 'moderador')->cuerpo());
        self::assertSame(['Otra'], self::titulos($this->pedir('GET', '/denuncias', 'moderador')));

        $historial = $this->pedir('GET', '/denuncias?ver=historial', 'moderador');
        self::assertSame(['La flor', 'La flor'], self::titulos($historial));
        self::assertStringContainsString('Desestimada', $historial->cuerpo());
        self::assertStringContainsString('Tiene los derechos: lo mostró el organizador.', $historial->cuerpo());
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function resolucionesIncompletas(): iterable
    {
        yield 'sin motivo' => [['accion' => 'desestimar', 'motivo' => ' '], 'motivo'];
        yield 'motivo de más' => [['accion' => 'desestimar', 'motivo' => str_repeat('a', 1001)], 'motivo'];
        yield 'sin acción' => [['motivo' => 'Bien.'], 'accion'];
        yield 'otra acción' => [['accion' => 'ocultar', 'motivo' => 'Bien.'], 'accion'];
    }

    /**
     * @param array<string, string> $campos
     */
    #[DataProvider('resolucionesIncompletas')]
    public function testUnaResolucionIncompletaNoCierraNada(array $campos, string $campo): void
    {
        $id = self::obra('La flor');
        $this->denunciada('obra', $id, self::ESPECTADORA, '2026-11-01 10:00:00');

        $respuesta = $this->resolver('obra', $id, $campos);

        self::assertSame(422, $respuesta->estado());
        self::assertStringContainsString("id=\"error-{$campo}\"", $respuesta->cuerpo());
        self::assertSame([], $this->cerradas());
    }

    /**
     * Dos pestañas: la segunda vez ya no hay abiertas.
     */
    public function testResolverLoQueYaSeCerroNoCambiaNada(): void
    {
        $id = self::obra('La flor');
        $this->denunciada('obra', $id, self::ESPECTADORA, '2026-11-01 10:00:00');
        $this->resolver('obra', $id, ['accion' => 'desestimar', 'motivo' => 'Nada.']);

        $otra = $this->resolver('obra', $id, ['accion' => 'desestimar', 'motivo' => 'Otro motivo.']);

        self::assertSame("/moderacion/denuncias?ya_resueltas={$id}", $otra->cabecera('Location'));
        self::assertSame('Nada.', $this->cerradas()[0]['motivo']);
    }

    /**
     * Q1 de EF-07: el moderador no resuelve las denuncias sobre lo suyo ni las que hizo él; el
     * administrador sí.
     */
    public function testElModeradorNoResuelveLoSuyoNiLoQueDenuncio(): void
    {
        $suya = self::obra('La de Mora', self::MODERADORA);
        $denunciadaPorElla = self::obra('La flor');
        $this->denunciada('obra', $suya, self::ESPECTADORA, '2026-11-01 10:00:00');
        $this->denunciada('obra', $denunciadaPorElla, self::MODERADORA, '2026-11-02 10:00:00');

        foreach ([$suya, $denunciadaPorElla] as $id) {
            $pantalla = $this->pedir('GET', "/denuncias?tipo=obra&id={$id}", 'moderador')->cuerpo();
            self::assertStringNotContainsString("/denuncias/obra/{$id}/resolver", $pantalla);
            self::assertStringContainsString('Es tuyo o lo denunciaste vos: lo resuelve otro moderador.', $pantalla);
            self::assertSame(403, $this->resolver('obra', $id, ['accion' => 'desestimar', 'motivo' => 'Nada.'])->estado());
        }
        self::assertSame([], $this->cerradas());

        self::assertSame(303, $this->resolver('obra', $suya, ['accion' => 'desestimar', 'motivo' => 'Nada.'], 'administrador')->estado());
        self::assertCount(1, $this->cerradas());
    }

    /**
     * @return iterable<string, array{?string, int}>
     */
    public static function quienEntra(): iterable
    {
        yield 'sin sesión' => [null, 401];
        yield 'espectador' => ['espectador', 403];
        yield 'organizador' => ['organizador', 403];
        yield 'moderador' => ['moderador', 200];
        yield 'administrador' => ['administrador', 200];
    }

    #[DataProvider('quienEntra')]
    public function testSoloEntranElModeradorYElAdministrador(?string $rol, int $estado): void
    {
        self::assertSame($estado, $this->pedir('GET', '/denuncias', $rol)->estado());
    }

    /**
     * Rechazar la obra denunciada es el rechazo de EF-07.7: programación la saca del catálogo, queda
     * en el historial de obras y su dueño recibe el correo con el motivo.
     */
    public function testRechazarLaObraDenunciadaLaSacaDelCatalogoYCierraLasDenuncias(): void
    {
        $id = self::obra('La flor');
        $this->denunciada('obra', $id, self::ESPECTADORA, '2026-11-01 10:00:00', 'derechos');
        self::assertStringContainsString('value="rechazar"', $this->pedir('GET', '/denuncias', 'moderador')->cuerpo());

        $respuesta = $this->resolver('obra', $id, ['accion' => 'rechazar', 'motivo' => 'No tiene los derechos de la copia.']);

        self::assertSame("/moderacion/denuncias?resueltas={$id}", $respuesta->cabecera('Location'));
        self::assertSame([['ruta' => 'revision', 'obra' => $id, 'resolucion' => 'rechazar', 'motivo' => 'No tiene los derechos de la copia.', 'moderador_id' => self::MODERADORA]], self::pedidosA('programacion'));
        self::assertSame('rechazada', self::obrasDeProgramacion()[$id]['estado']);
        self::assertSame('rechazada', $this->base->valor('SELECT resolucion FROM revision_obra WHERE obra_id = ?', [$id]));
        $correos = self::correosEnviados();
        self::assertCount(1, $correos);
        self::assertSame('<omar@ejemplo.com>', $correos[0]['para']);
        self::assertStringContainsString('No tiene los derechos de la copia.', $correos[0]['texto']);
        self::assertSame([['objeto_id' => $id, 'estado' => 'resuelta', 'accion' => 'rechazar', 'motivo' => 'No tiene los derechos de la copia.', 'resuelta_por' => self::MODERADORA, 'con_fecha' => 1]], $this->cerradas());
        self::assertStringContainsString('Obra rechazada', $this->pedir('GET', '/denuncias?ver=historial', 'moderador')->cuerpo());
    }

    public function testSiProgramacionNoRespondeNoSeCierraNada(): void
    {
        $id = self::obra('La flor');
        $this->denunciada('obra', $id, self::ESPECTADORA, '2026-11-01 10:00:00');
        self::tirar('programacion');

        self::assertSame(503, $this->resolver('obra', $id, ['accion' => 'rechazar', 'motivo' => 'No.'])->estado());

        self::assertSame([], $this->cerradas());
    }

    /**
     * Q14: si ya la habían rechazado (por la pestaña «Obras» u otra denuncia), las denuncias se
     * cierran sin repetir el rechazo ni el correo.
     */
    public function testUnaObraYaRechazadaCierraLasDenunciasSinRepetir(): void
    {
        $id = self::obra('La flor');
        $this->denunciada('obra', $id, self::ESPECTADORA, '2026-11-01 10:00:00');
        $obras = self::obrasDeProgramacion();
        $obras[$id]['estado'] = 'rechazada';
        self::obrasEnProgramacion($obras);

        $pantalla = $this->pedir('GET', '/denuncias', 'moderador')->cuerpo();
        self::assertStringContainsString('Ya está rechazada: resolverlas así no la vuelve a rechazar.', $pantalla);
        self::assertStringContainsString('value="rechazar"', $pantalla);

        self::assertSame(303, $this->resolver('obra', $id, ['accion' => 'rechazar', 'motivo' => 'Ya la rechazaron.'])->estado());
        self::assertSame([], self::pedidosA('programacion'));
        self::assertSame([], self::correosEnviados());
        self::assertSame(['resuelta', 'rechazar'], [$this->cerradas()[0]['estado'], $this->cerradas()[0]['accion']]);
    }

    /**
     * Q15: si ya no existe (su dueño la dio de baja), sólo se desestima.
     */
    public function testUnaObraQueYaNoEstaSoloSeDesestima(): void
    {
        $id = self::obra('La flor');
        $this->denunciada('obra', $id, self::ESPECTADORA, '2026-11-01 10:00:00');
        self::obrasEnProgramacion([]);

        $pantalla = $this->pedir('GET', '/denuncias', 'moderador')->cuerpo();
        self::assertStringContainsString('La flor', $pantalla);
        self::assertStringContainsString('Ya no está publicada', $pantalla);

        self::assertSame(422, $this->resolver('obra', $id, ['accion' => 'rechazar', 'motivo' => 'No.'])->estado());
        self::assertSame(303, $this->resolver('obra', $id, ['accion' => 'desestimar', 'motivo' => 'Ya no está.'])->estado());
        self::assertSame('desestimada', $this->cerradas()[0]['estado']);
    }

    public function testUnaSalaSeDenunciaTambienPorLaSeguridadDelLugar(): void
    {
        $id = self::sala('El Galpón');

        $formulario = $this->pedir('GET', "/denuncias/nueva?tipo=sala&id={$id}");

        self::assertSame(200, $formulario->estado(), $formulario->cuerpo());
        self::assertStringContainsString('El Galpón', $formulario->cuerpo());
        self::assertStringContainsString('Seguridad del lugar', $formulario->cuerpo());
        self::assertSame(303, $this->denunciar('sala', $id, ['categoria' => 'seguridad', 'detalle' => 'No hay salida de emergencia.'])->estado());
        self::assertStringContainsString("href=\"/salas/{$id}\"", $this->pedir('GET', "/denuncias/nueva?tipo=sala&id={$id}&enviada=1")->cuerpo());

        self::assertSame(404, $this->pedir('GET', '/denuncias/nueva?tipo=sala&id=' . self::sala('Sin publicar', habilitada: false))->estado());
        self::assertSame(404, $this->pedir('GET', '/denuncias/nueva?tipo=sala&id=' . self::sala('La mía', self::ESPECTADORA))->estado());
    }

    /**
     * Suspender abre la suspensión con causa denuncia (EF-07.5): programación la apaga y el
     * organizador recibe el motivo, sin quién denunció ni qué escribió.
     */
    public function testSuspenderLaSalaDenunciadaLaApagaYLeAvisaAlOrganizador(): void
    {
        $id = self::sala('El Galpón');
        $this->denunciada('sala', $id, self::ESPECTADORA, '2026-11-01 10:00:00', 'seguridad', 'No hay salida de emergencia.');
        self::assertStringContainsString('value="suspender"', $this->pedir('GET', '/denuncias', 'moderador')->cuerpo());

        $respuesta = $this->resolver('sala', $id, ['accion' => 'suspender', 'motivo' => 'Falta la salida de emergencia.']);

        self::assertSame("/moderacion/denuncias?resueltas={$id}", $respuesta->cabecera('Location'));
        self::assertSame([['ruta' => 'habilitacion', 'sala' => $id, 'habilitada' => false, 'motivo' => 'Falta la salida de emergencia.', 'moderador_id' => self::MODERADORA]], self::pedidosA('programacion'));
        self::assertSame([['causa' => 'denuncia', 'motivo' => 'Falta la salida de emergencia.', 'abierta_por' => self::MODERADORA, 'activa' => 1]], $this->suspensiones($id));
        self::assertSame(['resuelta', 'suspender'], [$this->cerradas()[0]['estado'], $this->cerradas()[0]['accion']]);
        $correos = self::correosEnviados();
        self::assertCount(1, $correos);
        self::assertSame('<omar@ejemplo.com>', $correos[0]['para']);
        self::assertSame('Suspendimos la sala «El Galpón»', $correos[0]['asunto']);
        self::assertStringContainsString('Falta la salida de emergencia.', $correos[0]['texto']);
        self::assertStringContainsString("/moderacion/salas/{$id}/documentacion", $correos[0]['texto']);
        self::assertStringNotContainsString('No hay salida de emergencia.', $correos[0]['texto']);
        self::assertStringNotContainsString('Eva', $correos[0]['texto']);
    }

    /**
     * Q14: con una suspensión por denuncia activa, las que quedaban abiertas se cierran sin abrir
     * otra ni mandar otro correo.
     */
    public function testUnaSalaYaSuspendidaPorDenunciaCierraSinRepetir(): void
    {
        $id = self::sala('El Galpón');
        $this->denunciada('sala', $id, self::ESPECTADORA, '2026-11-01 10:00:00');
        $this->resolver('sala', $id, ['accion' => 'suspender', 'motivo' => 'Primera.']);
        self::$correo->vaciar();
        file_put_contents(self::$registros['programacion'], '');
        // Una que ya estaba abierta cuando la suspendieron por otro lado (desde una función): una
        // suspendida no se puede denunciar.
        $this->base->ejecutar(
            "INSERT INTO denuncia (id, tipo, objeto_id, sala_id, titulo, usuario_id, categoria, gravedad, creada_en) VALUES (?, 'sala', ?, ?, 'El Galpón', ?, 'ofensivo', 'alta', '2026-11-02 10:00:00')",
            [Uuid::nuevo(), $id, $id, self::ADMINISTRADOR],
        );

        self::assertStringContainsString('Ya está suspendida por una denuncia', $this->pedir('GET', '/denuncias', 'moderador')->cuerpo());
        self::assertSame(303, $this->resolver('sala', $id, ['accion' => 'suspender', 'motivo' => 'Segunda.'])->estado());

        self::assertCount(1, $this->suspensiones($id));
        self::assertSame([], self::pedidosA('programacion'));
        self::assertSame([], self::correosEnviados());
        self::assertSame(['resuelta', 'resuelta'], array_column($this->cerradas(), 'estado'));
    }

    /**
     * Q15: una retirada o suspendida por otra causa sigue existiendo: se suspende igual (EF-07.5,
     * Q2: cada causa con su suspensión).
     */
    public function testUnaSalaRetiradaIgualSeSuspendePorDenuncia(): void
    {
        $id = self::sala('El Galpón');
        $this->denunciada('sala', $id, self::ESPECTADORA, '2026-11-01 10:00:00');
        $salas = self::salasDeProgramacion();
        $salas[$id]['habilitada'] = false;
        self::salasEnProgramacion($salas);

        self::assertSame(303, $this->resolver('sala', $id, ['accion' => 'suspender', 'motivo' => 'Igual.'])->estado());

        self::assertSame('denuncia', $this->suspensiones($id)[0]['causa']);
    }

    /**
     * Una sala suspendida por denuncia, como la deja resolver una.
     */
    private function suspendida(string $nombre, string $organizador = self::ORGANIZADOR): string
    {
        $id = self::sala($nombre, $organizador);
        $this->denunciada('sala', $id, self::ESPECTADORA, '2026-11-01 10:00:00');
        self::assertSame(303, $this->resolver('sala', $id, ['accion' => 'suspender', 'motivo' => 'Falta la salida de emergencia.'], 'administrador')->estado());
        file_put_contents(self::$registros['programacion'], '');

        return $id;
    }

    /**
     * La pestaña lista las salas suspendidas por denuncia, y levantar la suspensión la vuelve a
     * publicar si era la última activa (EF-07.5).
     */
    public function testLevantarLaSuspensionPorDenunciaVuelveAPublicarLaSala(): void
    {
        $id = $this->suspendida('El Galpón');

        $pantalla = $this->pedir('GET', '/denuncias', 'moderador')->cuerpo();
        self::assertStringContainsString('Salas suspendidas por denuncia', $pantalla);
        self::assertStringContainsString('El Galpón', $pantalla);
        self::assertStringContainsString('Falta la salida de emergencia.', $pantalla);
        self::assertStringContainsString("/moderacion/denuncias/salas/{$id}/levantar", $pantalla);

        $respuesta = $this->pedir('POST', "/denuncias/salas/{$id}/levantar", 'moderador', ['_csrf' => self::CSRF, 'motivo' => 'Ya instalaron la salida.']);

        self::assertSame(303, $respuesta->estado(), $respuesta->cuerpo());
        self::assertSame("/moderacion/denuncias?levantada={$id}", $respuesta->cabecera('Location'));
        self::assertSame([['ruta' => 'habilitacion', 'sala' => $id, 'habilitada' => true, 'motivo' => 'Ya instalaron la salida.', 'moderador_id' => self::MODERADORA]], self::pedidosA('programacion'));
        self::assertSame(0, $this->suspensiones($id)[0]['activa']);
        $despues = $this->pedir('GET', "/denuncias?levantada={$id}", 'moderador')->cuerpo();
        self::assertStringContainsString('Levantaste la suspensión de «El Galpón».', $despues);
        self::assertStringNotContainsString("/denuncias/salas/{$id}/levantar", $despues);
    }

    /**
     * Con otra suspensión activa (un documento vencido), la sala sigue apagada (EF-07.5, Q2).
     */
    public function testConOtraSuspensionActivaLaSalaSigueApagada(): void
    {
        $id = $this->suspendida('El Galpón');
        $this->base->ejecutar(
            "INSERT INTO suspension (id, sala_id, causa, tipo_documento, motivo, desde) VALUES (?, ?, 'vencimiento', 'seguro', 'Venció el seguro.', '2026-11-02 10:00:00')",
            [Uuid::nuevo(), $id],
        );

        self::assertSame(303, $this->pedir('POST', "/denuncias/salas/{$id}/levantar", 'moderador', ['_csrf' => self::CSRF, 'motivo' => 'Resuelto.'])->estado());

        self::assertSame([], self::pedidosA('programacion'));
        self::assertSame(['vencimiento' => 1, 'denuncia' => 0], array_column($this->suspensiones($id), 'activa', 'causa'));
    }

    public function testLevantarPideElMotivo(): void
    {
        $id = $this->suspendida('El Galpón');

        $respuesta = $this->pedir('POST', "/denuncias/salas/{$id}/levantar", 'moderador', ['_csrf' => self::CSRF, 'motivo' => ' ']);

        self::assertSame(422, $respuesta->estado());
        self::assertStringContainsString('Escribí el motivo', $respuesta->cuerpo());
        self::assertSame(1, $this->suspensiones($id)[0]['activa']);
    }

    /**
     * Q1 de EF-07: la de su propia sala la levanta otro moderador.
     */
    public function testElModeradorNoLevantaLaDeSuSala(): void
    {
        $id = $this->suspendida('La de Mora', self::MODERADORA);

        self::assertStringNotContainsString("/denuncias/salas/{$id}/levantar", $this->pedir('GET', '/denuncias', 'moderador')->cuerpo());
        self::assertSame(403, $this->pedir('POST', "/denuncias/salas/{$id}/levantar", 'moderador', ['_csrf' => self::CSRF, 'motivo' => 'Listo.'])->estado());
        self::assertSame(303, $this->pedir('POST', "/denuncias/salas/{$id}/levantar", 'administrador', ['_csrf' => self::CSRF, 'motivo' => 'Listo.'])->estado());
    }

    /**
     * Se denuncia la función de la cartelera: en votación, en acuerdo o programada a futuro, en una
     * sala publicada.
     */
    public function testUnaFuncionDeLaCarteleraSeDenuncia(): void
    {
        $sala = self::sala('El Galpón');
        $obras = [self::obra('Zama'), self::obra('La ciénaga')];
        $enVotacion = self::funcion($sala, $obras);

        $formulario = $this->pedir('GET', "/denuncias/nueva?tipo=funcion&id={$enVotacion}");
        self::assertSame(200, $formulario->estado(), $formulario->cuerpo());
        self::assertStringContainsString('Zama + La ciénaga', $formulario->cuerpo());
        self::assertStringContainsString('Seguridad del lugar', $formulario->cuerpo());
        self::assertSame(303, $this->denunciar('funcion', $enVotacion, ['categoria' => 'falsa', 'detalle' => ''])->estado());
        self::assertStringContainsString("href=\"/cartelera/{$enVotacion}\"", $this->pedir('GET', "/denuncias/nueva?tipo=funcion&id={$enVotacion}&enviada=1")->cuerpo());

        self::assertSame(200, $this->pedir('GET', '/denuncias/nueva?tipo=funcion&id=' . self::funcion($sala, $obras, 'programada', '2099-01-10T20:00:00'))->estado());
        foreach ([self::funcion($sala, $obras, 'cancelada'), self::funcion($sala, $obras, 'finalizada', '2026-01-10T20:00:00'), self::funcion($sala, $obras, 'programada', '2020-01-10T20:00:00')] as $fuera) {
            self::assertSame(404, $this->pedir('GET', "/denuncias/nueva?tipo=funcion&id={$fuera}")->estado());
        }
        self::assertSame(404, $this->pedir('GET', '/denuncias/nueva?tipo=funcion&id=' . self::funcion(self::sala('La mía', self::ESPECTADORA), $obras))->estado());
    }

    /**
     * La función en sí no se cancela (IC-549 Q3): se suspende su sala o se rechaza una de sus
     * obras, que se elige.
     */
    public function testLaFuncionDenunciadaSuspendeSuSalaORechazaUnaDeSusObras(): void
    {
        $sala = self::sala('El Galpón');
        [$zama, $cienaga] = [self::obra('Zama'), self::obra('La ciénaga')];
        $funcion = self::funcion($sala, [$zama, $cienaga]);
        $this->denunciada('funcion', $funcion, self::ESPECTADORA, '2026-11-01 10:00:00');

        $pantalla = $this->pedir('GET', '/denuncias', 'moderador')->cuerpo();
        self::assertStringContainsString('value="suspender"', $pantalla);
        self::assertStringContainsString("value=\"rechazar:{$zama}\"", $pantalla);
        self::assertStringContainsString("value=\"rechazar:{$cienaga}\"", $pantalla);
        self::assertSame(422, $this->resolver('funcion', $funcion, ['accion' => 'rechazar:' . self::obra('Otra'), 'motivo' => 'No.'])->estado());

        self::assertSame(303, $this->resolver('funcion', $funcion, ['accion' => "rechazar:{$cienaga}", 'motivo' => 'No tiene los derechos.'])->estado());

        self::assertSame('rechazada', self::obrasDeProgramacion()[$cienaga]['estado']);
        self::assertSame('aprobada', self::obrasDeProgramacion()[$zama]['estado']);
        self::assertSame([['ruta' => 'revision', 'obra' => $cienaga, 'resolucion' => 'rechazar', 'motivo' => 'No tiene los derechos.', 'moderador_id' => self::MODERADORA]], self::pedidosA('programacion'));
        self::assertSame([[$cienaga, 'rechazar']], array_map(null, ...[array_column($this->base->filas('SELECT obra_rechazada_id FROM denuncia'), 'obra_rechazada_id'), array_column($this->cerradas(), 'accion')]));

        $otra = self::funcion($sala, [$zama]);
        $this->denunciada('funcion', $otra, self::ESPECTADORA, '2026-11-02 10:00:00');
        self::assertSame(303, $this->resolver('funcion', $otra, ['accion' => 'suspender', 'motivo' => 'Falta la salida de emergencia.'])->estado());
        self::assertSame('denuncia', $this->suspensiones($sala)[0]['causa']);
    }

    public function testElModeradorNoResuelveLaFuncionDeSuSala(): void
    {
        $funcion = self::funcion(self::sala('La de Mora', self::MODERADORA), [self::obra('Zama')]);
        $this->denunciada('funcion', $funcion, self::ESPECTADORA, '2026-11-01 10:00:00');

        self::assertSame(403, $this->resolver('funcion', $funcion, ['accion' => 'desestimar', 'motivo' => 'Nada.'])->estado());
    }

    /**
     * Un comentario de una calificación se denuncia de a uno (Q1); su autor no lo denuncia, y uno
     * oculto ya no se ve.
     */
    public function testUnComentarioSeDenunciaSalvoSuAutor(): void
    {
        $sala = self::sala('El Galpón');
        $id = self::comentario($sala, 'Un insulto.');

        $formulario = $this->pedir('GET', "/denuncias/nueva?tipo=calificacion&id={$id}");
        self::assertSame(200, $formulario->estado(), $formulario->cuerpo());
        self::assertStringContainsString('Comentario de Omar', $formulario->cuerpo());
        self::assertStringNotContainsString('Seguridad del lugar', $formulario->cuerpo());
        self::assertSame(303, $this->denunciar('calificacion', $id, ['categoria' => 'ofensivo', 'detalle' => ''])->estado());
        self::assertStringContainsString("href=\"/salas/{$sala}\"", $this->pedir('GET', "/denuncias/nueva?tipo=calificacion&id={$id}&enviada=1")->cuerpo());

        self::assertSame(404, $this->pedir('GET', "/denuncias/nueva?tipo=calificacion&id={$id}", usuario: self::ORGANIZADOR)->estado());
        self::assertSame(404, $this->pedir('GET', '/denuncias/nueva?tipo=calificacion&id=' . self::comentario($sala, 'Otro.', oculto: true))->estado());
    }

    /**
     * Ocultar pide a funciones que lo saque de las fichas; el puntaje sigue (Q11).
     */
    public function testOcultarElComentarioDenunciadoLoPideAFunciones(): void
    {
        $id = self::comentario(self::sala('El Galpón'), 'Un insulto.');
        $this->denunciada('calificacion', $id, self::ESPECTADORA, '2026-11-01 10:00:00');

        $pantalla = $this->pedir('GET', '/denuncias', 'moderador')->cuerpo();
        self::assertStringContainsString('Un insulto.', $pantalla);
        self::assertStringContainsString('value="ocultar"', $pantalla);

        self::assertSame(303, $this->resolver('calificacion', $id, ['accion' => 'ocultar', 'motivo' => 'Insulta a la sala.'])->estado());

        self::assertSame([['ruta' => 'ocultar', 'calificacion' => $id, 'moderador_id' => self::MODERADORA]], self::pedidosA('funciones'));
        self::assertTrue(self::calificacionesDeFunciones()[$id]['oculto']);
        self::assertSame(['resuelta', 'ocultar'], [$this->cerradas()[0]['estado'], $this->cerradas()[0]['accion']]);
    }

    public function testSiFuncionesNoRespondeNoSeOcultaNiSeCierra(): void
    {
        $id = self::comentario(self::sala('El Galpón'), 'Un insulto.');
        $this->denunciada('calificacion', $id, self::ESPECTADORA, '2026-11-01 10:00:00');
        self::tirar('funciones');

        self::assertSame(503, $this->resolver('calificacion', $id, ['accion' => 'ocultar', 'motivo' => 'No.'])->estado());

        self::assertSame([], $this->cerradas());
    }

    /**
     * Q14: si ya está oculto, se cierran sin pedirlo otra vez.
     */
    public function testUnComentarioYaOcultoCierraSinRepetir(): void
    {
        $id = self::comentario(self::sala('El Galpón'), 'Un insulto.');
        $this->denunciada('calificacion', $id, self::ESPECTADORA, '2026-11-01 10:00:00');
        $calificaciones = self::calificacionesDeFunciones();
        $calificaciones[$id]['oculto'] = true;
        self::calificacionesEnFunciones($calificaciones);

        self::assertStringContainsString('Ya está oculto', $this->pedir('GET', '/denuncias', 'moderador')->cuerpo());
        self::assertSame(303, $this->resolver('calificacion', $id, ['accion' => 'ocultar', 'motivo' => 'Ya estaba.'])->estado());

        self::assertSame([], self::pedidosA('funciones'));
        self::assertSame('resuelta', $this->cerradas()[0]['estado']);
    }
}
