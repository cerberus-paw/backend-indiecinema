<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Servicios;

use IndieCinema\Nucleo\Http\ClienteInterno;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Uuid;
use IndieCinema\Recaudacion\Modelo\CobroDeReserva;
use IndieCinema\Recaudacion\Modelo\CobrosDeFuncion;
use RuntimeException;

/**
 * Lo cobrado de cada función, pedido a funciones por la API interna con el token de recaudación
 * (GET /interno/funciones/{id}/cobros, o GET /interno/cobros?funciones=… de a cien). La plata se
 * mueve en funciones, que es la única con la credencial de Mercado Pago; acá sólo se reparte: si
 * se filtrara la base de recaudación, no hay con qué cobrar ni reembolsar.
 *
 * Lee la respuesta sin suponer nada: si falta un campo o tiene otro tipo, es que funciones cambió
 * lo que manda, y para repartir plata mejor un error claro que un número a medias.
 */
final class CobrosDeFunciones
{
    /** Lo más que acepta funciones en un pedido (CobrosInternos::MAXIMO_POR_PEDIDO). */
    public const MAXIMO_POR_PEDIDO = 100;

    private const MONTO = '/^\d+\.\d{2}$/';

    public function __construct(private readonly ClienteInterno $cliente)
    {
    }

    /**
     * @throws ExcepcionHttp    503 si funciones no responde a tiempo
     * @throws RuntimeException si el id no es un UUID, o si responde algo que no son cobros
     */
    public function deUna(string $funcionId): CobrosDeFuncion
    {
        if (!Uuid::esValido($funcionId)) {
            throw new RuntimeException("«{$funcionId}» no es el id de una función.");
        }
        $datos = $this->cliente->get('funciones', "/interno/funciones/{$funcionId}/cobros");
        if (!is_array($datos) || ($datos['funcion_id'] ?? null) !== $funcionId) {
            throw new RuntimeException('funciones no respondió los cobros a GET /interno/funciones/{id}/cobros.');
        }

        return self::cobros($funcionId, $datos);
    }

    /**
     * Las de varias funciones, de a cien por pedido.
     *
     * @param list<string> $funcionesIds
     *
     * @return array<string, CobrosDeFuncion> con el id como clave, en el orden en que se pidieron
     *
     * @throws ExcepcionHttp    503 si funciones no responde a tiempo
     * @throws RuntimeException si algún id no es un UUID, o si responde algo que no son cobros
     */
    public function deVarias(array $funcionesIds): array
    {
        $ids = array_values(array_unique($funcionesIds));
        foreach ($ids as $id) {
            if (!Uuid::esValido($id)) {
                throw new RuntimeException("«{$id}» no es el id de una función.");
            }
        }
        $cobros = [];
        foreach (array_chunk($ids, self::MAXIMO_POR_PEDIDO) as $tanda) {
            $datos = $this->cliente->get('funciones', '/interno/cobros', ['funciones' => implode(',', $tanda)]);
            $porFuncion = is_array($datos) ? ($datos['cobros'] ?? null) : null;
            if (!is_array($porFuncion)) {
                throw new RuntimeException('funciones no respondió los cobros a GET /interno/cobros.');
            }
            foreach ($tanda as $id) {
                if (!is_array($porFuncion[$id] ?? null)) {
                    throw new RuntimeException("funciones no respondió los cobros de la función {$id}.");
                }
                $cobros[$id] = self::cobros($id, $porFuncion[$id]);
            }
        }

        return $cobros;
    }

    /**
     * @param array<mixed> $datos
     */
    private static function cobros(string $funcionId, array $datos): CobrosDeFuncion
    {
        $reservas = $datos['reservas'] ?? null;
        $neto = $datos['neto'] ?? null;
        if (!is_array($reservas) || !array_is_list($reservas) || !is_string($neto) || preg_match(self::MONTO, $neto) !== 1) {
            throw new RuntimeException("funciones no respondió los cobros de la función {$funcionId}.");
        }

        return new CobrosDeFuncion($funcionId, array_map(static function (mixed $reserva) use ($funcionId): CobroDeReserva {
            if (
                !is_array($reserva)
                || !is_string($reserva['id'] ?? null) || !is_string($reserva['usuario_id'] ?? null)
                || !is_int($reserva['cantidad'] ?? null) || !is_string($reserva['estado'] ?? null)
                || !is_string($reserva['total'] ?? null) || preg_match(self::MONTO, $reserva['total']) !== 1
                || !is_string($reserva['cobrado'] ?? null) || preg_match(self::MONTO, $reserva['cobrado']) !== 1
                || !(($reserva['pago_id'] ?? null) === null || is_string($reserva['pago_id']))
                || !is_bool($reserva['reembolsada'] ?? null)
            ) {
                throw new RuntimeException("funciones no respondió una reserva cobrada de la función {$funcionId}.");
            }

            return new CobroDeReserva(
                $reserva['id'],
                $reserva['usuario_id'],
                $reserva['cantidad'],
                $reserva['total'],
                $reserva['cobrado'],
                $reserva['pago_id'],
                $reserva['estado'],
                $reserva['reembolsada'],
            );
        }, $reservas), $neto);
    }
}
