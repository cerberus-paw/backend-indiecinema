<?php

declare(strict_types=1);

namespace IndieCinema\Nucleo\Http;

/**
 * La respuesta que arma un controlador. Es inmutable, como la petición: cada «con…» devuelve
 * una copia.
 */
final class Respuesta
{
    /** @var array<string, string> */
    private array $cabeceras = [];

    /** @var list<array{nombre: string, valor: string, opciones: array<string, mixed>}> */
    private array $cookies = [];

    /** @var list<callable(): mixed> */
    private array $alTerminar = [];

    public function __construct(
        private readonly string $cuerpo = '',
        private readonly int $estado = 200,
    ) {
    }

    public static function html(string $html, int $estado = 200): self
    {
        return (new self($html, $estado))->conCabecera('Content-Type', 'text/html; charset=utf-8');
    }

    public static function json(mixed $datos, int $estado = 200): self
    {
        $json = json_encode($datos, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return (new self($json, $estado))->conCabecera('Content-Type', 'application/json; charset=utf-8');
    }

    /**
     * 303 por defecto: después de un POST el navegador pide la página nueva con GET, y recargar
     * no vuelve a mandar el formulario.
     */
    public static function redireccion(string $url, int $estado = 303): self
    {
        return (new self('', $estado))->conCabecera('Location', $url);
    }

    public function conCabecera(string $nombre, string $valor): self
    {
        $copia = clone $this;
        $copia->cabeceras[$nombre] = $valor;

        return $copia;
    }

    /**
     * @param array<string, mixed> $opciones las de setcookie(): expires, path, secure, httponly, samesite
     */
    public function conCookie(string $nombre, string $valor, array $opciones = []): self
    {
        $copia = clone $this;
        $copia->cookies[] = ['nombre' => $nombre, 'valor' => $valor, 'opciones' => $opciones];

        return $copia;
    }

    /**
     * Algo para hacer después de mandar la respuesta, sin que quien pidió lo espere: un correo,
     * que pasa por cuentas y por el SMTP y puede tardar segundos. Cuando corre ya no hay a quién
     * responderle, así que la tarea maneja sus errores y deja lo suyo en el log.
     *
     * @param callable(): mixed $tarea
     */
    public function alTerminar(callable $tarea): self
    {
        $copia = clone $this;
        $copia->alTerminar[] = $tarea;

        return $copia;
    }

    /**
     * Corre las tareas de alTerminar(). enviar() la llama recién después de soltar la respuesta;
     * las pruebas, que no la envían, la llaman a mano.
     */
    public function terminar(): void
    {
        foreach ($this->alTerminar as $tarea) {
            $tarea();
        }
    }

    public function estado(): int
    {
        return $this->estado;
    }

    public function cuerpo(): string
    {
        return $this->cuerpo;
    }

    public function cabecera(string $nombre): ?string
    {
        foreach ($this->cabeceras as $clave => $valor) {
            if (strcasecmp($clave, $nombre) === 0) {
                return $valor;
            }
        }

        return null;
    }

    /**
     * @return list<array{nombre: string, valor: string, opciones: array<string, mixed>}>
     */
    public function cookies(): array
    {
        return $this->cookies;
    }

    public function enviar(): void
    {
        $conTareas = $this->alTerminar !== [];
        http_response_code($this->estado);
        foreach ($this->cabeceras as $nombre => $valor) {
            header("{$nombre}: {$valor}");
        }
        if ($conTareas) {
            // Con el largo, quien pidió sabe dónde termina la respuesta y no espera a que se cierre
            // la conexión, que sigue abierta mientras corren las tareas. Sin comprimir: mod_deflate
            // cambia el largo por partes y se guarda el final hasta que termina PHP.
            header('Content-Length: ' . strlen($this->cuerpo));
            header('Connection: close');
            if (function_exists('apache_setenv')) {
                apache_setenv('no-gzip', '1');
            }
        }
        foreach ($this->cookies as $cookie) {
            setcookie($cookie['nombre'], $cookie['valor'], $cookie['opciones']);
        }
        echo $this->cuerpo;
        if ($conTareas) {
            self::soltar();
            $this->terminar();
        }
    }

    /**
     * Manda ya lo que quedó en los búferes de PHP y de Apache. Las tareas siguen aunque quien pidió
     * corte, y con su propio tiempo: el max_execution_time de php.ini es para la página.
     */
    private static function soltar(): void
    {
        ignore_user_abort(true);
        set_time_limit(120);
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        flush();
        // Con PHP-FPM, cierra la conexión del todo; la imagen usa mod_php, donde no existe.
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
    }
}
