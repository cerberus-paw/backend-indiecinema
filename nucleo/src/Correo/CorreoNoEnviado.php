<?php

declare(strict_types=1);

namespace IndieCinema\Nucleo\Correo;

use RuntimeException;

/**
 * El servidor de correo no respondió o no aceptó el correo. Quien lo manda decide qué decirle al
 * usuario; el motivo queda en el log.
 */
final class CorreoNoEnviado extends RuntimeException
{
}
