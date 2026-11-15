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
 * uno nuevo. Al organizador le avisa por correo siete días antes y cuando la suspende.
 *
 * Correrla de más no repite nada: la suspensión activa por la misma causa y tipo ya está. Una sala
 * que falla (programación no responde) queda en el log y para la próxima vuelta; las demás siguen.
 */
final class SuspensionDeVencidas
{
    public function __construct(
        private readonly RepositorioDeDocumentos $documentos,
        private readonly ServicioDeSuspensiones $suspensiones,
        private readonly AvisosAlOrganizador $avisos,
        private readonly LoggerInterface $log,
    ) {
    }

    /**
     * @return int cuántas suspensiones abrió
     */
    public function correr(): int
    {
        $hoy = new DateTimeImmutable('today');
        foreach ($this->documentos->vigentesQueVencenEntre($hoy->modify('+1 day'), $hoy->modify('+7 days')) as $documento) {
            $this->avisos->avisar($documento->salaId, "vence-7:{$documento->id}", static fn (string $sala): array => [
                'Se vence ' . $documento->tipo->conArticulo() . " de «{$sala}»",
                implode("\n", [
                    ucfirst($documento->tipo->conArticulo()) . " de tu sala «{$sala}» vence el " . $documento->venceEn->format('d/m/Y') . '.',
                    '',
                    'Subí el nuevo antes de esa fecha en la documentación de la sala (' . AvisosAlOrganizador::documentacion($documento->salaId) . '):',
                    'si vence sin que la moderación acepte otro, la sala queda suspendida y no programa funciones nuevas.',
                ]),
            ]);
        }

        $abiertas = 0;
        foreach ($this->documentos->vencidosAl($hoy) as ['sala_id' => $sala, 'tipo' => $tipo]) {
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

        // Una vuelta después de abrirlas, también las que no se pudieron avisar antes.
        foreach ($this->suspensiones->activasDeLaTarea() as $suspension) {
            $this->avisos->avisar($suspension->salaId, "suspendida:{$suspension->id}", static fn (string $sala): array => [
                "Suspendimos la sala «{$sala}»",
                implode("\n", [
                    "Tu sala «{$sala}» queda suspendida: {$suspension->motivo}",
                    '',
                    'Mientras tanto no se ve en el sitio ni programa funciones nuevas; las que ya tenía siguen en pie.',
                    'Subí el documento nuevo en la documentación de la sala (' . AvisosAlOrganizador::documentacion($suspension->salaId) . '):',
                    'cuando la moderación lo acepte, la sala vuelve a publicarse.',
                ]),
            ]);
        }

        return $abiertas;
    }

    /** «Venció el seguro.», «Venció la habilitación.» */
    public static function motivo(TipoDocumento $tipo): string
    {
        return 'Venció ' . $tipo->conArticulo() . '.';
    }
}
