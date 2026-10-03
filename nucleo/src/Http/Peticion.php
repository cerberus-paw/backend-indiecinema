<?php

declare(strict_types=1);

namespace IndieCinema\Nucleo\Http;

use IndieCinema\Nucleo\Ruteo\Ruta;
use IndieCinema\Nucleo\Seguridad\Usuario;

/**
 * La petición HTTP que atiende el subsistema.
 *
 * Es inmutable: el router (y después el middleware) devuelven copias con lo que agregan, así
 * nadie cambia la petición a mitad de camino.
 */
final class Peticion
{
    /** @var array<string, string> */
    private readonly array $cabeceras;

    private ?Ruta $rutaResuelta = null;

    /** @var array<string, string> */
    private array $parametros = [];

    private ?Usuario $usuario = null;

    private ?string $tokenCsrf = null;

    private ?string $llamador = null;

    /**
     * @param string                              $ruta      la ruta sin el prefijo del subsistema, que nginx ya sacó
     * @param array<string, mixed>                $consulta  los parámetros de la URL
     * @param array<string, mixed>                $cuerpo    los campos del formulario, o el JSON ya decodificado
     * @param array<string, string>               $cabeceras
     * @param array<string, string>               $cookies
     * @param array<string, array<string, mixed>> $archivos  los subidos, como los arma PHP (ver archivo())
     */
    public function __construct(
        private readonly string $metodo,
        private readonly string $ruta,
        private readonly array $consulta = [],
        private readonly array $cuerpo = [],
        array $cabeceras = [],
        private readonly array $cookies = [],
        private readonly array $archivos = [],
    ) {
        $this->cabeceras = array_change_key_case($cabeceras, CASE_LOWER);
    }

    public static function desdeGlobales(): self
    {
        $cabeceras = [];
        foreach ($_SERVER as $clave => $valor) {
            if (str_starts_with($clave, 'HTTP_')) {
                $cabeceras[str_replace('_', '-', substr($clave, 5))] = (string) $valor;
            }
        }
        // Estas dos no llegan con el prefijo HTTP_.
        foreach (['CONTENT_TYPE', 'CONTENT_LENGTH'] as $clave) {
            if (isset($_SERVER[$clave])) {
                $cabeceras[str_replace('_', '-', $clave)] = (string) $_SERVER[$clave];
            }
        }
        // Apache no pone Authorization en $_SERVER (se la esconde a los scripts), y es la que
        // trae el token de las llamadas a /interno/. getallheaders() sí la tiene.
        if (!isset($cabeceras['AUTHORIZATION']) && function_exists('getallheaders')) {
            foreach (getallheaders() as $nombre => $valor) {
                if (strcasecmp((string) $nombre, 'Authorization') === 0) {
                    $cabeceras['AUTHORIZATION'] = (string) $valor;
                }
            }
        }

        $cuerpo = $_POST;
        // Las APIs internas mandan JSON, que PHP no pone en $_POST.
        if (str_contains(strtolower($cabeceras['CONTENT-TYPE'] ?? ''), 'application/json')) {
            $json = json_decode((string) file_get_contents('php://input'), true);
            $cuerpo = is_array($json) ? $json : [];
        }

        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            self::normalizarRuta((string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/')),
            $_GET,
            $cuerpo,
            $cabeceras,
            $_COOKIE,
            self::archivosSubidos($_FILES),
        );
    }

    /**
     * Sólo los archivos que el navegador subió en este pedido, de a uno por campo. Así el resto
     * del código puede mover el archivo temporal sin move_uploaded_file(), que en las pruebas no
     * anda, sin riesgo de que le hagan mover otro archivo del servidor.
     *
     * @param array<string, mixed> $archivos
     *
     * @return array<string, array{name: string, type: string, tmp_name: string, error: int, size: int}>
     */
    private static function archivosSubidos(array $archivos): array
    {
        return array_filter($archivos, static fn (mixed $archivo): bool => is_array($archivo)
            && is_string($archivo['tmp_name'] ?? null)
            && is_int($archivo['error'] ?? null)
            && ($archivo['error'] !== UPLOAD_ERR_OK || is_uploaded_file($archivo['tmp_name'])));
    }

    /**
     * «/salas/» y «/salas» son la misma ruta, y la raíz siempre es «/».
     */
    public static function normalizarRuta(string $ruta): string
    {
        return '/' . trim(rawurldecode($ruta), '/');
    }

    public function metodo(): string
    {
        return $this->metodo;
    }

    public function ruta(): string
    {
        return $this->ruta;
    }

    public function consulta(string $clave, mixed $porDefecto = null): mixed
    {
        return $this->consulta[$clave] ?? $porDefecto;
    }

    public function campo(string $clave, mixed $porDefecto = null): mixed
    {
        return $this->cuerpo[$clave] ?? $porDefecto;
    }

    /**
     * @return array<string, mixed>
     */
    public function cuerpo(): array
    {
        return $this->cuerpo;
    }

    public function cabecera(string $nombre): ?string
    {
        return $this->cabeceras[strtolower($nombre)] ?? null;
    }

    public function cookie(string $nombre): ?string
    {
        return $this->cookies[$nombre] ?? null;
    }

    /**
     * Un archivo subido, como lo arma PHP (name, type, tmp_name, error, size), o null si el campo
     * no vino. Un campo con varios archivos (imagen[]) no llega.
     *
     * @return array{name: string, type: string, tmp_name: string, error: int, size: int}|null
     */
    public function archivo(string $nombre): ?array
    {
        return $this->archivos[$nombre] ?? null;
    }

    /**
     * Un parámetro de la ruta, como el {id} de «/salas/{id}».
     */
    public function parametro(string $nombre): ?string
    {
        return $this->parametros[$nombre] ?? null;
    }

    public function rutaResuelta(): ?Ruta
    {
        return $this->rutaResuelta;
    }

    /**
     * @param array<string, string> $parametros
     */
    public function conRuta(Ruta $ruta, array $parametros): self
    {
        $copia = clone $this;
        $copia->rutaResuelta = $ruta;
        $copia->parametros = $parametros;

        return $copia;
    }

    /**
     * Quien hace la petición, o null si no inició sesión. Lo arma el middleware con las cabeceras
     * de nginx.
     */
    public function usuario(): ?Usuario
    {
        return $this->usuario;
    }

    public function conUsuario(?Usuario $usuario): self
    {
        $copia = clone $this;
        $copia->usuario = $usuario;

        return $copia;
    }

    /**
     * El token CSRF que llevan los formularios de esta página. Null en las rutas /interno/.
     */
    public function tokenCsrf(): ?string
    {
        return $this->tokenCsrf;
    }

    public function conTokenCsrf(string $token): self
    {
        $copia = clone $this;
        $copia->tokenCsrf = $token;

        return $copia;
    }

    /**
     * En una ruta /interno/, el subsistema que llama (ya autenticado por su token); null en las
     * demás.
     */
    public function llamador(): ?string
    {
        return $this->llamador;
    }

    public function conLlamador(string $llamador): self
    {
        $copia = clone $this;
        $copia->llamador = $llamador;

        return $copia;
    }

    /**
     * Si el que llama espera JSON: el JavaScript propio y las APIs internas.
     */
    public function aceptaJson(): bool
    {
        return str_contains(strtolower($this->cabecera('Accept') ?? ''), 'application/json');
    }
}
