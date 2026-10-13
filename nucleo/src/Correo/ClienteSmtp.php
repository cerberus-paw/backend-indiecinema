<?php

declare(strict_types=1);

namespace IndieCinema\Nucleo\Correo;

use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Uuid;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use SensitiveParameter;

/**
 * Manda correos de texto por SMTP con el curl de PHP, sin librerías: es el mismo curl de
 * ClienteInterno, que también habla SMTP. A dónde se conecta sale de la sección [correo] de
 * config.ini: en desarrollo, el buzón de prueba del compose; en el VPS, el servidor de correo
 * con su usuario y su clave.
 *
 * Si el servidor no responde, no deja la página colgada: espera como mucho cinco segundos.
 */
final class ClienteSmtp
{
    /**
     * Una dirección como la valida el navegador en un <input type="email">. Sin espacios ni saltos
     * de línea: una dirección no puede colar otra cabecera en el correo.
     */
    private const PATRON_DIRECCION = '/^[a-zA-Z0-9.!#$%&\'*+\/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*\z/';

    /**
     * @param string  $servidor  smtp://servidor:puerto, o smtps:// si es TLS desde el principio
     * @param bool    $starttls  con smtp://, pasar a TLS antes de mandar nada (y si no se puede,
     *                           no mandar)
     * @param string  $remitente la dirección que figura en From y la que se anuncia al servidor
     * @param ?string $usuario   null si el servidor no pide autenticarse, como el buzón de prueba
     *
     * @throws InvalidArgumentException si la configuración mandaría la clave sin cifrar o no es
     *                                  un servidor SMTP
     */
    public function __construct(
        private readonly string $servidor,
        private readonly bool $starttls,
        private readonly string $remitente,
        private readonly string $nombreDelRemitente,
        private readonly ?string $usuario,
        #[SensitiveParameter] private readonly ?string $clave,
        private readonly LoggerInterface $log,
        private readonly int $esperaMaximaMs = 5000,
    ) {
        // curl también sabe leer archivos y hablar HTTP: que la configuración no pueda llevarlo ahí.
        $esquema = strtolower((string) parse_url($servidor, PHP_URL_SCHEME));
        if (!in_array($esquema, ['smtp', 'smtps'], true)) {
            throw new InvalidArgumentException('[correo] servidor tiene que empezar con smtp:// o smtps://.');
        }
        if ($usuario !== null && $esquema === 'smtp' && !$starttls) {
            throw new InvalidArgumentException('Con usuario, [correo] necesita smtps:// o starttls = true: si no, la clave viajaría sin cifrar.');
        }
        self::verificarDireccion($remitente, '[correo] remitente');
    }

    public static function desdeConfiguracion(Configuracion $configuracion, LoggerInterface $log): self
    {
        $usuario = (string) $configuracion->obtener('correo.usuario', '');

        return new self(
            (string) $configuracion->requerir('correo.servidor'),
            $configuracion->obtener('correo.starttls', false) === true,
            (string) $configuracion->requerir('correo.remitente'),
            (string) $configuracion->obtener('correo.nombre', 'IndieCinema'),
            $usuario === '' ? null : $usuario,
            $usuario === '' ? null : (string) $configuracion->requerir('correo.clave'),
            $log,
        );
    }

    /**
     * @throws InvalidArgumentException si una dirección no es válida o el asunto tiene saltos de
     *                                  línea: es un error de quien arma el correo
     * @throws CorreoNoEnviado          si el servidor no responde a tiempo o no lo acepta
     */
    public function enviar(Correo $correo): void
    {
        self::verificarDireccion($correo->para, 'El destinatario');
        if ($correo->responderA !== null) {
            self::verificarDireccion($correo->responderA, 'La dirección de respuesta');
        }
        if (preg_match('/\p{Cc}/u', $correo->asunto) === 1) {
            throw new InvalidArgumentException('El asunto no puede tener saltos de línea ni caracteres de control.');
        }

        $mensaje = $this->mensaje($correo);
        $archivo = fopen('php://temp', 'r+');
        fwrite($archivo, $mensaje);
        rewind($archivo);

        $curl = curl_init($this->servidor);
        curl_setopt_array($curl, [
            CURLOPT_MAIL_FROM => "<{$this->remitente}>",
            CURLOPT_MAIL_RCPT => ["<{$correo->para}>"],
            CURLOPT_UPLOAD => true,
            CURLOPT_INFILE => $archivo,
            CURLOPT_INFILESIZE => strlen($mensaje),
            CURLOPT_USE_SSL => $this->starttls ? CURLUSESSL_ALL : CURLUSESSL_NONE,
            CURLOPT_CONNECTTIMEOUT_MS => $this->esperaMaximaMs,
            CURLOPT_TIMEOUT_MS => $this->esperaMaximaMs,
        ]);
        if ($this->usuario !== null) {
            curl_setopt($curl, CURLOPT_USERNAME, $this->usuario);
            curl_setopt($curl, CURLOPT_PASSWORD, (string) $this->clave);
        }
        $enviado = curl_exec($curl);
        fclose($archivo);

        if ($enviado === false) {
            // Sin las direcciones ni el asunto: pueden tener datos de quien escribió.
            $this->log->warning('El servidor de correo no aceptó el correo: {motivo}', ['motivo' => curl_error($curl)]);

            throw new CorreoNoEnviado('No se pudo mandar el correo.');
        }
    }

    /**
     * El correo entero (RFC 5322), con los saltos de línea de SMTP. El asunto y el nombre van
     * codificados (RFC 2047) y el texto en base64: así viajan las tildes, y ningún renglón del
     * texto puede pasar por una cabecera o por el punto que cierra el mensaje en SMTP.
     */
    private function mensaje(Correo $correo): string
    {
        $dominio = substr($this->remitente, strrpos($this->remitente, '@') + 1);
        $cabeceras = [
            'Date' => date(DATE_RFC2822),
            'From' => mb_encode_mimeheader($this->nombreDelRemitente, 'UTF-8', 'B', "\r\n") . " <{$this->remitente}>",
            'To' => "<{$correo->para}>",
        ];
        if ($correo->responderA !== null) {
            $cabeceras['Reply-To'] = "<{$correo->responderA}>";
        }
        $cabeceras += [
            'Subject' => mb_encode_mimeheader($correo->asunto, 'UTF-8', 'B', "\r\n"),
            'Message-ID' => '<' . Uuid::nuevo() . "@{$dominio}>",
            'MIME-Version' => '1.0',
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Transfer-Encoding' => 'base64',
        ];

        $texto = str_replace(["\r\n", "\r", "\n"], ["\n", "\n", "\r\n"], $correo->texto);
        $lineas = array_map(static fn (string $nombre, string $valor): string => "{$nombre}: {$valor}", array_keys($cabeceras), $cabeceras);

        return implode("\r\n", $lineas) . "\r\n\r\n" . chunk_split(base64_encode($texto), 76, "\r\n");
    }

    private static function verificarDireccion(string $direccion, string $quien): void
    {
        if (strlen($direccion) > 254 || preg_match(self::PATRON_DIRECCION, $direccion) !== 1) {
            throw new InvalidArgumentException("{$quien} no es una dirección de correo válida.");
        }
    }
}
