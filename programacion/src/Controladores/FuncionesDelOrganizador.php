<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Controladores;

use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Seguridad\Usuario;
use IndieCinema\Programacion\Modelo\FuncionDelOrganizador;
use IndieCinema\Programacion\Modelo\Obra;
use IndieCinema\Programacion\Modelo\Sala;
use IndieCinema\Programacion\Servicios\ServicioDeFuncionesDelOrganizador;
use LogicException;

/**
 * «Mis funciones» y alta para programar una función, en /organizador/funciones/….
 * Las rutas piden el rol de organizador y el núcleo controla el token CSRF en los POST.
 */
final class FuncionesDelOrganizador extends Controlador
{
    private const PLANTILLA_ALTA = 'alta_funcion.html.twig';

    public function __construct(private readonly ServicioDeFuncionesDelOrganizador $servicio)
    {
    }

    public function listar(Peticion $peticion): Respuesta
    {
        $funciones = $this->servicio->listar(self::organizador($peticion));

        return $this->vista('mis_funciones.html.twig', [
            'funciones' => array_map(self::datos(...), $funciones),
            'programada' => $peticion->consulta('programada'),
        ]);
    }

    public function nueva(Peticion $peticion): Respuesta
    {
        $organizador = self::organizador($peticion);
        $salas = $this->servicio->salasHabilitadas($organizador);
        $obras = $this->servicio->catalogoDeObras();

        return $this->formularioAlta($salas, $obras, []);
    }

    public function crear(Peticion $peticion): Respuesta
    {
        $organizador = self::organizador($peticion);
        $salas = $this->servicio->salasHabilitadas($organizador);
        $obras = $this->servicio->catalogoDeObras();

        $formulario = FormularioDeFuncion::desdeCampos($peticion->cuerpo(), $salas, $obras);
        if (!$formulario->esValido() || $formulario->salaId === null || $formulario->fechaHora === null || $formulario->cupo === null || $formulario->precio === null) {
            return $this->formularioAlta($salas, $obras, [
                'formulario' => $formulario,
                'error_general' => 'Revisá los campos marcados.',
            ], 422);
        }

        $funcion = $this->servicio->programar(
            $organizador,
            $formulario->salaId,
            $formulario->obrasIds,
            $formulario->cupo,
            $formulario->precio,
            $formulario->fechaHora,
        );

        // 303 al listado: al recargar no vuelve a crear la función.
        return $this->redirigir("/organizador/funciones?programada={$funcion->id}");
    }

    /**
     * @param list<Sala>                                                          $salas
     * @param list<Obra>                                                          $obras
     * @param array{formulario?: FormularioDeFuncion, error_general?: ?string} $extra
     */
    private function formularioAlta(array $salas, array $obras, array $extra = [], int $estado = 200): Respuesta
    {
        $formulario = $extra['formulario'] ?? null;

        return $this->vista(self::PLANTILLA_ALTA, [
            'accion' => '/organizador/funciones/nueva',
            'salas' => array_map(TarjetaDeSala::datos(...), $salas),
            'obras' => array_map(self::datosObra(...), $obras),
            'valores' => $formulario?->valores ?? [],
            'errores' => $formulario?->errores ?? [],
            'error_general' => $extra['error_general'] ?? null,
            'mensaje_exito' => null,
        ], $estado);
    }

    /**
     * @return array{id: string, titulo: string, anio: int, duracion: int, director: string, afiche: ?string}
     */
    private static function datosObra(Obra $obra): array
    {
        return [
            'id' => $obra->id,
            'titulo' => $obra->titulo,
            'anio' => $obra->anio,
            'duracion' => $obra->duracionMinutos,
            'director' => $obra->director,
            'afiche' => $obra->afiche,
        ];
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
