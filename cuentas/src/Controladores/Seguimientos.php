<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Controladores;

use IndieCinema\Cuentas\Modelo\TipoDeSeguimiento;
use IndieCinema\Cuentas\Repositorios\RepositorioDeSeguimientos;
use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Uuid;
use LogicException;

/**
 * Seguir y dejar de seguir una sala o a su organizador (EF-15.4).
 *
 * El botón está en la ficha de la sala, que arma programación: en lugar de preguntarle a cuentas
 * al armar la ficha, el componente del front consulta el estado acá por fetch y lo cambia con un
 * POST. Sin JavaScript, el formulario del botón hace el mismo POST y vuelve a la ficha.
 */
final class Seguimientos extends Controlador
{
    public function __construct(private readonly RepositorioDeSeguimientos $seguimientos)
    {
    }

    /**
     * GET /cuenta/seguimientos?tipo=…&id=…: si quien está en sesión lo sigue.
     */
    public function estado(Peticion $peticion): Respuesta
    {
        $usuario = $peticion->usuario() ?? throw new LogicException('La ruta pide sesión: sin ella no llega acá.');
        $objeto = self::objeto($peticion->consulta('tipo'), $peticion->consulta('id'));
        if ($objeto === null) {
            return $this->json(['error' => '«tipo» es sala u organizador, e «id» un UUID.'], 400);
        }

        return $this->json(['sigue' => $this->seguimientos->sigue($usuario->id, ...$objeto)]);
    }

    /**
     * POST /cuenta/seguimientos: «accion» seguir o dejar. Sin JavaScript llega «alternar», porque
     * la ficha no sabe si ya la sigue: lo resuelve el servidor. Al JavaScript le contesta el
     * estado nuevo; al formulario, lo devuelve a la página de donde vino.
     */
    public function cambiar(Peticion $peticion): Respuesta
    {
        $usuario = $peticion->usuario() ?? throw new LogicException('La ruta pide sesión: sin ella no llega acá.');
        $objeto = self::objeto($peticion->campo('tipo'), $peticion->campo('id'));
        $accion = $peticion->campo('accion');
        if ($objeto === null || !in_array($accion, ['seguir', 'dejar', 'alternar'], true)) {
            return $this->json(['error' => '«tipo» es sala u organizador, «id» un UUID y «accion» seguir, dejar o alternar.'], 400);
        }
        // Seguirse a uno mismo no tiene sentido: el organizador ve el botón en sus propias salas.
        if ($objeto[0] === TipoDeSeguimiento::Organizador && $objeto[1] === $usuario->id) {
            return $this->json(['error' => 'No te podés seguir a vos.'], 422);
        }

        if ($accion === 'alternar') {
            $accion = $this->seguimientos->sigue($usuario->id, ...$objeto) ? 'dejar' : 'seguir';
        }
        if ($accion === 'seguir') {
            $this->seguimientos->seguir($usuario->id, ...$objeto);
        } else {
            $this->seguimientos->dejar($usuario->id, ...$objeto);
        }

        if ($peticion->aceptaJson()) {
            return $this->json(['sigue' => $accion === 'seguir']);
        }

        return Respuesta::redireccion(self::volver($peticion->campo('volver')));
    }

    /**
     * @return ?array{0: TipoDeSeguimiento, 1: string}
     */
    private static function objeto(mixed $tipo, mixed $id): ?array
    {
        $tipo = is_string($tipo) ? TipoDeSeguimiento::tryFrom($tipo) : null;

        return $tipo !== null && is_string($id) && Uuid::esValido($id) ? [$tipo, $id] : null;
    }

    /**
     * Sólo una ruta de este mismo sitio («/salas/…»): un «volver» con otro dominio o con «//»
     * dejaría usar el formulario para mandar a cualquiera a cualquier lado.
     */
    private static function volver(mixed $volver): string
    {
        return is_string($volver) && preg_match('#^/(?![/\\\\])[^\s]*$#', $volver) === 1 ? $volver : '/cuenta/';
    }
}
