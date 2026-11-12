<?php

declare(strict_types=1);

namespace IndieCinema\Nucleo\Http;

use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Seguridad\ControlDeLlamadores;
use CURLFile;
use JsonException;
use LogicException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use SensitiveParameter;

/**
 * Llama a la API interna de otro subsistema por la red del compose, con el nombre y el token de
 * este (E2, sección 4: cada llamada lleva el token del subsistema que llama). Usa el curl de PHP,
 * sin librerías.
 *
 * Si el otro no responde, no deja la página colgada: espera como mucho dos segundos y corta con
 * un 503. Un controlador que puede mostrar la página sin ese dato (la cartelera sin los lugares
 * libres, por ejemplo) atrapa la ExcepcionHttp y sigue.
 */
final class ClienteInterno
{
    /** Cuánto espera un pedido con un archivo de unos megas, como la foto de una sala. */
    private const ESPERA_CON_ARCHIVO_MS = 10_000;

    /**
     * @param string                $llamador este subsistema, como lo conocen los demás
     * @param array<string, string> $urls     subsistema => URL base (sección [subsistemas])
     */
    public function __construct(
        private readonly string $llamador,
        #[SensitiveParameter] private readonly string $token,
        private readonly array $urls,
        private readonly LoggerInterface $log,
        private readonly int $esperaMaximaMs = 2000,
    ) {
    }

    public static function desdeConfiguracion(Configuracion $configuracion, LoggerInterface $log): self
    {
        /** @var array<string, string> $urls */
        $urls = $configuracion->seccion('subsistemas');

        return new self(
            (string) $configuracion->requerir('app.subsistema'),
            (string) $configuracion->requerir('interno.token'),
            $urls,
            $log,
        );
    }

    /**
     * @param array<string, scalar> $consulta los parámetros de la URL
     *
     * @return mixed el JSON de la respuesta ya decodificado, o null si el recurso no existe (404)
     *
     * @throws ExcepcionHttp    503 si el otro subsistema no responde a tiempo o falla
     * @throws RuntimeException si responde otro error, que es un problema nuestro (un token mal
     *                          configurado da 403, por ejemplo) y termina como un 500 en el log
     */
    public function get(string $subsistema, string $ruta, array $consulta = []): mixed
    {
        $url = $this->url($subsistema, $ruta) . ($consulta === [] ? '' : '?' . http_build_query($consulta));

        return $this->pedir('GET', $subsistema, $ruta, $url, null);
    }

    /**
     * @param array<string, mixed> $datos se mandan como JSON
     *
     * @return mixed como en get()
     */
    public function post(string $subsistema, string $ruta, array $datos = []): mixed
    {
        return $this->pedir('POST', $subsistema, $ruta, $this->url($subsistema, $ruta), json_encode($datos, JSON_THROW_ON_ERROR));
    }

    /**
     * Un POST multipart, como un formulario con un archivo: los campos y el archivo, que al otro
     * lado llega como subido. Es para lo que no entra en un JSON, como la foto de una sala, que en
     * base64 pasa el post_max_size de PHP (EF-07.1). Espera más que los demás pedidos, porque
     * manda unos megas.
     *
     * @param array<string, scalar> $campos
     *
     * @return mixed como en get()
     */
    public function postConArchivo(string $subsistema, string $ruta, array $campos, string $campoDelArchivo, string $archivo): mixed
    {
        $cuerpo = array_map(strval(...), $campos);
        $cuerpo[$campoDelArchivo] = new CURLFile($archivo, (string) mime_content_type($archivo), basename($archivo));

        return $this->pedir('POST', $subsistema, $ruta, $this->url($subsistema, $ruta), $cuerpo, max($this->esperaMaximaMs, self::ESPERA_CON_ARCHIVO_MS));
    }

    private function url(string $subsistema, string $ruta): string
    {
        if (!str_starts_with($ruta, '/interno/')) {
            throw new LogicException("La API interna sólo tiene rutas /interno/, no {$ruta}.");
        }
        $base = $this->urls[$subsistema] ?? throw new LogicException("Falta «subsistemas.{$subsistema}» en config.ini.");

        return rtrim($base, '/') . $ruta;
    }

    /**
     * @param string|array<string, string|CURLFile>|null $cuerpo JSON, o los campos de un multipart
     *                                                           (curl pone el Content-Type)
     */
    private function pedir(string $metodo, string $subsistema, string $ruta, string $url, string|array|null $cuerpo, ?int $esperaMaximaMs = null): mixed
    {
        $esperaMaximaMs ??= $this->esperaMaximaMs;
        $cabeceras = [
            'Authorization: Bearer ' . $this->token,
            ControlDeLlamadores::CABECERA_LLAMADOR . ': ' . $this->llamador,
            'Accept: application/json',
        ];
        if (is_string($cuerpo)) {
            $cabeceras[] = 'Content-Type: application/json';
        }

        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $metodo,
            CURLOPT_HTTPHEADER => $cabeceras,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT_MS => $esperaMaximaMs,
            CURLOPT_TIMEOUT_MS => $esperaMaximaMs,
        ]);
        if ($cuerpo !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $cuerpo);
        }
        $cuerpo = curl_exec($curl);
        $estado = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $contexto = ['subsistema' => $subsistema, 'metodo' => $metodo, 'ruta' => $ruta];

        if (!is_string($cuerpo)) {
            $this->log->warning('{subsistema} no respondió a {metodo} {ruta}: {motivo}', $contexto + ['motivo' => curl_error($curl)]);

            throw ExcepcionHttp::servicioNoDisponible();
        }
        if ($estado >= 500) {
            $this->log->warning('{subsistema} respondió {estado} a {metodo} {ruta}.', $contexto + ['estado' => $estado]);

            throw ExcepcionHttp::servicioNoDisponible();
        }
        if ($estado === 404) {
            return null;
        }
        if ($estado < 200 || $estado >= 300) {
            throw new RuntimeException("{$subsistema} respondió {$estado} a {$metodo} {$ruta}"
                . ($estado === 403 ? ": revisar el token de {$this->llamador} y que la ruta lo acepte." : '.'));
        }

        try {
            return $cuerpo === '' ? null : json_decode($cuerpo, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException("{$subsistema} no respondió JSON a {$metodo} {$ruta}.", 0, $error);
        }
    }
}
