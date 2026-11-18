<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Controladores;

use IndieCinema\Funciones\Modelo\Reserva;
use IndieCinema\Funciones\Repositorios\RepositorioDeReservas;
use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Uuid;

/**
 * Lo cobrado de cada función, para recaudación (EF-02.5), que reparte la plata pero no la mueve:
 * la credencial de Mercado Pago está sólo en funciones, y recaudación no lee su base. Sólo la
 * llama recaudación, con su token: lo controla la ruta.
 *
 * Por cada reserva en pie: su total y lo que se cobró de verdad. Una reserva a la gorra, o que se
 * confirmó con el cobro apagado, no tiene pago: total puede ser más que 0, pero cobrado es 0.
 *
 * También van las canceladas que se habían pagado (EF-06.4), con reembolsada diciendo si la plata
 * ya volvió. El neto es lo cobrado menos lo reembolsado: una devuelta no se reparte, y una que
 * quedó pendiente de devolver sí, porque todavía está en la cuenta.
 *
 * Los montos van como texto con dos decimales, como los guarda la base: sin pasar por float.
 */
final class CobrosInternos extends Controlador
{
    /** Como la ocupación: un pedido más largo es un error de quien llama. */
    private const MAXIMO_POR_PEDIDO = 100;

    public function __construct(private readonly RepositorioDeReservas $reservas)
    {
    }

    /**
     * GET /interno/funciones/{id}/cobros.
     *
     * @throws ExcepcionHttp 404 si el id no es un UUID
     */
    public function deUna(Peticion $peticion): Respuesta
    {
        $id = (string) $peticion->parametro('id');
        if (!Uuid::esValido($id)) {
            throw ExcepcionHttp::noEncontrada();
        }

        return $this->json(['funcion_id' => $id, ...self::cobros($this->reservas->cobradasDe([$id])[$id])]);
    }

    /**
     * GET /interno/cobros?funciones=id1,id2,…, para repartir de a varias. Las que no tienen
     * reservas vienen con la lista vacía y el neto en cero; los ids que no son UUID son un 400,
     * porque los manda recaudación de las funciones que ya conoce.
     */
    public function deVarias(Peticion $peticion): Respuesta
    {
        $lista = $peticion->consulta('funciones');
        $ids = is_string($lista) && $lista !== '' ? array_values(array_unique(explode(',', $lista))) : [];
        if ($ids === [] || count($ids) > self::MAXIMO_POR_PEDIDO) {
            return $this->json(['error' => 'Se piden entre 1 y ' . self::MAXIMO_POR_PEDIDO . ' funciones por vez.'], 400);
        }
        if (array_filter($ids, static fn (string $id): bool => !Uuid::esValido($id)) !== []) {
            return $this->json(['error' => 'Los ids de las funciones son UUID.'], 400);
        }

        return $this->json(['cobros' => (object) array_map(self::cobros(...), $this->reservas->cobradasDe($ids))]);
    }

    /**
     * @param list<Reserva> $reservas
     *
     * @return array{reservas: list<array<string, mixed>>, neto: string}
     */
    private static function cobros(array $reservas): array
    {
        $neto = 0;
        $filas = [];
        foreach ($reservas as $reserva) {
            $cobrado = $reserva->pagoId === null ? '0.00' : $reserva->total;
            $reembolsada = $reserva->reembolsoId !== null;
            $neto += $reembolsada ? 0 : self::centavos($cobrado);
            $filas[] = [
                'id' => $reserva->id,
                'usuario_id' => $reserva->usuarioId,
                'cantidad' => $reserva->cantidad,
                'total' => $reserva->total,
                'cobrado' => $cobrado,
                'pago_id' => $reserva->pagoId,
                'estado' => $reserva->estado->value,
                'reembolsada' => $reembolsada,
            ];
        }

        return ['reservas' => $filas, 'neto' => sprintf('%d.%02d', intdiv($neto, 100), $neto % 100)];
    }

    /** «3500.00» → 350000: se suma en centavos enteros, sin los redondeos de float. */
    private static function centavos(string $monto): int
    {
        [$enteros, $decimales] = explode('.', $monto . '.00');

        return (int) $enteros * 100 + (int) substr(str_pad($decimales, 2, '0'), 0, 2);
    }
}
