<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Controladores;

use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Seguridad\Usuario;
use IndieCinema\Nucleo\Uuid;
use IndieCinema\Programacion\Modelo\BloqueoDeSala;
use IndieCinema\Programacion\Modelo\ReglaIncumplida;
use IndieCinema\Programacion\Modelo\Sala;
use IndieCinema\Programacion\Servicios\ServicioDeBloqueos;
use IndieCinema\Programacion\Servicios\ServicioDeSalas;
use IndieCinema\Programacion\Servicios\UsuariosDeCuentas;
use LogicException;

/**
 * «Usuarios bloqueados» en el panel del organizador (EF-12.1 / IC-580): listar bloqueados en sus
 * salas, bloquear a un espectador con motivo y desbloquearlo. Las rutas piden el rol de
 * organizador y que la sala sea suya lo controla ServicioDeSalas::paraEditar (404 si es ajena).
 */
final class BloqueosDelOrganizador extends Controlador
{
    private const PLANTILLA = 'bloqueados.html.twig';
    private const MAXIMO_MOTIVO = 500;

    public function __construct(
        private readonly ServicioDeBloqueos $bloqueos,
        private readonly ServicioDeSalas $salas,
        private readonly UsuariosDeCuentas $usuarios,
    ) {
    }

    /** GET /organizador/bloqueados */
    public function listar(Peticion $peticion): Respuesta
    {
        $organizador = self::organizador($peticion);
        $salaQuery = $peticion->consulta('sala');
        $usuarioQuery = $peticion->consulta('usuario');
        $usuarioInicial = is_string($usuarioQuery) && Uuid::esValido(trim($usuarioQuery))
            ? trim($usuarioQuery)
            : '';

        return $this->pantalla(
            $organizador,
            salaId: is_string($salaQuery) ? $salaQuery : null,
            valores: ['usuario_id' => $usuarioInicial, 'motivo' => ''],
            bloqueado: $peticion->consulta('bloqueado') === '1',
            desbloqueado: $peticion->consulta('desbloqueado') === '1',
        );
    }

    /** POST /organizador/salas/{id}/bloqueos */
    public function bloquear(Peticion $peticion): Respuesta
    {
        $organizador = self::organizador($peticion);
        $salaId = (string) $peticion->parametro('id');
        // Valida propiedad antes que los campos: una sala ajena o inexistente siempre da 404.
        $this->salas->paraEditar($salaId, $organizador);

        $usuarioId = self::texto($peticion->campo('usuario_id'));
        $motivo = self::texto($peticion->campo('motivo'));
        $errores = $this->validar($organizador, $usuarioId, $motivo);
        $valores = ['usuario_id' => $usuarioId, 'motivo' => $motivo];

        if ($errores !== []) {
            return $this->pantalla($organizador, salaId: $salaId, valores: $valores, errores: $errores, estado: 422);
        }

        try {
            $this->bloqueos->bloquear($organizador, $salaId, $usuarioId, $motivo);
        } catch (ReglaIncumplida $regla) {
            return $this->pantalla(
                $organizador,
                salaId: $salaId,
                valores: $valores,
                errorGeneral: $regla->getMessage(),
                estado: 422,
            );
        }

        return $this->redirigir('/organizador/bloqueados?bloqueado=1&sala=' . rawurlencode($salaId));
    }

    /** POST /organizador/salas/{id}/bloqueos/{usuario}/quitar */
    public function desbloquear(Peticion $peticion): Respuesta
    {
        $organizador = self::organizador($peticion);
        $salaId = (string) $peticion->parametro('id');
        $usuarioId = (string) $peticion->parametro('usuario');

        $this->bloqueos->desbloquear($organizador, $salaId, $usuarioId);

        return $this->redirigir('/organizador/bloqueados?desbloqueado=1&sala=' . rawurlencode($salaId));
    }

    /**
     * @param array{usuario_id: string, motivo: string} $valores
     * @param array<string, string>                   $errores
     */
    private function pantalla(
        Usuario $organizador,
        ?string $salaId = null,
        array $valores = ['usuario_id' => '', 'motivo' => ''],
        array $errores = [],
        ?string $errorGeneral = null,
        bool $bloqueado = false,
        bool $desbloqueado = false,
        int $estado = 200,
    ): Respuesta {
        $salas = array_map(
            static fn (Sala $sala): array => ['id' => $sala->id, 'nombre' => $sala->datos->nombre],
            $this->salas->delOrganizador($organizador),
        );
        $salaElegida = null;
        foreach ($salas as $sala) {
            if ($salaId !== null && $sala['id'] === $salaId) {
                $salaElegida = $sala;
                break;
            }
        }
        $salaElegida ??= $salas[0] ?? null;

        $bloqueos = $this->bloqueos->delOrganizador($organizador);
        $idsABuscar = array_map(static fn (BloqueoDeSala $bloqueo): string => $bloqueo->usuarioId, $bloqueos);
        if (Uuid::esValido($valores['usuario_id'])) {
            $idsABuscar[] = $valores['usuario_id'];
        }
        // Todos los nombres de la pantalla en un solo llamado a cuentas.
        $nombres = $this->usuarios->nombresDe($idsABuscar);
        $usuarioElegidoNombre = Uuid::esValido($valores['usuario_id']) ? ($nombres[$valores['usuario_id']] ?? null) : null;

        return $this->vista(self::PLANTILLA, [
            'salas' => $salas,
            'sala_elegida' => $salaElegida,
            'usuario_elegido_nombre' => $usuarioElegidoNombre,
            'bloqueos' => array_map(
                static fn (BloqueoDeSala $bloqueo): array => [
                    'sala_id' => $bloqueo->salaId,
                    'sala_nombre' => $bloqueo->salaNombre,
                    'usuario_id' => $bloqueo->usuarioId,
                    'usuario_nombre' => $nombres[$bloqueo->usuarioId] ?? null,
                    'motivo' => $bloqueo->motivo,
                    'bloqueado_en' => FechaParaMostrar::diaYHora($bloqueo->bloqueadoEn),
                ],
                $bloqueos,
            ),
            'valores' => $valores,
            'errores' => $errores,
            'error_general' => $errorGeneral,
            'bloqueado' => $bloqueado,
            'desbloqueado' => $desbloqueado,
        ], $estado);
    }

    /**
     * @return array<string, string>
     */
    private function validar(Usuario $organizador, string $usuarioId, string $motivo): array
    {
        $errores = [];
        if ($usuarioId === '') {
            $errores['usuario_id'] = 'Indicá el identificador del espectador a bloquear.';
        } elseif (!Uuid::esValido($usuarioId)) {
            $errores['usuario_id'] = 'El identificador del espectador no tiene un formato válido.';
        } elseif (hash_equals($organizador->id, $usuarioId)) {
            $errores['usuario_id'] = 'No podés bloquearte a vos mismo en tu sala.';
        }

        if ($motivo === '') {
            $errores['motivo'] = 'Escribí el motivo del bloqueo.';
        } elseif (mb_strlen($motivo) > self::MAXIMO_MOTIVO) {
            $errores['motivo'] = 'El motivo no puede superar los 500 caracteres.';
        }

        return $errores;
    }

    private static function texto(mixed $valor): string
    {
        if (!is_string($valor) || !mb_check_encoding($valor, 'UTF-8')) {
            return '';
        }
        $limpio = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $valor);

        return trim($limpio ?? '');
    }

    private static function organizador(Peticion $peticion): Usuario
    {
        return $peticion->usuario() ?? throw new LogicException('Ruta de organizador sin usuario.');
    }
}
