<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Controladores;

use DateTimeImmutable;
use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Uuid;
use IndieCinema\Recaudacion\Modelo\Liquidacion;
use IndieCinema\Recaudacion\Modelo\Mes;
use IndieCinema\Recaudacion\Modelo\Monto;
use IndieCinema\Recaudacion\Servicios\ServicioDeLiquidaciones;
use IndieCinema\Recaudacion\Servicios\UsuariosDeCuentas;

/**
 * «Liquidaciones» del administrador, en /recaudacion/administracion/liquidaciones (EF-08.3): lo
 * que hay que transferirle a cada organizador y a cada dueño en el mes, y la marca de que ya se
 * hizo.
 *
 * La transferencia se hace fuera del sitio: marcarla es dejar escrito que se pagó, con la fecha y
 * una nota (el comprobante, el banco, lo que haga falta). Una liquidación marcada no vuelve atrás
 * ni cambia de monto, así que el botón desaparece.
 *
 * Las dos rutas piden el rol de administrador, declarado en rutas.php.
 */
final class LiquidacionesDelAdministrador extends Controlador
{
    private const PLANTILLA = 'administracion_liquidaciones.html.twig';

    /** Lo que entra en la columna liquidacion.nota. */
    private const LARGO_DE_LA_NOTA = 500;

    public function __construct(
        private readonly ServicioDeLiquidaciones $liquidaciones,
        private readonly UsuariosDeCuentas $usuarios,
    ) {
    }

    /** GET /recaudacion/administracion/liquidaciones */
    public function mostrar(Peticion $peticion): Respuesta
    {
        $meses = $this->liquidaciones->meses();

        return $this->pantalla(
            self::mesElegido($peticion, $meses),
            $meses,
            transferida: $peticion->consulta('transferida') === '1',
            yaEstaba: $peticion->consulta('ya') === '1',
        );
    }

    /**
     * POST /recaudacion/administracion/liquidaciones/{id}/transferida.
     *
     * @throws ExcepcionHttp 404 si el id no es el de ninguna liquidación
     */
    public function marcarTransferida(Peticion $peticion): Respuesta
    {
        $id = (string) $peticion->parametro('id');
        $liquidacion = Uuid::esValido($id) ? $this->liquidaciones->buscar($id) : null;
        if ($liquidacion === null) {
            throw ExcepcionHttp::noEncontrada();
        }

        $nota = self::texto($peticion->campo('nota'));
        if (mb_strlen($nota) > self::LARGO_DE_LA_NOTA) {
            return $this->pantalla(
                $liquidacion->periodo,
                $this->liquidaciones->meses(),
                error: 'La nota de la transferencia puede tener hasta ' . self::LARGO_DE_LA_NOTA . ' caracteres.',
                estado: 422,
            );
        }

        $mes = $liquidacion->periodo->comoTexto();
        $marcada = $this->liquidaciones->marcarTransferida($id, $nota === '' ? null : $nota);

        return $this->redirigir("/administracion/liquidaciones?mes={$mes}&" . ($marcada ? 'transferida=1' : 'ya=1'));
    }

    /**
     * @param list<Mes> $meses los que tienen alguna liquidación, para el filtro
     */
    private function pantalla(
        ?Mes $mes,
        array $meses,
        bool $transferida = false,
        bool $yaEstaba = false,
        ?string $error = null,
        int $estado = 200,
    ): Respuesta {
        $liquidaciones = $mes === null ? [] : $this->liquidaciones->delMes($mes);
        $nombres = $this->usuarios->nombresDe(array_map(
            static fn (Liquidacion $liquidacion): string => $liquidacion->beneficiarioId,
            $liquidaciones,
        ));
        $pendiente = array_sum(array_map(
            static fn (Liquidacion $liquidacion): int => $liquidacion->estaTransferida() ? 0 : $liquidacion->centavos,
            $liquidaciones,
        ));

        return $this->vista(self::PLANTILLA, [
            'meses' => array_map(static fn (Mes $otro): array => [
                'mes' => $otro->comoTexto(),
                'nombre' => $otro->nombre(),
                'url' => '/recaudacion/administracion/liquidaciones?mes=' . $otro->comoTexto(),
                'actual' => $mes !== null && $otro->comoTexto() === $mes->comoTexto(),
            ], $meses),
            'mes' => $mes?->nombre(),
            'liquidaciones' => array_map(static fn (Liquidacion $liquidacion): array => [
                'id' => $liquidacion->id,
                'beneficiario' => $nombres[$liquidacion->beneficiarioId] ?? null,
                'beneficiario_id' => $liquidacion->beneficiarioId,
                'monto' => Monto::comoPesos($liquidacion->centavos),
                'transferida' => $liquidacion->estaTransferida(),
                'estado' => $liquidacion->estado->comoTexto(),
                'transferida_en' => $liquidacion->transferidaEn === null ? null : self::fecha($liquidacion->transferidaEn),
                'nota' => $liquidacion->nota,
            ], $liquidaciones),
            'total_pendiente' => Monto::comoPesos($pendiente),
            'transferida' => $transferida,
            'ya_estaba' => $yaEstaba,
            'error' => $error,
        ], $estado);
    }

    /**
     * El mes de ?mes=, si hay liquidaciones de él, o el más nuevo que haya. Null si todavía no hay
     * ninguna liquidación armada.
     *
     * @param list<Mes> $meses del más nuevo al más viejo
     */
    private static function mesElegido(Peticion $peticion, array $meses): ?Mes
    {
        $pedido = $peticion->consulta('mes');
        $elegido = is_string($pedido) ? Mes::desdeTexto($pedido) : null;
        foreach ($meses as $mes) {
            if ($elegido !== null && $mes->comoTexto() === $elegido->comoTexto()) {
                return $mes;
            }
        }

        return $meses === [] ? null : $meses[0];
    }

    /** «16 de noviembre de 2026». */
    private static function fecha(DateTimeImmutable $momento): string
    {
        return "{$momento->format('j')} de " . Mes::de($momento)->nombre();
    }

    private static function texto(mixed $valor): string
    {
        if (!is_string($valor) || !mb_check_encoding($valor, 'UTF-8')) {
            return '';
        }
        $limpio = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $valor);

        return trim($limpio ?? '');
    }
}
