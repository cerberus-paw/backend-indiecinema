<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Servicios;

use DateTimeImmutable;
use DateTimeZone;
use IndieCinema\Nucleo\Http\ClienteInterno;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Recaudacion\Modelo\DatosDelReporte;
use IndieCinema\Recaudacion\Modelo\FuncionFinalizada;
use IndieCinema\Recaudacion\Modelo\ModeracionDeSala;
use IndieCinema\Recaudacion\Modelo\ModeracionDelPeriodo;
use LogicException;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * La moderación y la conducta en los reportes del administrador (EF-22.4). El profe, el 18/11:
 * «en los reportes generales de esto no hablamos de nada, y puede ser información importante para
 * el administrador».
 *
 * - A moderación le pide las denuncias del período, las salas suspendidas hoy y las solicitudes de
 *   sala (GET /interno/resumen).
 * - A funciones, las ausencias y las calificaciones de conducta negativas de cada función
 *   (GET /interno/conducta/funciones) y cuántos de los que reservaron están bajo el umbral
 *   (GET /interno/usuarios/conducta).
 *
 * Moderación no sabe de qué sala es cada función: lo sabe el reporte. Por eso las denuncias se
 * cuentan acá, por sala. Si un subsistema no responde, o falta su dirección en config.ini, el
 * reporte sale igual, con un aviso.
 */
final class ModeracionYConducta
{
    /** Lo más que acepta funciones en un pedido. */
    private const MAXIMO_POR_PEDIDO = 100;

    /** El período se elige en días de la Argentina; moderación guarda en UTC. */
    private const ZONA = 'America/Argentina/Buenos_Aires';

    private const SIN_DENUNCIAS = ['abierta' => 0, 'desestimada' => 0, 'resuelta' => 0];

    public function __construct(
        private readonly ClienteInterno $cliente,
        private readonly LoggerInterface $log,
    ) {
    }

    public function delPeriodo(DatosDelReporte $datos): ModeracionDelPeriodo
    {
        $avisos = [];
        $porSala = [];
        $deLaFuncion = [];
        foreach ($datos->funciones as $funcion) {
            $porSala[$funcion->salaId][] = $funcion;
            $deLaFuncion[$funcion->id] = $funcion->salaId;
        }

        try {
            $moderacion = $this->moderacion($datos);
        } catch (ExcepcionHttp|RuntimeException|LogicException $error) {
            $this->log->warning('El reporte salió sin la moderación: {error}', ['error' => $error->getMessage()]);
            $moderacion = ['denuncias' => [], 'suspendidas' => [], 'solicitudes' => []];
            $avisos[] = 'Moderación no respondió: faltan las denuncias, las salas suspendidas y las solicitudes.';
        }
        try {
            $conducta = $this->conductaDeLasFunciones(array_keys($deLaFuncion));
            $bajoUmbral = $this->bajoUmbral($datos);
        } catch (ExcepcionHttp|RuntimeException|LogicException $error) {
            $this->log->warning('El reporte salió sin la conducta: {error}', ['error' => $error->getMessage()]);
            $conducta = [];
            $bajoUmbral = [];
            $avisos[] = 'Funciones no respondió: faltan las ausencias y la conducta de los espectadores.';
        }

        $denunciasDeSala = [];
        $otras = self::SIN_DENUNCIAS;
        foreach ($moderacion['denuncias'] as $denuncia) {
            $sala = match ($denuncia['tipo']) {
                'sala' => isset($porSala[$denuncia['objeto_id']]) ? $denuncia['objeto_id'] : null,
                'funcion' => $deLaFuncion[$denuncia['objeto_id']] ?? null,
                default => null,
            };
            if ($sala === null) {
                $otras[$denuncia['estado']] += $denuncia['cantidad'];
            } else {
                $denunciasDeSala[$sala] ??= self::SIN_DENUNCIAS;
                $denunciasDeSala[$sala][$denuncia['estado']] += $denuncia['cantidad'];
            }
        }
        $suspendidas = [];
        foreach ($moderacion['suspendidas'] as $suspension) {
            $suspendidas[$suspension['sala_id']] ??= $suspension['causa'];
        }

        $salas = [];
        foreach ($porSala as $salaId => $funciones) {
            $salaId = (string) $salaId;
            $ausentes = 0;
            $negativas = 0;
            $compradores = [];
            foreach ($funciones as $funcion) {
                $ausentes += $conducta[$funcion->id]['ausentes'] ?? 0;
                $negativas += $conducta[$funcion->id]['negativas'] ?? 0;
                foreach ($datos->ventasDe($funcion->id)->compradoresIds ?? [] as $id) {
                    $compradores[$id] = true;
                }
            }
            $salas[] = new ModeracionDeSala(
                $salaId,
                $funciones[0]->salaNombre,
                $denunciasDeSala[$salaId] ?? self::SIN_DENUNCIAS,
                $suspendidas[$salaId] ?? null,
                $ausentes,
                $negativas,
                count(array_filter(array_keys($compradores), static fn (int|string $id): bool => $bajoUmbral[(string) $id] ?? false)),
                count($compradores),
            );
        }
        usort($salas, static fn (ModeracionDeSala $a, ModeracionDeSala $b): int => [$b->denuncias['abierta'], $b->totalDeDenuncias(), $b->negativas + $b->ausentes, $a->salaNombre]
            <=> [$a->denuncias['abierta'], $a->totalDeDenuncias(), $a->negativas + $a->ausentes, $b->salaNombre]);

        return new ModeracionDelPeriodo(
            $salas,
            $moderacion['solicitudes'],
            $otras,
            count(array_diff_key($suspendidas, $porSala)),
            $avisos,
        );
    }

    /**
     * @return array{denuncias: list<array{tipo: string, objeto_id: string, estado: string, cantidad: int}>, suspendidas: list<array{sala_id: string, causa: string}>, solicitudes: array<string, int>}
     *
     * @throws ExcepcionHttp|RuntimeException|LogicException si moderación no responde, responde otra
     *                                                       cosa o falta su dirección en config.ini
     */
    private function moderacion(DatosDelReporte $datos): array
    {
        $zona = new DateTimeZone(self::ZONA);
        $utc = new DateTimeZone('UTC');
        $desde = new DateTimeImmutable($datos->periodo->desde->format('Y-m-d'), $zona);
        $hasta = new DateTimeImmutable($datos->periodo->hasta->format('Y-m-d') . ' +1 day', $zona);
        $respuesta = $this->cliente->get('moderacion', '/interno/resumen', [
            'desde' => $desde->setTimezone($utc)->format('Y-m-d H:i:s'),
            'hasta' => $hasta->setTimezone($utc)->format('Y-m-d H:i:s'),
        ]);
        if (!is_array($respuesta) || !is_array($respuesta['denuncias'] ?? null) || !is_array($respuesta['suspendidas'] ?? null) || !is_array($respuesta['solicitudes'] ?? null)) {
            throw new RuntimeException('moderación no respondió el resumen a GET /interno/resumen.');
        }
        $denuncias = [];
        foreach ($respuesta['denuncias'] as $fila) {
            if (!is_array($fila) || !is_string($fila['tipo'] ?? null) || !is_string($fila['objeto_id'] ?? null)
                || !in_array($fila['estado'] ?? null, ['abierta', 'desestimada', 'resuelta'], true) || !is_int($fila['cantidad'] ?? null)) {
                throw new RuntimeException('moderación respondió una denuncia que no se entiende.');
            }
            $denuncias[] = ['tipo' => $fila['tipo'], 'objeto_id' => $fila['objeto_id'], 'estado' => (string) $fila['estado'], 'cantidad' => $fila['cantidad']];
        }
        $suspendidas = [];
        foreach ($respuesta['suspendidas'] as $fila) {
            if (!is_array($fila) || !is_string($fila['sala_id'] ?? null) || !is_string($fila['causa'] ?? null)) {
                throw new RuntimeException('moderación respondió una suspensión que no se entiende.');
            }
            $suspendidas[] = ['sala_id' => $fila['sala_id'], 'causa' => $fila['causa']];
        }
        $solicitudes = [];
        foreach ($respuesta['solicitudes'] as $estado => $cantidad) {
            if (is_int($cantidad)) {
                $solicitudes[(string) $estado] = $cantidad;
            }
        }

        return ['denuncias' => $denuncias, 'suspendidas' => $suspendidas, 'solicitudes' => $solicitudes];
    }

    /**
     * @param list<string> $funcionesIds
     *
     * @return array<string, array{ausentes: int, negativas: int}>
     */
    private function conductaDeLasFunciones(array $funcionesIds): array
    {
        $conducta = [];
        foreach (array_chunk($funcionesIds, self::MAXIMO_POR_PEDIDO) as $tanda) {
            $respuesta = $this->cliente->get('funciones', '/interno/conducta/funciones', ['funciones' => implode(',', $tanda)]);
            $porFuncion = is_array($respuesta) ? ($respuesta['funciones'] ?? null) : null;
            if (!is_array($porFuncion)) {
                throw new RuntimeException('funciones no respondió la conducta a GET /interno/conducta/funciones.');
            }
            foreach ($porFuncion as $id => $fila) {
                if (is_array($fila) && is_int($fila['ausentes'] ?? null) && is_int($fila['negativas'] ?? null)) {
                    $conducta[(string) $id] = ['ausentes' => $fila['ausentes'], 'negativas' => $fila['negativas']];
                }
            }
        }

        return $conducta;
    }

    /**
     * Quiénes de los que reservaron están bajo el umbral de conducta hoy.
     *
     * @return array<string, bool>
     */
    private function bajoUmbral(DatosDelReporte $datos): array
    {
        $ids = [];
        foreach ($datos->funciones as $funcion) {
            foreach ($datos->ventasDe($funcion->id)->compradoresIds ?? [] as $id) {
                $ids[$id] = true;
            }
        }
        $bajo = [];
        foreach (array_chunk(array_map('strval', array_keys($ids)), self::MAXIMO_POR_PEDIDO) as $tanda) {
            $respuesta = $this->cliente->get('funciones', '/interno/usuarios/conducta', ['ids' => implode(',', $tanda)]);
            $porUsuario = is_array($respuesta) ? ($respuesta['conductas'] ?? null) : null;
            if (!is_array($porUsuario)) {
                throw new RuntimeException('funciones no respondió la conducta a GET /interno/usuarios/conducta.');
            }
            foreach ($porUsuario as $id => $fila) {
                $bajo[(string) $id] = is_array($fila) && ($fila['bajo_umbral'] ?? false) === true;
            }
        }

        return $bajo;
    }
}
