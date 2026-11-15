<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Servicios;

use DateTimeImmutable;
use IndieCinema\Moderacion\Modelo\CausaSuspension;
use IndieCinema\Moderacion\Modelo\TipoDocumento;
use IndieCinema\Moderacion\Repositorios\RepositorioDeDocumentos;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * La tarea diaria que suspende las salas con un documento vencido (EF-07.5): cada sala cuyo
 * seguro o habilitación vigente (el aceptado con el vencimiento más lejano) vence hoy o antes
 * queda suspendida por vencimiento de ese papel, y apagada en programación, hasta que se acepte
 * uno nuevo.
 *
 * Correrla de más no repite nada: la suspensión activa por la misma causa y tipo ya está. Una sala
 * que falla (programación no responde) queda en el log y para la próxima vuelta; las demás siguen.
 */
final class SuspensionDeVencidas
{
    public function __construct(
        private readonly RepositorioDeDocumentos $documentos,
        private readonly ServicioDeSuspensiones $suspensiones,
        private readonly LoggerInterface $log,
    ) {
    }

    /**
     * @return int cuántas suspensiones abrió
     */
    public function correr(): int
    {
        $abiertas = 0;
        foreach ($this->documentos->vencidosAl(new DateTimeImmutable('today')) as ['sala_id' => $sala, 'tipo' => $tipo]) {
            try {
                if ($this->suspensiones->abrir($sala, CausaSuspension::Vencimiento, $tipo, self::motivo($tipo), null)) {
                    ++$abiertas;
                }
            } catch (Throwable $error) {
                $this->log->error('No se suspendió la sala {sala} por el vencimiento {tipo}: {mensaje}', [
                    'sala' => $sala,
                    'tipo' => $tipo->deQue(),
                    'mensaje' => $error->getMessage(),
                    'exception' => $error,
                ]);
            }
        }

        return $abiertas;
    }

    /** «Venció el seguro.», «Venció la habilitación.» */
    public static function motivo(TipoDocumento $tipo): string
    {
        return 'Venció ' . $tipo->conArticulo() . '.';
    }
}
