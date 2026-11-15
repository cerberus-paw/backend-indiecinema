<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Servicios;

use DateTimeImmutable;
use DateTimeZone;
use IndieCinema\Moderacion\Modelo\CategoriaDenuncia;
use IndieCinema\Moderacion\Modelo\Denuncia;
use IndieCinema\Moderacion\Modelo\Denunciado;
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
    ) {
    }

    /**
     * @return list<array{tipo: TipoDenuncia, objeto_id: string, titulo: string, cantidad: int, primera: DateTimeImmutable}>
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
     * @return list<array{tipo: TipoDenuncia, objeto_id: string, titulo: string, categoria: CategoriaDenuncia, estado: string, accion: ?string, motivo: string, resuelta_por: string, resuelta_en: DateTimeImmutable}>
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
     * @param ?Denunciado $denunciado como lo da hoy su subsistema, si todavía se resuelve
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

        if ($denunciado !== null && !$denunciado->sancionado) {
            match ($accion) {
                // El rechazo de EF-07.7: el historial de obras y el correo al dueño.
                'rechazar' => $this->obras->resolver($denunciado->id, false, $motivo, $moderador),
                default => null,
            };
        }

        return $this->denuncias->cerrar($tipo, $objetoId, $accion === 'desestimar' ? null : $accion, null, $motivo, $moderador->id, new DateTimeImmutable('now', new DateTimeZone('UTC'))) > 0;
    }
}
