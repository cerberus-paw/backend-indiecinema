<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Servicios;

use DateTimeImmutable;
use IndieCinema\Nucleo\Uuid;
use IndieCinema\Recaudacion\Modelo\ParametroDeReparto;
use IndieCinema\Recaudacion\Modelo\ReglaIncumplida;
use IndieCinema\Recaudacion\Repositorios\RepositorioDeParametrosDeReparto;

/**
 * Los porcentajes del reparto y desde cuándo rige cada uno (EF-08.1): el administrador los cambia
 * y el reparto de cada función usa el vigente en su fecha_hora (EF-08.2).
 *
 * Un parámetro no se corrige ni se borra: cargar() agrega otro con su vigencia. Por eso la
 * vigencia no puede empezar antes de ahora: el reparto de las funciones que ya pasaron no se
 * cambia, que es lo que pide EF-08.1.
 */
final class ServicioDeParametrosDeReparto
{
    /** Cuántos cambios muestra el historial de la pantalla. */
    public const CAMBIOS_DEL_HISTORIAL = 50;

    public function __construct(private readonly RepositorioDeParametrosDeReparto $repositorio)
    {
    }

    /**
     * El que rige ahora (o en la fecha de una función, al repartir lo suyo).
     */
    public function vigenteEn(DateTimeImmutable $momento): ?ParametroDeReparto
    {
        return $this->repositorio->vigenteEn($momento);
    }

    /**
     * Los cambios, del que rige último al primero.
     *
     * @return list<ParametroDeReparto>
     */
    public function historial(): array
    {
        return $this->repositorio->historial(self::CAMBIOS_DEL_HISTORIAL);
    }

    /**
     * Carga el reparto que va a regir desde una fecha. Los porcentajes van en centésimas de punto
     * (70 % es 7000) y los tres tienen que sumar 100 %.
     *
     * @throws ReglaIncumplida si no suman 100 %, si la vigencia ya pasó o si ya hay uno que
     *                         empieza en ese mismo momento
     */
    public function cargar(
        int $sala,
        int $dueno,
        int $plataforma,
        DateTimeImmutable $vigenteDesde,
        string $administradorId,
        DateTimeImmutable $ahora = new DateTimeImmutable(),
    ): ParametroDeReparto {
        // Al minuto, que es lo que elige el administrador en el campo: la vigencia de «ahora
        // mismo» no se rechaza por los segundos que tardó en enviar el formulario.
        if ($vigenteDesde < $ahora->setTime((int) $ahora->format('H'), (int) $ahora->format('i'))) {
            throw new ReglaIncumplida('La vigencia no puede empezar antes de ahora: el reparto de las funciones que ya pasaron no se cambia.');
        }
        if ($this->repositorio->hayUnoVigenteDesde($vigenteDesde)) {
            throw new ReglaIncumplida('Ya hay un reparto que empieza a regir en ese momento. Elegí otra fecha y hora.');
        }

        // El constructor controla que sumen 100 % y que ninguno sea negativo.
        $parametro = new ParametroDeReparto(
            Uuid::nuevo(),
            $sala,
            $dueno,
            $plataforma,
            $vigenteDesde,
            $administradorId,
            $ahora,
        );
        $this->repositorio->guardar($parametro);

        return $parametro;
    }
}
