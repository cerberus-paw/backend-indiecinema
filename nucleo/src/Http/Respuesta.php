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
        http_response_code($this->estado);
        foreach ($this->cabeceras as $nombre => $valor) {
            header("{$nombre}: {$valor}");
        }
        foreach ($this->cookies as $cookie) {
            setcookie($cookie['nombre'], $cookie['valor'], $cookie['opciones']);
        }
        echo $this->cuerpo;
    }
}
