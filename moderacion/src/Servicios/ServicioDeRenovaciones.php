<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Servicios;

use DateTimeImmutable;
use DateTimeZone;
use IndieCinema\Moderacion\Modelo\CausaSuspension;
use IndieCinema\Moderacion\Modelo\Documento;
use IndieCinema\Moderacion\Modelo\EstadoDocumento;
use IndieCinema\Moderacion\Repositorios\RepositorioDeDocumentos;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Seguridad\Rol;
use IndieCinema\Nucleo\Seguridad\Usuario;
use IndieCinema\Nucleo\Uuid;
use Psr\Log\LoggerInterface;

/**
 * Las renovaciones que suben los organizadores (EF-07.5), que la moderación acepta o rechaza con un
 * motivo, sin checklist (grilling de EF-07, Q14). Aceptar levanta la suspensión por vencimiento de
 * ese papel; si era la última, la sala vuelve a publicarse. El organizador recibe un correo.
 */
final class ServicioDeRenovaciones
{
    /** Las que entran en la pantalla; se resuelven a medida que llegan. */
    private const EN_LA_BANDEJA = 50;

    public function __construct(
        private readonly RepositorioDeDocumentos $documentos,
        private readonly ServicioDeSuspensiones $suspensiones,
        private readonly SalasDeProgramacion $salas,
        private readonly AvisosAlOrganizador $avisos,
        private readonly LoggerInterface $log,
    ) {
    }

    /**
     * Las pendientes, de la más vieja a la más nueva, con lo que programación cuenta de su sala
     * (null si ya no la tiene).
     *
     * @return list<array{documento: Documento, sala: ?array{nombre: string, organizador_id: string, habilitada: bool}}>
     *
     * @throws ExcepcionHttp 503 si programación no responde
     */
    public function pendientes(): array
    {
        $pendientes = array_slice($this->documentos->renovacionesPendientes(), 0, self::EN_LA_BANDEJA);
        $salas = $this->salas->datosDeVarias(array_map(static fn (Documento $documento): string => $documento->salaId, $pendientes));

        return array_map(static fn (Documento $documento): array => ['documento' => $documento, 'sala' => $salas[$documento->salaId] ?? null], $pendientes);
    }

    public function cuantasPendientes(): int
    {
        return count($this->documentos->renovacionesPendientes());
    }

    /**
     * @throws ExcepcionHttp 404 si no es una renovación pendiente; 403 si es de una sala del
     *                       moderador; 503 si programación no responde (y queda pendiente)
     */
    public function resolver(string $id, bool $aceptar, string $motivo, Usuario $moderador): Documento
    {
        $documento = Uuid::esValido($id) ? $this->documentos->buscar($id) : null;
        if ($documento === null || $documento->solicitudId !== null || $documento->estado !== EstadoDocumento::Pendiente) {
            throw ExcepcionHttp::noEncontrada();
        }
        $sala = $this->salas->datosDe($documento->salaId);
        // El moderador no resuelve lo suyo (grilling de EF-07, Q1); el administrador sí.
        if ($sala !== null && hash_equals($sala['organizador_id'], $moderador->id) && !$moderador->alcanza(Rol::Administrador)) {
            throw ExcepcionHttp::prohibida();
        }
        // Antes que el documento: si programación no responde, queda pendiente y se reintenta. Uno
        // que se venció mientras esperaba no levanta nada.
        if ($aceptar && $documento->venceEn > new DateTimeImmutable('today')) {
            $this->suspensiones->levantar($documento->salaId, CausaSuspension::Vencimiento, $documento->tipo, 'Se aceptó ' . $documento->tipo->conArticulo() . ' nuevo.', $moderador->id);
        }
        $estado = $aceptar ? EstadoDocumento::Aceptado : EstadoDocumento::Rechazado;
        if (!$this->documentos->resolverRenovacion($documento->id, $estado, $motivo, $moderador->id, new DateTimeImmutable('now', new DateTimeZone('UTC')))) {
            throw ExcepcionHttp::noEncontrada();
        }
        $this->log->notice('{moderador} resolvió el documento {documento} de la sala {sala}: {estado}.', [
            'moderador' => $moderador->id,
            'documento' => $documento->id,
            'sala' => $documento->salaId,
            'estado' => $estado->value,
        ]);
        $this->avisar($documento, $aceptar, $motivo);

        return $documento;
    }

    /**
     * Si el correo no sale, la resolución queda igual: AvisosAlOrganizador lo deja en el log.
     */
    private function avisar(Documento $documento, bool $aceptado, string $motivo): void
    {
        $tipo = $documento->tipo->conArticulo();
        $this->avisos->avisar($documento->salaId, "resuelto:{$documento->id}", static fn (string $sala): array => [
            ($aceptado ? 'Aceptamos ' : 'Rechazamos ') . "{$tipo} de «{$sala}»",
            implode("\n", [
                $aceptado
                    ? "La moderación aceptó {$tipo} nuevo de tu sala «{$sala}», que vence el " . $documento->venceEn->format('d/m/Y') . '.'
                    : "La moderación rechazó {$tipo} que subiste para tu sala «{$sala}».",
                '',
                "Motivo: {$motivo}",
                '',
                $aceptado
                    ? 'Si la sala estaba suspendida sólo por ese papel, ya vuelve a verse en el sitio.'
                    : 'Podés subir otro en la documentación de la sala (' . AvisosAlOrganizador::documentacion($documento->salaId) . ').',
            ]),
        ]);
    }
}
