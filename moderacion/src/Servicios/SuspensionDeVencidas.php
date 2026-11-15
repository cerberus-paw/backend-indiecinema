<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Servicios;

use DateTimeImmutable;
use IndieCinema\Moderacion\Modelo\CausaSuspension;
use IndieCinema\Moderacion\Modelo\TipoDocumento;
use IndieCinema\Moderacion\Repositorios\RepositorioDeDocumentos;
use IndieCinema\Nucleo\Configuracion;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * La tarea diaria que suspende las salas con un documento vencido (EF-07.5): cada sala cuyo
 * seguro o habilitación vigente (el aceptado con el vencimiento más lejano) vence hoy o antes
 * queda suspendida por vencimiento de ese papel, y apagada en programación, hasta que se acepte
 * uno nuevo. Al organizador le avisa por correo siete días antes y cuando la suspende.
 *
 * Con [documentos] exigible_desde en la configuración, las salas habilitadas a las que les falta
 * un documento aceptado (las que ya estaban antes de que se pidiera) tienen 30 días desde esa
 * fecha: un aviso al empezar, otro siete días antes del fin y, pasado el plazo, la suspensión por
 * falta (grilling de EF-07, Q4).
 *
 * Correrla de más no repite nada: la suspensión activa por la misma causa y tipo ya está. Una sala
 * que falla (programación no responde) queda en el log y para la próxima vuelta; las demás siguen.
 */
final class SuspensionDeVencidas
{
    /** El plazo de las salas que ya estaban, desde exigible_desde (grilling de EF-07, Q4). */
    private const DIAS_DE_PLAZO = 30;

    public function __construct(
        private readonly RepositorioDeDocumentos $documentos,
        private readonly ServicioDeSuspensiones $suspensiones,
        private readonly AvisosAlOrganizador $avisos,
        private readonly LoggerInterface $log,
        private readonly Configuracion $configuracion,
        private readonly SalasDeProgramacion $salas,
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

        $abiertas += $this->exigirLaDocumentacion($hoy);

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

    /**
     * El plazo de las salas que ya estaban: avisos y, pasados 30 días, la suspensión por falta.
     *
     * @return int cuántas suspensiones abrió
     */
    private function exigirLaDocumentacion(DateTimeImmutable $hoy): int
    {
        $texto = $this->configuracion->obtener('documentos.exigible_desde');
        $desde = is_string($texto) ? DateTimeImmutable::createFromFormat('!Y-m-d', $texto) : false;
        if ($texto !== null && $desde === false) {
            $this->log->error('[documentos] exigible_desde no es una fecha (AAAA-MM-DD): no se exige la documentación.');
        }
        if ($desde === false || $hoy < $desde) {
            return 0;
        }
        $fin = $desde->modify('+' . self::DIAS_DE_PLAZO . ' days');
        try {
            $salas = $this->salas->habilitadas();
        } catch (Throwable $error) {
            $this->log->error('No se pidieron las salas habilitadas para exigirles la documentación: {mensaje}', ['mensaje' => $error->getMessage(), 'exception' => $error]);

            return 0;
        }

        $abiertas = 0;
        foreach (array_keys($salas) as $sala) {
            $tiene = $this->documentos->tiposAceptadosDe($sala);
            $faltan = array_values(array_filter(TipoDocumento::cases(), static fn (TipoDocumento $tipo): bool => !in_array($tipo, $tiene, true)));
            if ($faltan === []) {
                continue;
            }
            $cuales = implode(' y ', array_map(static fn (TipoDocumento $tipo): string => $tipo->conArticulo(), $faltan));
            $this->avisos->avisar($sala, 'plazo-inicio', static fn (string $nombre): array => [
                "Cargá la documentación de «{$nombre}»",
                self::textoDelPlazo($sala, $nombre, $cuales, $fin, 'Desde ahora IndieCinema pide el seguro y la habilitación de cada sala, en PDF.'),
            ]);
            if ($hoy >= $fin->modify('-7 days') && $hoy < $fin) {
                $this->avisos->avisar($sala, 'plazo-7', static fn (string $nombre): array => [
                    "Quedan 7 días para cargar la documentación de «{$nombre}»",
                    self::textoDelPlazo($sala, $nombre, $cuales, $fin, 'Se termina el plazo para cargar la documentación de tu sala.'),
                ]);
            }
            if ($hoy < $fin) {
                continue;
            }
            foreach ($faltan as $tipo) {
                try {
                    if ($this->suspensiones->abrir($sala, CausaSuspension::Vencimiento, $tipo, 'Falta ' . $tipo->conArticulo() . '.', null)) {
                        ++$abiertas;
                    }
                } catch (Throwable $error) {
                    $this->log->error('No se suspendió la sala {sala} por la falta {tipo}: {mensaje}', [
                        'sala' => $sala,
                        'tipo' => $tipo->deQue(),
                        'mensaje' => $error->getMessage(),
                        'exception' => $error,
                    ]);
                }
            }
        }

        return $abiertas;
    }

    private static function textoDelPlazo(string $sala, string $nombre, string $cuales, DateTimeImmutable $fin, string $primero): string
    {
        return implode("\n", [
            $primero,
            '',
            "Te falta {$cuales} de «{$nombre}»: cargalos hasta el " . $fin->format('d/m/Y') . ' en la documentación de la sala',
            '(' . AvisosAlOrganizador::documentacion($sala) . '). Si no, la sala queda suspendida y no programa funciones nuevas.',
        ]);
    }

    /** «Venció el seguro.», «Venció la habilitación.» */
    public static function motivo(TipoDocumento $tipo): string
    {
        return 'Venció ' . $tipo->conArticulo() . '.';
    }
}
