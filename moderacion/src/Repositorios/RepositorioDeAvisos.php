<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Repositorios;

use DateTimeImmutable;
use IndieCinema\Nucleo\BaseDeDatos;
use PDOException;

/**
 * Los correos que ya salieron a cada sala (EF-07.5), para no repetirlos.
 */
final class RepositorioDeAvisos
{
    /** El error de MySQL de una clave única repetida: el aviso ya salió. */
    private const CLAVE_DUPLICADA = 1062;

    public function __construct(private readonly BaseDeDatos $base)
    {
    }

    /**
     * Lo anota antes de mandarlo: si dos vueltas de la tarea se cruzan, sólo una lo manda.
     *
     * @return bool false si ya había salido
     */
    public function reservar(string $salaId, string $clave, DateTimeImmutable $cuando): bool
    {
        try {
            $this->base->ejecutar('INSERT INTO aviso (sala_id, clave, enviado_en) VALUES (?, ?, ?)', [$salaId, $clave, $cuando]);
        } catch (PDOException $error) {
            if (($error->errorInfo[1] ?? null) === self::CLAVE_DUPLICADA) {
                return false;
            }
            throw $error;
        }

        return true;
    }

    /**
     * Si el correo no salió: la próxima vuelta lo reintenta.
     */
    public function liberar(string $salaId, string $clave): void
    {
        $this->base->ejecutar('DELETE FROM aviso WHERE sala_id = ? AND clave = ?', [$salaId, $clave]);
    }
}
