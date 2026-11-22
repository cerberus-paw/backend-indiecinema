<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Servicios;

use DateTimeImmutable;
use DateTimeZone;
use IndieCinema\Moderacion\Modelo\CategoriaDenuncia;
use IndieCinema\Moderacion\Modelo\CausaSuspension;
use IndieCinema\Moderacion\Modelo\Denuncia;
use IndieCinema\Moderacion\Modelo\Denunciado;
use IndieCinema\Moderacion\Modelo\GravedadDenuncia;
use IndieCinema\Moderacion\Modelo\Suspension;
use IndieCinema\Moderacion\Modelo\TipoDenuncia;
use IndieCinema\Moderacion\Modelo\UsuarioDeCuentas;
use IndieCinema\Moderacion\Repositorios\RepositorioDeDenuncias;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Seguridad\Rol;
use IndieCinema\Nucleo\Seguridad\Usuario;
use RuntimeException;

/**
 * La bandeja de denuncias (EF-07.8): las abiertas, agrupadas por lo denunciado, y lo que el
 * moderador hace con ellas. Lo denunciado es de otro subsistema, y quién denunció, de cuentas.
 */
final class ServicioDeDenuncias
{
    /** Cuántas cerradas muestra «Ver historial». */
    private const EN_EL_HISTORIAL = 100;

    public function __construct(
        private readonly RepositorioDeDenuncias $denuncias,
        private readonly LoDenunciado $denunciados,
        private readonly UsuariosDeCuentas $usuarios,
        private readonly ServicioDeObras $obras,
        private readonly ServicioDeSuspensiones $suspensiones,
        private readonly AvisosAlOrganizador $avisos,
        private readonly SalasDeProgramacion $salas,
        private readonly CalificacionesDeFunciones $calificaciones,
    ) {
    }

    /**
     * @return list<array{tipo: TipoDenuncia, objeto_id: string, titulo: string, cantidad: int, gravedad: GravedadDenuncia, para_suspender: bool, primera: DateTimeImmutable}>
     */
    public function abiertas(): array
    {
        return $this->denuncias->abiertas();
    }

    /**
     * @return list<Denuncia>
     */
    public function abiertasDe(TipoDenuncia $tipo, string $objetoId): array
    {
        return $this->denuncias->abiertasDe($tipo, $objetoId);
    }

    /**
     * Lo denunciado, como lo da hoy su subsistema, o null si ya no existe.
     *
     * @throws ExcepcionHttp    503 si su subsistema no responde
     * @throws RuntimeException si responde otra cosa
     */
    public function denunciado(TipoDenuncia $tipo, string $objetoId): ?Denunciado
    {
        return $this->denunciados->buscar($tipo, $objetoId);
    }

    /**
     * Quién denunció, según cuentas, o null si ya no existe.
     *
     * @throws ExcepcionHttp 503 si cuentas no responde
     */
    public function quien(string $usuarioId): ?UsuarioDeCuentas
    {
        return $this->usuarios->buscar($usuarioId);
    }

    /**
     * El moderador no resuelve las denuncias sobre lo suyo ni las que hizo él (grilling de EF-07,
     * Q1); el administrador sí.
     *
     * @param list<Denuncia> $denuncias
     */
    public function lasPuedeResolver(?Denunciado $denunciado, array $denuncias, Usuario $moderador): bool
    {
        if ($moderador->alcanza(Rol::Administrador)) {
            return true;
        }
        if ($denunciado !== null && $denunciado->esDe($moderador->id)) {
            return false;
        }
        foreach ($denuncias as $denuncia) {
            if (hash_equals($denuncia->usuarioId, $moderador->id)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<array{tipo: TipoDenuncia, objeto_id: string, titulo: string, categoria: CategoriaDenuncia, gravedad: GravedadDenuncia, estado: string, accion: ?string, motivo: string, resuelta_por: string, resuelta_en: DateTimeImmutable}>
     */
    public function historial(): array
    {
        return $this->denuncias->historial(self::EN_EL_HISTORIAL);
    }

    /**
     * Cierra todas las abiertas de lo denunciado con el motivo: desestimadas, o resueltas después
     * de actuar en el subsistema dueño (si no responde, no se cierra nada y se reintenta). Si lo
     * que resuelve ya estaba hecho, se cierran sin repetirlo (Q14).
     *
     * @param ?Denunciado $denunciado como lo da hoy su subsistema, o null si ya no existe
     *
     * @return bool false si ya no había abiertas: no cambia nada
     *
     * @throws ExcepcionHttp 403 si es del moderador o denunció él; 503 si el subsistema dueño no responde
     */
    public function resolver(TipoDenuncia $tipo, string $objetoId, ?Denunciado $denunciado, string $accion, string $motivo, Usuario $moderador): bool
    {
        $denuncias = $this->denuncias->abiertasDe($tipo, $objetoId);
        if ($denuncias === []) {
            return false;
        }
        if (!$this->lasPuedeResolver($denunciado, $denuncias, $moderador)) {
            throw ExcepcionHttp::prohibida();
        }

        // En una función, la obra rechazada va en la acción: rechazar:{id}.
        [$accion, $obraId] = str_contains($accion, ':') ? explode(':', $accion, 2) : [$accion, null];
        if ($denunciado !== null && !$denunciado->sancionado) {
            // La sala de la función, o la sala denunciada; la obra elegida, o la denunciada.
            $salaId = $denunciado->salaId() ?? $denunciado->id;
            match ($accion) {
                // El rechazo de EF-07.7: el historial de obras y el correo al dueño. Si ya estaba
                // rechazada, no hace nada.
                'rechazar' => $this->obras->resolver($obraId ?? $denunciado->id, false, $motivo, $moderador),
                'suspender' => $this->suspender($salaId, $motivo, $moderador),
                'ocultar' => $this->calificaciones->ocultar($denunciado->id, $moderador->id),
                default => null,
            };
        }

        return $this->denuncias->cerrar($tipo, $objetoId, $accion === 'desestimar' ? null : $accion, $obraId, $motivo, $moderador->id, new DateTimeImmutable('now', new DateTimeZone('UTC'))) > 0;
    }

    /**
     * Abre la suspensión por denuncia (EF-07.5: programación la apaga si era la primera activa y
     * lo programado sigue) y le avisa al organizador con el motivo, sin quién denunció ni el
     * detalle. Si el correo no sale, la suspensión queda igual.
     */
    private function suspender(string $salaId, string $motivo, Usuario $moderador): void
    {
        if (!$this->suspensiones->abrir($salaId, CausaSuspension::Denuncia, null, $motivo, $moderador->id)) {
            return;
        }
        foreach ($this->suspensiones->activasDe($salaId) as $suspension) {
            if ($suspension->causa === CausaSuspension::Denuncia) {
                $this->avisarLaSuspension($suspension);
            }
        }
    }

    private function avisarLaSuspension(Suspension $suspension): void
    {
        $this->avisos->avisar($suspension->salaId, "suspendida:{$suspension->id}", static fn (string $sala): array => [
            "Suspendimos la sala «{$sala}»",
            implode("\n", [
                "Tu sala «{$sala}» queda suspendida por una denuncia, por este motivo:",
                $suspension->motivo,
                '',
                'Mientras tanto no se ve en el sitio ni programa funciones nuevas; las que ya tenía siguen en pie.',
                'Los detalles están en la documentación de la sala (' . AvisosAlOrganizador::documentacion($suspension->salaId) . ').',
                'Si se resuelve, la moderación levanta la suspensión y la sala vuelve a publicarse.',
            ]),
        ]);
    }

    /**
     * Las salas suspendidas por denuncia, con su nombre y su organizador según programación.
     *
     * @return list<array{suspension: Suspension, nombre: ?string, organizador_id: ?string}>
     *
     * @throws ExcepcionHttp 503 si programación no responde
     */
    public function suspendidas(): array
    {
        $suspensiones = $this->suspensiones->activasPorDenuncia();
        $salas = $this->salas->datosDeVarias(array_values(array_unique(array_map(static fn (Suspension $suspension): string => $suspension->salaId, $suspensiones))));

        return array_map(static fn (Suspension $suspension): array => [
            'suspension' => $suspension,
            'nombre' => $salas[$suspension->salaId]['nombre'] ?? null,
            'organizador_id' => $salas[$suspension->salaId]['organizador_id'] ?? null,
        ], $suspensiones);
    }

    /**
     * El moderador no levanta la suspensión de su propia sala (Q1); el administrador sí.
     */
    public function laPuedeLevantar(?string $organizadorId, Usuario $moderador): bool
    {
        return $organizadorId === null || !hash_equals($organizadorId, $moderador->id) || $moderador->alcanza(Rol::Administrador);
    }

    /**
     * Levanta la suspensión por denuncia: si era la última activa, programación vuelve a publicar
     * la sala (EF-07.5).
     *
     * @return bool false si no tenía una activa por denuncia: no cambia nada
     *
     * @throws ExcepcionHttp 403 si la sala es del moderador; 503 si programación no responde
     */
    public function levantar(string $salaId, string $motivo, Usuario $moderador): bool
    {
        $sala = $this->salas->datosDe($salaId);
        if (!$this->laPuedeLevantar($sala['organizador_id'] ?? null, $moderador)) {
            throw ExcepcionHttp::prohibida();
        }

        return $this->suspensiones->levantar($salaId, CausaSuspension::Denuncia, null, $motivo, $moderador->id);
    }

    /**
     * @throws ExcepcionHttp 503 si programación no responde
     */
    public function nombreDeLaSala(string $salaId): ?string
    {
        return $this->salas->datosDe($salaId)['nombre'] ?? null;
    }
}
