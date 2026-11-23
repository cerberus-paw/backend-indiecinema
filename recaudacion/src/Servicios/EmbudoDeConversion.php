<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Servicios;

use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Recaudacion\Modelo\DatosDelReporte;
use IndieCinema\Recaudacion\Modelo\Embudo;
use IndieCinema\Recaudacion\Modelo\EmbudoDelPeriodo;
use IndieCinema\Recaudacion\Modelo\FuncionFinalizada;
use IndieCinema\Recaudacion\Modelo\Monto;
use LogicException;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * El embudo de conversión de los reportes (EF-22.13): votó → reservó → pagó → fue → calificó, en
 * personas y en pesos. A Autocar el profe le pidió el 18/11 pensar en «cuántos clics terminaron en
 * plata»; acá el clic es el voto, que es lo que tenemos y no tiene un cine común.
 *
 * Quiénes votaron lo sabe el reporte (programación); hasta dónde llegó cada uno lo pregunta a
 * funciones (GET /interno/embudo), que manda también las reservas que se cayeron sin pagar: los
 * cobros, que usa el reparto, no las tienen. Si funciones no responde, el embudo sale vacío y con
 * un aviso.
 */
final class EmbudoDeConversion
{
    public function __construct(
        private readonly HastaDondeLlegaron $hastaDonde,
        private readonly LoggerInterface $log,
    ) {
    }

    public function delPeriodo(DatosDelReporte $datos): EmbudoDelPeriodo
    {
        $avisos = [];
        try {
            $llegadas = $this->hastaDonde->enLasFunciones(array_map(static fn (FuncionFinalizada $funcion): string => $funcion->id, $datos->funciones));
        } catch (ExcepcionHttp|RuntimeException|LogicException $error) {
            $this->log->warning('El reporte salió sin el embudo: {error}', ['error' => $error->getMessage()]);
            $llegadas = null;
            $avisos[] = 'Funciones no respondió: falta hasta dónde llegó cada uno que votó.';
        }

        $porSala = [];
        foreach ($datos->funciones as $funcion) {
            $porSala[$funcion->salaId][] = $funcion;
        }
        $salas = [];
        foreach ($porSala as $salaId => $funciones) {
            $salas[] = self::embudo((string) $salaId, $funciones[0]->salaNombre, $funciones, $llegadas ?? []);
        }
        usort($salas, static fn (Embudo $a, Embudo $b): int => [$b->personas['votaron'], $a->salaNombre] <=> [$a->personas['votaron'], $b->salaNombre]);

        return new EmbudoDelPeriodo(self::embudo(null, 'Todas las salas', $datos->funciones, $llegadas ?? []), $salas, $avisos);
    }

    /**
     * @param list<FuncionFinalizada>                                  $funciones
     * @param array<string, array<string, array{paso: int, centavos: int}>> $llegadas por función y usuario
     */
    private static function embudo(?string $salaId, string $nombre, array $funciones, array $llegadas): Embudo
    {
        $personas = array_fill_keys(Embudo::PASOS, 0);
        $centavos = array_fill_keys(Embudo::PASOS, 0);
        $sinVotar = 0;
        $sinVotarCentavos = 0;
        foreach ($funciones as $funcion) {
            $deLaFuncion = $llegadas[$funcion->id] ?? [];
            $votantes = array_fill_keys($funcion->votantesIds, true);
            $precio = Monto::centavos($funcion->precioBase);
            foreach (array_keys($votantes) as $votante) {
                $llegada = $deLaFuncion[(string) $votante] ?? ['paso' => 0, 'centavos' => 0];
                ++$personas['votaron'];
                // Lo que habría entrado si cada uno que votó compraba una entrada.
                $centavos['votaron'] += $precio;
                for ($paso = 1; $paso <= $llegada['paso']; ++$paso) {
                    ++$personas[Embudo::PASOS[$paso]];
                    $centavos[Embudo::PASOS[$paso]] += $llegada['centavos'];
                }
            }
            // Los que vinieron sin votar: el embudo no los cuenta, pero se dice cuántos son.
            foreach ($deLaFuncion as $usuario => $llegada) {
                if (!isset($votantes[$usuario]) && $llegada['paso'] >= HastaDondeLlegaron::PASO['pago']) {
                    ++$sinVotar;
                    $sinVotarCentavos += $llegada['centavos'];
                }
            }
        }

        return new Embudo($salaId, $nombre, $personas, $centavos, $sinVotar, $sinVotarCentavos);
    }
}
