<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Servicios;

use DateTimeImmutable;
use LogicException;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Seguridad\Usuario;
use IndieCinema\Programacion\Modelo\EstadoFuncion;
use IndieCinema\Programacion\Modelo\Funcion;
use IndieCinema\Programacion\Modelo\FuncionDelOrganizador;
use IndieCinema\Programacion\Modelo\Obra;
use IndieCinema\Programacion\Modelo\ReglaIncumplida;
use IndieCinema\Programacion\Modelo\Sala;
use IndieCinema\Programacion\Repositorios\RepositorioDeFunciones;
use IndieCinema\Programacion\Repositorios\RepositorioDeObras;
use IndieCinema\Programacion\Repositorios\RepositorioDeSalas;
use IndieCinema\Programacion\Repositorios\RepositorioDeVotos;

/**
 * El panel de funciones del organizador: lo que ve de las funciones de sus salas y el alta
 * (E3-11.2), la edición y la cancelación.
 *
 * No es ServicioDeFunciones, que responde la API interna a los otros subsistemas: acá las reglas
 * son las del organizador y quién es el dueño.
 */
final class ServicioDeFuncionesDelOrganizador
{
    public function __construct(
        private readonly RepositorioDeFunciones $funciones,
        private readonly RepositorioDeSalas $salas,
        private readonly RepositorioDeObras $obras,
        private readonly RepositorioDeVotos $votos,
        private readonly ?AcuerdosDeFunciones $acuerdos = null,
    ) {
    }

    /**
     * Las funciones de las salas del organizador. Que sean sólo las suyas no lo decide la ruta,
     * que únicamente controla el rol: lo decide la consulta, con su id.
     *
     * @return list<FuncionDelOrganizador>
     */
    public function listar(Usuario $organizador): array
    {
        return $this->funciones->delOrganizador($organizador->id);
    }

    /**
     * Las salas del organizador que están habilitadas para programar funciones.
     *
     * @return list<Sala>
     */
    public function salasHabilitadas(Usuario $organizador): array
    {
        return array_values(array_filter(
            $this->salas->delOrganizador($organizador->id),
            static fn (Sala $sala): bool => $sala->habilitada,
        ));
    }

    /**
     * El catálogo de obras disponibles para proyectar.
     *
     * @return list<Obra>
     */
    public function catalogoDeObras(): array
    {
        return $this->obras->todas();
    }

    /**
     * Da de alta y programa una nueva función en una sala propia habilitada (E3-11.2), con los
     * parámetros de la sala: cuántas obras, cuánto duran y que no se pise con otra función.
     *
     * @param list<string> $obrasIds
     *
     * @throws ReglaIncumplida si la sala no es suya, si una obra no está en el catálogo o si la
     *                         sala no admite la función
     */
    public function programar(
        Usuario $organizador,
        string $salaId,
        array $obrasIds,
        int $cupo,
        float $precioBase,
        DateTimeImmutable $fechaHora,
    ): Funcion {
        $sala = $this->salas->buscar($salaId);
        if ($sala === null || !$sala->esDe($organizador->id)) {
            throw new ReglaIncumplida('La sala no existe o no pertenece a tu cuenta.');
        }

        $duraciones = array_column($this->obras->todas(), 'duracionMinutos', 'id');
        $duracion = 0;
        foreach ($obrasIds as $obraId) {
            $duracion += $duraciones[$obraId] ?? throw new ReglaIncumplida('Una de las obras no está en el catálogo.');
        }

        $funcion = $sala->programarFuncion($obrasIds, $cupo, $precioBase, $fechaHora, $duracion, $this->funciones->ocupadoEnLaSala($sala->id));
        $this->funciones->agregar($funcion);

        return $funcion;
    }

    /**
     * Da de alta una nueva función en votación de títulos (E3-12.1), sin fecha todavía.
     *
     * @param list<string> $obrasIds
     *
     * @throws ReglaIncumplida si la sala no es suya o una obra no está en el catálogo
     */
    public function crearEnVotacionDeTitulos(
        Usuario $organizador,
        string $salaId,
        array $obrasIds,
        int $cupo,
        float $precioBase,
    ): Funcion {
        $sala = $this->salas->buscar($salaId);
        if ($sala === null || !$sala->esDe($organizador->id)) {
            throw new ReglaIncumplida('La sala no existe o no pertenece a tu cuenta.');
        }

        $idsCatalogo = array_column($this->obras->todas(), 'id');
        if (array_diff($obrasIds, $idsCatalogo) !== []) {
            throw new ReglaIncumplida('Una de las obras no está en el catálogo.');
        }

        $funcion = $sala->crearFuncion($obrasIds, $cupo, $precioBase);
        $this->funciones->agregar($funcion);

        return $funcion;
    }

    /**
     * La función para editarla, si es de una sala del organizador y se puede editar
     * (Funcion::seEdita(): está programada y todavía no se dio). Si no, la respuesta es 404, como
     * en propia(): el panel y la ficha no le muestran el botón, así que la única forma de llegar
     * es escribiendo la dirección, y no tiene sentido abrir un formulario que no va a poder
     * guardar (E3-11.3).
     *
     * @throws ExcepcionHttp 404
     */
    public function paraEditar(Usuario $organizador, string $id): Funcion
    {
        $funcion = $this->propia($organizador, $id);
        if (!Funcion::seEdita($funcion->estado, $funcion->fechaHora)) {
            throw ExcepcionHttp::noEncontrada();
        }

        return $funcion;
    }

    /**
     * Actualiza una función propia del organizador (E3-11.3).
     *
     * @param list<string> $obrasIds
     *
     * @throws ExcepcionHttp 404 si la función no existe o la sala no es del organizador
     * @throws ReglaIncumplida si los datos no cumplen las reglas de la sala o de la función
     */
    public function actualizar(
        Usuario $organizador,
        string $funcionId,
        string $nuevaSalaId,
        array $obrasIds,
        int $cupo,
        float $precioBase,
        DateTimeImmutable $fechaHora,
    ): Funcion {
        $funcion = $this->paraEditar($organizador, $funcionId);

        $sala = $this->salas->buscar($nuevaSalaId);
        if ($sala === null || !$sala->esDe($organizador->id)) {
            throw new ReglaIncumplida('La sala no existe o no pertenece a tu cuenta.');
        }

        $duraciones = array_column($this->obras->todas(), 'duracionMinutos', 'id');
        $duracion = 0;
        foreach ($obrasIds as $obraId) {
            $duracion += $duraciones[$obraId] ?? throw new ReglaIncumplida('Una de las obras no está en el catálogo.');
        }

        $ocupado = $this->funciones->ocupadoEnLaSala($sala->id, $funcion->id);
        $sala->actualizarFuncion($funcion, $obrasIds, $cupo, $precioBase, $fechaHora, $duracion, $ocupado);
        $this->funciones->actualizar($funcion);

        return $funcion;
    }

    /**
     * La función para la pantalla que confirma la cancelación, como la muestra el panel (E3-11.4).
     * Como en paraEditar(), es 404 si no es del organizador o no se puede cancelar
     * (Funcion::seCancela()).
     *
     * @throws ExcepcionHttp 404
     */
    public function paraCancelar(Usuario $organizador, string $id): FuncionDelOrganizador
    {
        $funcion = $this->funciones->delOrganizadorPorId($organizador->id, $id);
        if ($funcion === null || !Funcion::seCancela($funcion->estado, $funcion->fechaHora)) {
            throw ExcepcionHttp::noEncontrada();
        }

        return $funcion;
    }

    /**
     * Cancela una función propia del organizador: es una baja lógica, la función queda en su panel
     * pero sale de la cartelera (E3-11.4).
     *
     * @throws ExcepcionHttp 404 si no es del organizador o no se puede cancelar
     */
    public function cancelar(Usuario $organizador, string $id): void
    {
        $funcion = $this->propia($organizador, $id);
        if (!Funcion::seCancela($funcion->estado, $funcion->fechaHora)) {
            throw ExcepcionHttp::noEncontrada();
        }

        $funcion->cancelar();
        $this->funciones->guardar($funcion);
    }

    /**
     * Cierra la votación de títulos de una función propia con la obra más votada (E3-12.4).
     * La función pasa a estado «en acuerdo de fecha» y conserva únicamente la obra ganadora.
     *
     * @throws ExcepcionHttp 404 si la función no existe, es de otro organizador o no está en votación de títulos
     */
    public function cerrarVotacion(Usuario $organizador, string $id): Funcion
    {
        $funcion = $this->propia($organizador, $id);
        if ($funcion->estado !== EstadoFuncion::EnVotacionTitulos) {
            throw ExcepcionHttp::noEncontrada();
        }

        $obraGanadora = $this->votos->obraMasVotada($funcion->id, $funcion->obrasIds);
        $funcion->cerrarVotacionTitulos([$obraGanadora]);
        $this->funciones->guardar($funcion);

        return $funcion;
    }

    /**
     * El organizador propone las franjas horarias y el mínimo de asistentes para el acuerdo de fecha (E3-13.2 / IC-154).
     *
     * @param list<array{fecha_hora_inicio: string, fecha_hora_fin?: string|null}> $franjas
     *
     * @return array<string, mixed> los datos del acuerdo creado por funciones
     *
     * @throws ExcepcionHttp 404 si la función no es del organizador o no está en acuerdo de fecha
     */
    public function proponerAcuerdoFecha(Usuario $organizador, string $id, int $minimoAsistentes, array $franjas): array
    {
        $funcion = $this->propia($organizador, $id);
        if ($funcion->estado !== EstadoFuncion::EnAcuerdoFecha) {
            throw ExcepcionHttp::noEncontrada();
        }

        if ($this->acuerdos === null) {
            throw new LogicException('No se configuró el cliente de acuerdos de funciones.');
        }

        return $this->acuerdos->proponer($funcion->id, $minimoAsistentes, $franjas);
    }

    /**
     * Confirma la franja ganadora del acuerdo de fecha si alcanzó el mínimo y programa la función (E3-13.4 / IC-156).
     *
     * @return Funcion
     *
     * @throws ExcepcionHttp 404 si la función no es del organizador o no está en acuerdo de fecha
     * @throws ReglaIncumplida si el acuerdo no alcanzó el mínimo o la fecha no es válida
     */
    public function confirmarAcuerdo(Usuario $organizador, string $id): Funcion
    {
        $funcion = $this->propia($organizador, $id);
        if ($funcion->estado !== EstadoFuncion::EnAcuerdoFecha) {
            throw ExcepcionHttp::noEncontrada();
        }

        if ($this->acuerdos === null) {
            throw new LogicException('No se configuró el cliente de acuerdos de funciones.');
        }

        $acuerdo = $this->acuerdos->obtenerPorFuncion($funcion->id);
        if ($acuerdo === null) {
            throw new ReglaIncumplida('No hay acuerdo de fecha activo para esta función.');
        }

        $resultado = $this->acuerdos->confirmar((string) $acuerdo['id']);

        $inicioStr = null;
        if (!empty($resultado['franja_ganadora']['inicio'])) {
            $inicioStr = (string) $resultado['franja_ganadora']['inicio'];
        } else {
            $franjaId = $resultado['franja_ganadora_id'] ?? null;
            $franjas = $resultado['franjas'] ?? $acuerdo['franjas'] ?? [];
            foreach ($franjas as $f) {
                if (($f['id'] ?? null) === $franjaId) {
                    $inicioStr = (string) ($f['inicio'] ?? '');
                    break;
                }
            }
        }

        if ($inicioStr === null || $inicioStr === '') {
            throw new ReglaIncumplida('No se pudo determinar la fecha y hora de la franja ganadora.');
        }

        $fechaHora = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $inicioStr) ?: new DateTimeImmutable($inicioStr);
        $funcion->fijarFechaHora($fechaHora);
        $this->funciones->guardar($funcion);

        return $funcion;
    }

    /**
     * La función, si es de una sala del organizador. Si no existe o es de otro, la respuesta es
     * 404: así un organizador no puede averiguar qué ids existen.
     *
     * @throws ExcepcionHttp 404
     */
    private function propia(Usuario $organizador, string $id): Funcion
    {
        $funcion = $this->funciones->buscar($id) ?? throw ExcepcionHttp::noEncontrada();
        $sala = $this->salas->buscar($funcion->salaId);
        if ($sala === null || !$sala->esDe($organizador->id)) {
            throw ExcepcionHttp::noEncontrada();
        }

        return $funcion;
    }
}
