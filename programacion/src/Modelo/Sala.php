<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Modelo;

use DateTimeImmutable;
use IndieCinema\Nucleo\Uuid;

/**
 * Una sala indie y el organizador que la administra.
 *
 * Hasta que exista moderación (E4), la publica el administrador poniendo habilitada en verdadero;
 * el organizador no la puede habilitar desde el formulario.
 */
final class Sala
{
    public function __construct(
        public readonly string $id,
        public readonly string $organizadorId,
        public private(set) DatosDeSala $datos,
        /** El nombre del archivo en el volumen, o null si no tiene. */
        public private(set) ?string $imagen = null,
        public private(set) bool $habilitada = false,
    ) {
    }

    public static function nueva(string $organizadorId, DatosDeSala $datos, string $imagen): self
    {
        return new self(Uuid::nuevo(), $organizadorId, $datos, $imagen);
    }

    public function esDe(string $usuarioId): bool
    {
        return hash_equals($this->organizadorId, $usuarioId);
    }

    /**
     * Si no está habilitada, sólo la ve su organizador: todavía no es pública.
     */
    public function laPuedeVer(?string $usuarioId): bool
    {
        return $this->habilitada || ($usuarioId !== null && $this->esDe($usuarioId));
    }

    public function cambiarDatos(DatosDeSala $datos): void
    {
        $this->datos = $datos;
    }

    public function cambiarImagen(string $imagen): void
    {
        $this->imagen = $imagen;
    }

    /**
     * La publica o la retira del sitio. Lo decide el administrador, no el organizador.
     */
    public function cambiarHabilitacion(bool $habilitada): void
    {
        $this->habilitada = $habilitada;
    }

    /**
     * Una función nueva en esta sala, en votación entre las obras candidatas.
     *
     * @param list<string> $obrasIds
     *
     * @throws ReglaIncumplida si la sala no está habilitada, si el cupo no entra en ella o si
     *                         Funcion::nueva() rechaza los datos
     */
    public function crearFuncion(array $obrasIds, int $cupo, float $precioBase): Funcion
    {
        if (!$this->habilitada) {
            throw new ReglaIncumplida('La sala todavía no está habilitada para programar funciones.');
        }
        if ($cupo > $this->datos->capacidad) {
            throw new ReglaIncumplida("El cupo no puede ser mayor a la capacidad de la sala ({$this->datos->capacidad}).");
        }

        return Funcion::nueva($this->id, $obrasIds, $cupo, $precioBase);
    }

    /**
     * Una función ya programada en esta sala, con sus obras, fecha y hora confirmadas (E3-11.2).
     * Hereda los parámetros de la sala: cuántas películas entran en una función, cuánto puede durar
     * y cuánto tiempo hay que dejar entre una función y la siguiente.
     *
     * @param list<string>                                         $obrasIds
     * @param int                                                  $duracion lo que duran las obras, en minutos
     * @param list<array{DateTimeImmutable, DateTimeImmutable}>    $ocupado  desde y hasta cuándo va cada
     *                                                                       función ya programada en la sala
     *
     * @throws ReglaIncumplida si la sala no está habilitada, si el cupo no entra en ella, si las
     *                         obras son más o duran más de lo que la sala admite, si se pisa con
     *                         otra función, o si Funcion::programada() rechaza los datos
     */
    public function programarFuncion(
        array $obrasIds,
        int $cupo,
        float $precioBase,
        DateTimeImmutable $fechaHora,
        int $duracion,
        array $ocupado = [],
        DateTimeImmutable $ahora = new DateTimeImmutable(),
    ): Funcion {
        $this->controlarReglasDeFuncion($obrasIds, $cupo, $fechaHora, $duracion, $ocupado);

        return Funcion::programada($this->id, $obrasIds, $cupo, $precioBase, $fechaHora, $ahora);
    }

    /**
     * Aplica los cambios de edición a una función en esta sala (E3-11.3).
     *
     * @param list<string>                                      $obrasIds
     * @param int                                               $duracion lo que duran las obras, en minutos
     * @param list<array{DateTimeImmutable, DateTimeImmutable}> $ocupado  ocupación de la sala (excluyendo esta función)
     *
     * @throws ReglaIncumplida si la sala no admite los nuevos datos o si se pisa con otra función
     */
    public function actualizarFuncion(
        Funcion $funcion,
        array $obrasIds,
        int $cupo,
        float $precioBase,
        DateTimeImmutable $fechaHora,
        int $duracion,
        array $ocupado = [],
        DateTimeImmutable $ahora = new DateTimeImmutable(),
    ): void {
        $this->controlarReglasDeFuncion($obrasIds, $cupo, $fechaHora, $duracion, $ocupado);
        $funcion->actualizar($this->id, $obrasIds, $cupo, $precioBase, $fechaHora, $ahora);
    }

    /**
     * @param list<string>                                      $obrasIds
     * @param list<array{DateTimeImmutable, DateTimeImmutable}> $ocupado
     */
    private function controlarReglasDeFuncion(
        array $obrasIds,
        int $cupo,
        DateTimeImmutable $fechaHora,
        int $duracion,
        array $ocupado,
    ): void {
        if (!$this->habilitada) {
            throw new ReglaIncumplida('La sala todavía no está habilitada para programar funciones.');
        }
        if ($cupo > $this->datos->capacidad) {
            throw new ReglaIncumplida("El cupo no puede ser mayor a la capacidad de la sala ({$this->datos->capacidad}).");
        }
        if (count($obrasIds) > $this->datos->peliculasPorFuncion) {
            throw new ReglaIncumplida("En esta sala entran hasta {$this->datos->peliculasPorFuncion} películas por función.");
        }
        if ($duracion > $this->datos->duracionFuncion) {
            throw new ReglaIncumplida("Las obras duran {$duracion} minutos y en esta sala una función puede durar hasta {$this->datos->duracionFuncion}.");
        }
        // Entre una función y la siguiente tiene que quedar el tiempo que pide la sala, de los dos
        // lados: ni empezar pegada a la anterior ni terminar pegada a la que sigue.
        $pausa = "{$this->datos->tiempoEntreFunciones} minutes";
        $termina = $fechaHora->modify("+{$duracion} minutes");
        foreach ($ocupado as [$desde, $hasta]) {
            if ($fechaHora < $hasta->modify("+{$pausa}") && $termina->modify("+{$pausa}") > $desde) {
                throw new ReglaIncumplida(
                    "Se pisa con otra función de la sala, que va de {$desde->format('H:i')} a {$hasta->format('H:i')}: "
                    . "entre una y otra tienen que quedar {$this->datos->tiempoEntreFunciones} minutos.",
                );
            }
        }
    }
}
