<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Servicios;

use IndieCinema\Nucleo\Seguridad\Usuario;
use IndieCinema\Nucleo\Uuid;
use IndieCinema\Programacion\Modelo\BloqueoDeSala;
use IndieCinema\Programacion\Modelo\ReglaIncumplida;
use IndieCinema\Programacion\Modelo\Sala;
use IndieCinema\Programacion\Repositorios\RepositorioDeBloqueos;
use Psr\Log\LoggerInterface;

/**
 * Bloqueo de espectadores en las salas de un organizador (EF-12.1 / IC-580).
 * Que la sala exista y sea suya lo controla ServicioDeSalas::paraEditar (404 si no).
 */
final class ServicioDeBloqueos
{
    public function __construct(
        private readonly RepositorioDeBloqueos $bloqueos,
        private readonly ServicioDeSalas $salas,
        private readonly LoggerInterface $log,
    ) {
    }

    /**
     * @return list<BloqueoDeSala>
     */
    public function delOrganizador(Usuario $organizador): array
    {
        return $this->bloqueos->delOrganizador($organizador->id);
    }

    /**
     * @throws ReglaIncumplida si el usuario es inválido, es el propio organizador o falta el motivo
     */
    public function bloquear(Usuario $organizador, string $salaId, string $usuarioId, string $motivo): Sala
    {
        $sala = $this->salas->paraEditar($salaId, $organizador);
        if (!Uuid::esValido($usuarioId)) {
            throw new ReglaIncumplida('Indicá un identificador de usuario válido.');
        }
        if (hash_equals($organizador->id, $usuarioId)) {
            throw new ReglaIncumplida('No podés bloquearte a vos mismo en tu sala.');
        }
        $motivoLimpio = trim($motivo);
        if ($motivoLimpio === '' || mb_strlen($motivoLimpio) > 500) {
            throw new ReglaIncumplida('El motivo del bloqueo es obligatorio (hasta 500 caracteres).');
        }

        $this->bloqueos->guardar($sala->id, $usuarioId, $motivoLimpio, $organizador->id);
        $this->log->info('Usuario bloqueado en sala.', [
            'sala' => $sala->id,
            'usuario' => $usuarioId,
            'organizador' => $organizador->id,
        ]);

        return $sala;
    }

    public function desbloquear(Usuario $organizador, string $salaId, string $usuarioId): Sala
    {
        $sala = $this->salas->paraEditar($salaId, $organizador);
        if (Uuid::esValido($usuarioId) && $this->bloqueos->quitar($sala->id, $usuarioId)) {
            $this->log->info('Usuario desbloqueado en sala.', [
                'sala' => $sala->id,
                'usuario' => $usuarioId,
                'organizador' => $organizador->id,
            ]);
        }

        return $sala;
    }
}
