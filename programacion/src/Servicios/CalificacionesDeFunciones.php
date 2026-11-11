<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Servicios;

use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\ClienteInterno;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Las calificaciones de las funciones, que son del subsistema funciones (EF-04.3): los promedios y
 * las últimas que muestran las fichas de la sala y de la obra.
 *
 * Como la ocupación, son un dato de más: si funciones no responde, la ficha sale igual, sin las
 * calificaciones y con un aviso. Por eso cada método devuelve null en lugar de cortar con un 503.
 */
final class CalificacionesDeFunciones
{
    private ?ClienteInterno $cliente = null;

    public function __construct(
        private readonly Configuracion $configuracion,
        private readonly LoggerInterface $log,
    ) {
    }

    /**
     * El promedio y la cantidad de cada sala, obra, organizador o función que tenga calificaciones,
     * y cuántos entraron a cada función.
     *
     * @param array{salas?: list<string>, obras?: list<string>, organizadores?: list<string>, funciones?: list<string>} $ids
     *
     * @return ?array{salas: array<string, array{promedio: float, cantidad: int}>, obras: array<string, array{promedio: float, cantidad: int}>, organizadores: array<string, array{promedio: float, cantidad: int}>, funciones: array<string, array{promedio: float, cantidad: int}>, asistentes: array<string, int>}
     *                null si funciones no responde
     */
    public function resumen(array $ids): ?array
    {
        $consulta = array_map(static fn (array $lista): string => implode(',', $lista), array_filter($ids));

        return $this->pedir(static function (ClienteInterno $cliente) use ($consulta): array {
            $datos = $cliente->get('funciones', '/interno/calificaciones/resumen', $consulta);
            if (!is_array($datos)) {
                throw new RuntimeException('funciones no respondió el resumen de las calificaciones.');
            }
            $resumen = [];
            foreach (['salas', 'obras', 'organizadores', 'funciones'] as $tipo) {
                $porId = $datos[$tipo] ?? null;
                if (!is_array($porId)) {
                    throw new RuntimeException("funciones no respondió las calificaciones de las {$tipo}.");
                }
                $resumen[$tipo] = [];
                foreach ($porId as $id => $valores) {
                    if (!is_string($id) || !is_array($valores) || !is_numeric($valores['promedio'] ?? null) || !is_int($valores['cantidad'] ?? null)) {
                        throw new RuntimeException("funciones no respondió las calificaciones de las {$tipo}.");
                    }
                    $resumen[$tipo][$id] = ['promedio' => (float) $valores['promedio'], 'cantidad' => $valores['cantidad']];
                }
            }
            $asistentes = $datos['asistentes'] ?? null;
            if (!is_array($asistentes) || array_filter($asistentes, is_int(...)) !== $asistentes) {
                throw new RuntimeException('funciones no respondió los asistentes de las funciones.');
            }
            $resumen['asistentes'] = $asistentes;

            return $resumen;
        });
    }

    /**
     * Las últimas calificaciones de una sala o de una obra, de la más nueva a la más vieja.
     *
     * @param 'sala'|'obra' $de
     *
     * @return ?list<array{funcion_id: string, puntaje: int, comentario: ?string, espectador: ?string, fecha: string}>
     *                null si funciones no responde
     */
    public function ultimas(string $de, string $id): ?array
    {
        return $this->pedir(static function (ClienteInterno $cliente) use ($de, $id): array {
            $datos = $cliente->get('funciones', '/interno/calificaciones', [$de => $id]);
            $lista = is_array($datos) ? ($datos['calificaciones'] ?? null) : null;
            if (!is_array($lista) || !array_is_list($lista)) {
                throw new RuntimeException('funciones no respondió las últimas calificaciones.');
            }
            foreach ($lista as $calificacion) {
                if (
                    !is_array($calificacion) || !is_string($calificacion['funcion_id'] ?? null) || !is_int($calificacion['puntaje'] ?? null)
                    || !is_string($calificacion['fecha'] ?? null)
                    || !(($calificacion['comentario'] ?? null) === null || is_string($calificacion['comentario']))
                    || !(($calificacion['espectador'] ?? null) === null || is_string($calificacion['espectador']))
                ) {
                    throw new RuntimeException('funciones no respondió las últimas calificaciones.');
                }
            }

            return $lista;
        });
    }

    /**
     * @template T
     *
     * @param callable(ClienteInterno): T $pedido
     *
     * @return ?T
     */
    private function pedir(callable $pedido): mixed
    {
        // Sin funciones en config.ini, como en OcupacionDeFunciones: la ficha sale sin calificaciones.
        if ($this->configuracion->obtener('subsistemas.funciones') === null || $this->configuracion->obtener('interno.token') === null) {
            return null;
        }
        try {
            return $pedido($this->cliente ??= ClienteInterno::desdeConfiguracion($this->configuracion, $this->log));
        } catch (ExcepcionHttp) {
            // ClienteInterno ya dejó en el log que funciones no respondió.
            return null;
        } catch (RuntimeException $error) {
            $this->log->error('Sin las calificaciones: {mensaje}', ['mensaje' => $error->getMessage()]);

            return null;
        }
    }
}
