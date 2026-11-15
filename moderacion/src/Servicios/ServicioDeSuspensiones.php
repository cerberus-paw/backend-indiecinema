<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Servicios;

use DateTimeImmutable;
use DateTimeZone;
use IndieCinema\Moderacion\Modelo\CausaSuspension;
use IndieCinema\Moderacion\Modelo\Suspension;
use IndieCinema\Moderacion\Modelo\TipoDocumento;
use IndieCinema\Moderacion\Repositorios\RepositorioDeSuspensiones;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Uuid;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Abre y levanta las suspensiones de una sala (EF-07.5). Programación sólo sabe si la sala está
 * habilitada: se la apaga cuando se abre la primera suspensión activa y se la vuelve a publicar
 * cuando se levanta la última, así una causa no deshace otra (grilling de EF-07, Q2).
 *
 * El pedido a programación va antes que el cambio en la base: si programación no responde, no
 * cambia nada y se puede reintentar. Al revés, quedaría una suspensión guardada con la sala
 * publicada.
 */
final class ServicioDeSuspensiones
{
    public function __construct(
        private readonly RepositorioDeSuspensiones $suspensiones,
        private readonly SalasDeProgramacion $salas,
        private readonly LoggerInterface $log,
    ) {
    }

    /**
     * @param ?string $quien el moderador, o null si la abre la tarea
     *
     * @return bool false si ya había una activa por la misma causa (y tipo): no cambia nada
     *
     * @throws ExcepcionHttp    503 si programación no responde; 404 si ya no tiene la sala
     * @throws RuntimeException si programación responde otra cosa
     */
    public function abrir(string $salaId, CausaSuspension $causa, ?TipoDocumento $tipo, string $motivo, ?string $quien): bool
    {
        $activas = $this->suspensiones->activasDe($salaId);
        foreach ($activas as $activa) {
            if ($activa->causa === $causa && $activa->tipoDocumento === $tipo) {
                return false;
            }
        }
        if ($activas === []) {
            $this->salas->cambiarHabilitacion($salaId, false, $motivo, $quien);
        }
        $suspension = new Suspension(Uuid::nuevo(), $salaId, $causa, $tipo, $motivo, $quien, self::ahora());
        if (!$this->suspensiones->agregar($suspension)) {
            // Otra abrió la misma a la vez: la sala ya quedó apagada por ésa.
            return false;
        }
        $this->log->notice('{quien} suspendió la sala {sala} ({causa}): {motivo}', [
            'quien' => $quien ?? 'La tarea de vencimientos',
            'sala' => $salaId,
            'causa' => $causa->value . ($tipo === null ? '' : " del {$tipo->value}"),
            'motivo' => $motivo,
        ]);

        return true;
    }

    /**
     * Levanta la activa de esa causa (y tipo) y, si era la última, vuelve a publicar la sala.
     *
     * @param string  $motivo el que queda en el registro de programación al publicarla
     * @param ?string $quien  el moderador, o null si la levanta la tarea
     *
     * @return bool false si no había una activa por esa causa: no cambia nada
     *
     * @throws ExcepcionHttp    503 si programación no responde; 404 si ya no tiene la sala
     * @throws RuntimeException si programación responde otra cosa
     */
    public function levantar(string $salaId, CausaSuspension $causa, ?TipoDocumento $tipo, string $motivo, ?string $quien): bool
    {
        $activas = $this->suspensiones->activasDe($salaId);
        $esta = null;
        foreach ($activas as $activa) {
            if ($activa->causa === $causa && $activa->tipoDocumento === $tipo) {
                $esta = $activa;
            }
        }
        if ($esta === null) {
            return false;
        }
        $ultima = count($activas) === 1;
        if ($ultima) {
            $this->salas->cambiarHabilitacion($salaId, true, $motivo, $quien);
        }
        if (!$this->suspensiones->levantar($esta->id, $quien, self::ahora())) {
            return false;
        }
        $this->log->notice('{quien} levantó la suspensión de la sala {sala} ({causa}){publicada}: {motivo}', [
            'quien' => $quien ?? 'La tarea de vencimientos',
            'sala' => $salaId,
            'causa' => $causa->value . ($tipo === null ? '' : " del {$tipo->value}"),
            'publicada' => $ultima ? ' y volvió a publicarse' : ', que sigue suspendida por otra causa',
            'motivo' => $motivo,
        ]);

        return true;
    }

    /**
     * @return list<Suspension>
     */
    public function activasDeLaTarea(): array
    {
        return $this->suspensiones->activasDeLaTarea();
    }

    /**
     * @return list<Suspension>
     */
    public function activasDe(string $salaId): array
    {
        return $this->suspensiones->activasDe($salaId);
    }

    private static function ahora(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
