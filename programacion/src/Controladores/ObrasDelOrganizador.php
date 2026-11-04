<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Controladores;

use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Seguridad\Usuario;
use IndieCinema\Programacion\Modelo\Genero;
use IndieCinema\Programacion\Modelo\Obra;
use IndieCinema\Programacion\Modelo\OrigenCopia;
use IndieCinema\Programacion\Modelo\ReglaIncumplida;
use IndieCinema\Programacion\Modelo\TipoObra;
use IndieCinema\Programacion\Servicios\ServicioDeObras;
use LogicException;

/**
 * «Mis obras», alta, edición y baja lógica de obras del catálogo en /organizador/obras/….
 * Las rutas piden el rol de organizador y el núcleo controla el token CSRF en los POST.
 */
final class ObrasDelOrganizador extends Controlador
{
    private const PLANTILLA_ALTA = 'alta_obra.html.twig';
    private const PLANTILLA_LISTADO = 'mis_obras.html.twig';

    public function __construct(private readonly ServicioDeObras $servicio)
    {
    }

    public function listar(Peticion $peticion): Respuesta
    {
        $organizador = self::organizador($peticion);
        $obras = $this->servicio->delOrganizador($organizador);
        $mapaGeneros = $this->servicio->mapaGeneros();

        $datosObras = array_map(
            fn (Obra $obra): array => [
                ...TarjetaDeObra::datos($obra, $mapaGeneros),
                'url_editar' => "/organizador/obras/{$obra->id}/editar",
                'url_baja' => "/organizador/obras/{$obra->id}/baja",
                'tiene_funciones_futuras' => $this->servicio->tieneFuncionesFuturas($obra->id),
            ],
            $obras,
        );

        return $this->vista(self::PLANTILLA_LISTADO, [
            'obras' => $datosObras,
            'creada' => $peticion->consulta('creada') === '1',
            'guardada' => $peticion->consulta('guardada') === '1',
            'dada_de_baja' => $peticion->consulta('dada_de_baja') === '1',
            'error_baja' => $peticion->consulta('error_baja'),
        ]);
    }

    public function nueva(Peticion $peticion): Respuesta
    {
        self::organizador($peticion);

        return $this->formulario([], esEdicion: false);
    }

    public function crear(Peticion $peticion): Respuesta
    {
        $organizador = self::organizador($peticion);

        $formulario = FormularioDeObra::desdeCampos(
            $peticion->cuerpo(),
            $peticion->archivo('afiche'),
            $this->servicio->generos(),
            aficheObligatorio: true,
        );
        $datos = $formulario->datos();
        if ($datos === null) {
            return $this->formulario(['formulario' => $formulario], esEdicion: false, estado: 422);
        }

        try {
            $this->servicio->crear($organizador, $datos, $formulario->imagen);
        } catch (ReglaIncumplida $regla) {
            return $this->formulario(['formulario' => $formulario, 'error_general' => $regla->getMessage()], esEdicion: false, estado: 422);
        }

        return $this->redirigir('/organizador/obras?creada=1');
    }

    public function editar(Peticion $peticion): Respuesta
    {
        $organizador = self::organizador($peticion);
        $id = (string) $peticion->parametro('id');
        $obra = $this->servicio->paraEditar($id, $organizador);

        return $this->formulario([
            'formulario' => FormularioDeObra::desdeDatos($obra->datos()),
            'mensaje_exito' => $peticion->consulta('guardada') === '1' ? 'Los cambios ya se ven en el catálogo.' : null,
        ], esEdicion: true, obra: $obra);
    }

    public function actualizar(Peticion $peticion): Respuesta
    {
        $organizador = self::organizador($peticion);
        $id = (string) $peticion->parametro('id');
        $obra = $this->servicio->paraEditar($id, $organizador);

        $formulario = FormularioDeObra::desdeCampos(
            $peticion->cuerpo(),
            $peticion->archivo('afiche'),
            $this->servicio->generos(),
            aficheObligatorio: false,
        );
        $datos = $formulario->datos();
        if ($datos === null) {
            return $this->formulario(['formulario' => $formulario], esEdicion: true, obra: $obra, estado: 422);
        }

        try {
            $this->servicio->actualizar($obra, $datos, $formulario->imagen);
        } catch (ReglaIncumplida $regla) {
            return $this->formulario(['formulario' => $formulario, 'error_general' => $regla->getMessage()], esEdicion: true, obra: $obra, estado: 422);
        }

        return $this->redirigir("/organizador/obras/{$obra->id}/editar?guardada=1");
    }

    public function darDeBaja(Peticion $peticion): Respuesta
    {
        $organizador = self::organizador($peticion);
        $id = (string) $peticion->parametro('id');
        $json = $peticion->cabecera('Accept') === 'application/json';

        try {
            $this->servicio->darDeBaja($id, $organizador);
        } catch (ReglaIncumplida $regla) {
            if ($json) {
                return $this->json(['error' => $regla->getMessage()], 422);
            }

            return $this->redirigir('/organizador/obras?error_baja=' . rawurlencode($regla->getMessage()));
        }

        if ($json) {
            return $this->json(['ok' => true]);
        }

        return $this->redirigir('/organizador/obras?dada_de_baja=1');
    }

    /**
     * @param array{formulario?: FormularioDeObra, mensaje_exito?: ?string, error_general?: ?string} $extra
     */
    private function formulario(
        array $extra,
        bool $esEdicion = false,
        ?Obra $obra = null,
        int $estado = 200,
    ): Respuesta {
        $formulario = $extra['formulario'] ?? null;
        $mensajeExito = $extra['mensaje_exito'] ?? null;
        $errorGeneral = $extra['error_general'] ?? ($formulario?->errores ? 'Revisá los campos marcados.' : null);

        return $this->vista(self::PLANTILLA_ALTA, [
            'es_edicion' => $esEdicion,
            'accion' => $esEdicion && $obra !== null ? "/organizador/obras/{$obra->id}/editar" : '/organizador/obras/nueva',
            'valores' => $formulario?->valores ?? [],
            'errores' => $formulario?->errores ?? [],
            'error_general' => $errorGeneral,
            'mensaje_exito' => $mensajeExito,
            'reglas' => FormularioDeObra::reglas(!$esEdicion),
            'generos' => array_map(static fn (Genero $g): array => ['id' => $g->id, 'nombre' => $g->nombre], $this->servicio->generos()),
            'tipos_obra' => TipoObra::opciones(),
            'origenes_copia' => OrigenCopia::opciones(),
            'afiche_actual' => $esEdicion && $obra?->afiche !== null ? "/obras/{$obra->id}/afiche" : null,
        ], $estado);
    }

    private static function organizador(Peticion $peticion): Usuario
    {
        return $peticion->usuario() ?? throw new LogicException('Ruta de organizador sin usuario.');
    }
}
