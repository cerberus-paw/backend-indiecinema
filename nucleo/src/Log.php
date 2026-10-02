<?php

declare(strict_types=1);

namespace IndieCinema\Nucleo;

use Psr\Log\AbstractLogger;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LogLevel;
use Stringable;
use Throwable;

/**
 * El log del subsistema (PSR-3): una línea por entrada en la salida de errores, que en el
 * contenedor es lo que muestra `docker compose logs`. Así no hay archivos que rotar ni un volumen
 * más, y nginx y PHP quedan en el mismo lugar.
 *
 * Del contexto sólo se escribe lo que nombra el mensaje entre llaves, más la excepción si la hay:
 * lo que no está en el mensaje no llega al log, así nadie registra un dato personal sin querer.
 */
final class Log extends AbstractLogger
{
    private const NIVELES = [
        LogLevel::EMERGENCY,
        LogLevel::ALERT,
        LogLevel::CRITICAL,
        LogLevel::ERROR,
        LogLevel::WARNING,
        LogLevel::NOTICE,
        LogLevel::INFO,
        LogLevel::DEBUG,
    ];

    /** @var resource */
    private $salida;

    /**
     * @param string        $canal  el subsistema: con el servidor local todos escriben en la misma terminal
     * @param resource|null $salida para las pruebas; si no, la salida de errores
     */
    public function __construct(private readonly string $canal, $salida = null)
    {
        $this->salida = $salida ?? fopen('php://stderr', 'w');
    }

    /**
     * @param array<string, mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        if (!in_array($level, self::NIVELES, true)) {
            throw new InvalidArgumentException("Nivel de log desconocido: {$level}.");
        }

        $linea = sprintf(
            '[%s] %s.%s %s',
            date(DATE_ATOM),
            $this->canal,
            strtoupper($level),
            self::escapar(self::interpolar((string) $message, $context)),
        );

        // La traza ocupa varias líneas: van con sangría para que se vea a qué entrada pertenecen.
        $excepcion = $context['exception'] ?? null;
        if ($excepcion instanceof Throwable) {
            $linea .= "\n    " . str_replace("\n", "\n    ", (string) $excepcion);
        }

        fwrite($this->salida, $linea . "\n");
    }

    /**
     * @param array<string, mixed> $contexto
     */
    private static function interpolar(string $mensaje, array $contexto): string
    {
        return preg_replace_callback('/\{([\w.]+)\}/', static function (array $coincidencia) use ($contexto): string {
            $valor = $contexto[$coincidencia[1]] ?? null;

            return match (true) {
                $valor === null => $coincidencia[0],
                is_bool($valor) => $valor ? 'true' : 'false',
                is_scalar($valor), $valor instanceof Stringable => (string) $valor,
                default => '[' . get_debug_type($valor) . ']',
            };
        }, $mensaje) ?? $mensaje;
    }

    /**
     * Un valor que vino del usuario (una ruta, por ejemplo) no puede cortar la línea e inventar
     * una entrada que parezca otra.
     */
    private static function escapar(string $texto): string
    {
        return addcslashes($texto, "\0..\37\177");
    }
}
