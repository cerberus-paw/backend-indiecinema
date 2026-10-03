<?php

declare(strict_types=1);

namespace IndieCinema\Nucleo;

/**
 * Ids UUID versión 4 (RFC 9562), que es lo que usa el modelo de la E2 para las entidades.
 *
 * Los genera la aplicación y no la base: al ser al azar, una URL como /salas/{id} no deja
 * adivinar cuántas hay ni recorrerlas cambiando un número.
 */
final class Uuid
{
    private const FORMATO = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D';

    public static function nuevo(): string
    {
        $bytes = random_bytes(16);
        // Los bits que marcan la versión (4: al azar) y la variante (la del RFC).
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /**
     * Para descartar antes de ir a la base un id que no puede existir, como el {id} de una URL
     * escrita a mano.
     */
    public static function esValido(string $uuid): bool
    {
        return preg_match(self::FORMATO, $uuid) === 1;
    }
}
