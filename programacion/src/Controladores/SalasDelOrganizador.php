<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Controladores;

use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Seguridad\Usuario;
use IndieCinema\Programacion\Modelo\Sala;
use IndieCinema\Programacion\Servicios\ServicioDeSalas;
use LogicException;

/**
 * «Mis salas» y la edición de la sala, en /organizador/salas/…; las nuevas entran por la
 * solicitud de sala, que es de moderación: la sala existe recién cuando se aprueba (EF-07.2). Las
 * rutas piden el rol de organizador y el núcleo ya controló el token CSRF de los POST antes de
 * llegar acá.
 *
 * La dirección, la localidad y una capacidad mayor las revisa la moderación con el seguro y la
 * habilitación (EF-07.6): acá la dirección se ve sin editarse y la capacidad sólo baja. Se cambian
 * con un pedido a la moderación, en /solicitar-sala?sala={id}.
 */
final class SalasDelOrganizador extends Controlador
{
    private const PLANTILLA = 'alta_sala.html.twig';
    private const SUBIR_LA_CAPACIDAD = 'Para subir la capacidad, pedí el cambio a la moderación.';

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

        return $this->formulario($sala, [
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

        // La dirección y la localidad quedan las que tiene: lo que llegue en esos campos no cuenta.
        $campos = ['direccion' => $sala->datos->direccion, 'localidad' => $sala->datos->localidad] + $peticion->cuerpo();
        $formulario = FormularioDeSala::desdeCampos($campos, $peticion->archivo('imagen'));
        $datos = $formulario->datos();
        if ($datos === null) {
            return $this->formulario($sala, ['formulario' => $formulario], 422);
        }
        if ($datos->capacidad > $sala->datos->capacidad) {
            return $this->formulario($sala, [
                'formulario' => $formulario,
                'errores' => ['capacidad' => self::SUBIR_LA_CAPACIDAD],
            ], 422);
        }
        // Que la capacidad no quede por debajo del cupo de una función que todavía no se dio
        // (F-01.1): la sala tendría menos butacas que lugares ofrecidos.
        $minima = $this->servicio->capacidadMinima($sala);
        if ($datos->capacidad < $minima) {
            return $this->formulario($sala, [
                'formulario' => $formulario,
                'errores' => ['capacidad' => "Tenés una función con {$minima} lugares de cupo en esta sala: la capacidad no puede ser menor. Bajale el cupo primero."],
            ], 422);
        }
        $this->servicio->actualizar($sala, $datos, $formulario->imagen);

        return $this->redirigir("/organizador/salas/{$sala->id}/editar?guardada=1");
    }

    /**
     * @param array{formulario?: FormularioDeSala, errores?: array<string, string>, mensaje_exito?: ?string} $extra
     */
    private function formulario(Sala $sala, array $extra, int $estado = 200): Respuesta
    {
        $formulario = $extra['formulario'] ?? null;
        // Las mismas con las que valida FormularioDeSala, con sus mensajes: el navegador revisa lo
        // mismo y dice lo mismo (E4-09.1). Al editar la foto no es obligatoria: sin una nueva queda
        // la que estaba. La capacidad no pasa de la que tiene.
        $reglas = FormularioDeSala::reglas(imagenObligatoria: false);
        $reglas['capacidad']['valor_maximo'] = $sala->datos->capacidad;
        $reglas['capacidad']['mensajes']['valor_maximo'] = self::SUBIR_LA_CAPACIDAD;

        return $this->vista(self::PLANTILLA, [
            'es_edicion' => true,
            'accion' => "/organizador/salas/{$sala->id}/editar",
            'reglas' => $reglas,
            // EF-07.6: la dirección se ve sin editarse; la de una sala publicada se cambia con un
            // pedido a la moderación. La de una que espera su solicitud se corrige ahí.
            'direccion_bloqueada' => true,
            'pedir_cambio_de_direccion' => $sala->habilitada ? "/solicitar-sala?sala={$sala->id}" : null,
            'valores' => $formulario?->valores ?? [],
            'errores' => [...($formulario?->errores ?? []), ...($extra['errores'] ?? [])],
            'mensaje_exito' => $extra['mensaje_exito'] ?? null,
        ], $estado);
    }

    private static function organizador(Peticion $peticion): Usuario
    {
        // La ruta pide el rol de organizador: si llegó acá, hay sesión.
        return $peticion->usuario() ?? throw new LogicException('Ruta de organizador sin usuario.');
    }
}
