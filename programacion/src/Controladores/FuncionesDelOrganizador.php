<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Controladores;

use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Seguridad\Usuario;
use IndieCinema\Programacion\Modelo\FuncionDelOrganizador;
use IndieCinema\Programacion\Servicios\ServicioDeFuncionesDelOrganizador;
use LogicException;

/**
 * «Mis funciones», en /organizador/funciones. La ruta pide el rol de organizador; que sean las de
 * sus salas y no las de cualquiera lo resuelve el servicio con el id del que pide.
 */
final class FuncionesDelOrganizador extends Controlador
{
    public function __construct(private readonly ServicioDeFuncionesDelOrganizador $servicio)
    {
    }

    public function listar(Peticion $peticion): Respuesta
    {
        $funciones = $this->servicio->listar(self::organizador($peticion));

        return $this->vista('mis_funciones.html.twig', ['funciones' => array_map(self::datos(...), $funciones)]);
    }

    /**
     * La fila de la tabla, con la fecha y el precio ya escritos como se leen, igual que en la
     * tarjeta de la cartelera. La plantilla recibe datos y no la entidad.
     *
     * @return array{id: string, titulo: string, titulos_elegidos: bool, sala: string, sala_id: string, sala_habilitada: bool, estado: string, estado_valor: string, es_final: bool, fecha: ?string, precio: string, cupo: int}
     */
    private static function datos(FuncionDelOrganizador $funcion): array
    {
        return [
            'id' => $funcion->id,
            // Una función con varias obras las proyecta todas: el título las nombra a todas.
            'titulo' => implode(' + ', $funcion->titulos),
            'titulos_elegidos' => $funcion->titulosElegidos,
            'sala' => $funcion->salaNombre,
            'sala_id' => $funcion->salaId,
            'sala_habilitada' => $funcion->salaHabilitada,
            'estado' => $funcion->estado->etiqueta(),
            'estado_valor' => $funcion->estado->value,
            'es_final' => $funcion->estado->esFinal(),
            // null mientras la función no tiene fecha: la plantilla lo dice con palabras.
            'fecha' => $funcion->fechaHora === null ? null : TarjetaDeFuncion::fecha($funcion->fechaHora),
            'precio' => TarjetaDeFuncion::precio($funcion->precioBase),
            'cupo' => $funcion->cupo,
        ];
    }

    private static function organizador(Peticion $peticion): Usuario
    {
        // La ruta pide el rol de organizador: si llegó acá, hay sesión.
        return $peticion->usuario() ?? throw new LogicException('Ruta de organizador sin usuario.');
    }
}
