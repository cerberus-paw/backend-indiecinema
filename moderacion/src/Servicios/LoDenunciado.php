<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Servicios;

use DateTimeImmutable;
use IndieCinema\Moderacion\Modelo\CausaSuspension;
use IndieCinema\Moderacion\Modelo\Denunciado;
use IndieCinema\Moderacion\Modelo\Suspension;
use IndieCinema\Moderacion\Modelo\TipoDenuncia;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Uuid;
use RuntimeException;

/**
 * Lo que se denuncia, que es de otro subsistema (EF-07.8): se lo pide a su dueño por la API
 * interna, para nombrarlo, saber de quién es y si está publicado.
 */
final class LoDenunciado
{
    public function __construct(
        private readonly ObrasDeProgramacion $obras,
        private readonly SalasDeProgramacion $salas,
        private readonly ServicioDeSuspensiones $suspensiones,
        private readonly FuncionesDeProgramacion $funciones,
    ) {
    }

    /**
     * @return ?Denunciado null si no existe
     *
     * @throws ExcepcionHttp    503 si el subsistema dueño no responde
     * @throws RuntimeException si responde otra cosa
     */
    public function buscar(TipoDenuncia $tipo, string $id): ?Denunciado
    {
        if (!Uuid::esValido($id)) {
            return null;
        }

        return match ($tipo) {
            TipoDenuncia::Obra => $this->obra($id),
            TipoDenuncia::Sala => $this->sala($id),
            TipoDenuncia::Funcion => $this->funcion($id),
            default => null,
        };
    }

    /**
     * La sala está publicada si está habilitada. Ya está sancionada si tiene una suspensión activa
     * por denuncia (EF-07.5).
     */
    private function sala(string $id): ?Denunciado
    {
        $sala = $this->salas->datosDe($id);
        if ($sala === null) {
            return null;
        }
        $porDenuncia = array_filter($this->suspensiones->activasDe($id), static fn (Suspension $suspension): bool => $suspension->causa === CausaSuspension::Denuncia);

        return new Denunciado(TipoDenuncia::Sala, $id, $sala['nombre'], $sala['organizador_id'], $sala['habilitada'], "/salas/{$id}", [], $porDenuncia !== []);
    }

    /**
     * La función está publicada si está en la cartelera: en votación de títulos, en acuerdo de
     * fecha o programada a futuro, en una sala publicada. Es del organizador de su sala.
     */
    private function funcion(string $id): ?Denunciado
    {
        $funcion = $this->funciones->una($id);
        if ($funcion === null) {
            return null;
        }
        $enLaCartelera = in_array($funcion['estado'], ['en_votacion_titulos', 'en_acuerdo_fecha'], true)
            || ($funcion['estado'] === 'programada' && $funcion['fecha_hora'] !== null && new DateTimeImmutable($funcion['fecha_hora']) > new DateTimeImmutable());

        return new Denunciado(
            TipoDenuncia::Funcion,
            $id,
            implode(' + ', array_column($funcion['obras'], 'titulo')),
            $funcion['organizador_id'],
            $funcion['sala_habilitada'] && $enLaCartelera,
            "/cartelera/{$id}",
            ['sala' => $funcion['sala'], 'sala_id' => $funcion['sala_id'], 'obras' => $funcion['obras']],
        );
    }

    private function obra(string $id): ?Denunciado
    {
        $obra = $this->obras->una($id);

        return $obra === null ? null : new Denunciado(TipoDenuncia::Obra, $obra->id, $obra->titulo, $obra->duenoId, $obra->estado === 'aprobada', "/obras/{$obra->id}", [
            'tipo' => $obra->tipo === 'cortometraje' ? 'Cortometraje' : 'Película',
            'anio' => $obra->anio,
            'director' => $obra->director,
            'sinopsis' => $obra->sinopsis,
            'afiche' => $obra->afiche ? "/obras/{$obra->id}/afiche" : null,
        ], $obra->estado === 'rechazada');
    }
}
