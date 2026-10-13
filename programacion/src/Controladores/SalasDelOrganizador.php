<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Controladores;

use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Seguridad\Usuario;
use IndieCinema\Programacion\Servicios\ServicioDeSalas;
use LogicException;

/**
 * «Mis salas» y la edición de la sala, en /organizador/salas/…; las nuevas entran por la
 * solicitud de sala (SolicitudDeSala). Las rutas piden el rol de organizador y el núcleo ya
 * controló el token CSRF de los POST antes de llegar acá.
 */
final class SalasDelOrganizador extends Controlador
{
    private const PLANTILLA = 'alta_sala.html.twig';

    public function __construct(private readonly ServicioDeSalas $servicio)
    {
    }

    public function listar(Peticion $peticion): Respuesta
    {
        $salas = $this->servicio->delOrganizador(self::organizador($peticion));

        return $this->vista('mis_salas.html.twig', ['salas' => array_map(TarjetaDeSala::datos(...), $salas)]);
    }

    public function editar(Peticion $peticion): Respuesta
    {
        $sala = $this->servicio->paraEditar((string) $peticion->parametro('id'), self::organizador($peticion));

        return $this->formulario($sala->id, [
            'formulario' => FormularioDeSala::desdeDatos($sala->datos),
            'mensaje_exito' => $peticion->consulta('guardada') === '1'
                ? ($sala->habilitada ? 'Los cambios ya se ven en el sitio.' : 'Se publica en el sitio cuando aprueben su solicitud.')
                : null,
        ]);
    }

    public function actualizar(Peticion $peticion): Respuesta
    {
        // Primero la propiedad: a quien manda el formulario de una sala ajena no se le dice si
        // los datos estaban bien.
        $sala = $this->servicio->paraEditar((string) $peticion->parametro('id'), self::organizador($peticion));

        $formulario = FormularioDeSala::desdeCampos($peticion->cuerpo(), $peticion->archivo('imagen'));
        $datos = $formulario->datos();
        if ($datos === null) {
            return $this->formulario($sala->id, ['formulario' => $formulario], 422);
        }
        $this->servicio->actualizar($sala, $datos, $formulario->imagen);

        return $this->redirigir("/organizador/salas/{$sala->id}/editar?guardada=1");
    }

    /**
     * @param array{formulario?: FormularioDeSala, mensaje_exito?: ?string} $extra
     */
    private function formulario(string $id, array $extra, int $estado = 200): Respuesta
    {
        $formulario = $extra['formulario'] ?? null;

        return $this->vista(self::PLANTILLA, [
            'es_edicion' => true,
            'accion' => "/organizador/salas/{$id}/editar",
            'valores' => $formulario?->valores ?? [],
            'errores' => $formulario?->errores ?? [],
            'error_general' => $formulario?->errores ? 'Revisá los campos marcados.' : null,
            'mensaje_exito' => $extra['mensaje_exito'] ?? null,
        ], $estado);
    }

    private static function organizador(Peticion $peticion): Usuario
    {
        // La ruta pide el rol de organizador: si llegó acá, hay sesión.
        return $peticion->usuario() ?? throw new LogicException('Ruta de organizador sin usuario.');
    }
}
