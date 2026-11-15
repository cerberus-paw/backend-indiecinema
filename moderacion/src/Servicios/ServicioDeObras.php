<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Servicios;

use DateTimeImmutable;
use DateTimeZone;
use IndieCinema\Moderacion\Modelo\ObraDeProgramacion;
use IndieCinema\Moderacion\Modelo\UsuarioDeCuentas;
use IndieCinema\Moderacion\Repositorios\RepositorioDeRevisionesDeObras;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Seguridad\Rol;
use IndieCinema\Nucleo\Seguridad\Usuario;
use Psr\Log\LoggerInterface;

/**
 * La revisión de las obras nuevas (EF-07.7): una obra que carga un organizador queda pendiente en
 * programación hasta que un moderador la aprueba o la rechaza con un motivo. El estado es de
 * programación; moderación guarda el historial de decisiones.
 */
final class ServicioDeObras
{
    /** Las que muestra el historial: más no entran en la pantalla. */
    private const EN_EL_HISTORIAL = 50;

    public function __construct(
        private readonly ObrasDeProgramacion $obras,
        private readonly RepositorioDeRevisionesDeObras $revisiones,
        private readonly UsuariosDeCuentas $usuarios,
        private readonly LoggerInterface $log,
    ) {
    }

    /**
     * @return list<ObraDeProgramacion>
     *
     * @throws ExcepcionHttp 503 si programación no responde
     */
    public function pendientes(): array
    {
        return $this->obras->pendientes();
    }

    /**
     * @return list<array{obra_id: string, dueno_id: string, titulo: string, resolucion: string, motivo: string, moderador_id: string, resuelta_en: DateTimeImmutable}>
     */
    public function historial(): array
    {
        return $this->revisiones->historial(self::EN_EL_HISTORIAL);
    }

    /**
     * Quién la cargó, según cuentas, o null si ya no existe.
     *
     * @throws ExcepcionHttp 503 si cuentas no responde
     */
    public function dueno(ObraDeProgramacion $obra): ?UsuarioDeCuentas
    {
        return $this->usuarios->buscar($obra->duenoId);
    }

    /**
     * El moderador no resuelve sus obras (grilling de EF-07, Q1); el administrador sí.
     */
    public function laPuedeResolver(ObraDeProgramacion $obra, Usuario $moderador): bool
    {
        return !hash_equals($obra->duenoId, $moderador->id) || $moderador->alcanza(Rol::Administrador);
    }

    /**
     * La aprueba o la rechaza en programación y recién ahí guarda el registro: si programación no
     * responde, no queda nada y se reintenta.
     *
     * @return bool false si ya no está pendiente: no cambia nada
     *
     * @throws ExcepcionHttp 403 si es del moderador; 503 si programación no responde
     */
    public function resolver(string $id, bool $aprobar, string $motivo, Usuario $moderador): bool
    {
        $obra = $this->pendiente($id);
        if ($obra === null) {
            return false;
        }
        if (!$this->laPuedeResolver($obra, $moderador)) {
            throw ExcepcionHttp::prohibida();
        }
        $revisada = $this->obras->revisar($obra->id, $aprobar, $motivo, $moderador->id);
        if ($revisada === null || !$revisada['cambio']) {
            return false;
        }
        $this->revisiones->agregar($obra->id, $revisada['dueno_id'], $revisada['titulo'], $revisada['estado'], $motivo, $moderador->id, new DateTimeImmutable('now', new DateTimeZone('UTC')));
        // Es el registro de auditoría: quién resolvió qué.
        $this->log->notice('{moderador} resolvió la obra {obra}: {estado}.', [
            'moderador' => $moderador->id,
            'obra' => $obra->id,
            'estado' => $revisada['estado'],
        ]);

        return true;
    }

    /**
     * Una pendiente, o null si no lo está (o no existe).
     *
     * @throws ExcepcionHttp 503 si programación no responde
     */
    public function pendiente(string $id): ?ObraDeProgramacion
    {
        foreach ($this->obras->pendientes() as $obra) {
            if ($obra->id === $id) {
                return $obra;
            }
        }

        return null;
    }
}
