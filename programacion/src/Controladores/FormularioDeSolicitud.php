<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Controladores;

use IndieCinema\Programacion\Modelo\DatosDeSala;

/**
 * El formulario de la solicitud de sala: los datos de la sala, con las mismas reglas que el alta
 * (FormularioDeSala), y las dos declaraciones de «Términos y condiciones» del wireframe, sin las
 * que no se manda.
 */
final class FormularioDeSolicitud
{
    /** Casilla => qué falta si no está tildada. */
    private const DECLARACIONES = [
        'habilitacion' => 'Tenés que declarar que el espacio está habilitado.',
        'derechos' => 'Tenés que comprometerte a tener los derechos de exhibición.',
    ];

    /** @var array<string, string|bool> lo que mandó, para volver a mostrarlo; las casillas, como verdadero o falso */
    public private(set) array $valores = [];

    /** @var array<string, string> campo => qué está mal */
    public private(set) array $errores = [];

    private function __construct(public readonly FormularioDeSala $sala)
    {
        $this->valores = $sala->valores;
        $this->errores = $sala->errores;
    }

    /**
     * @param array<string, mixed>      $campos            el cuerpo del POST
     * @param array<string, mixed>|null $imagen            el archivo del campo imagen, como lo arma PHP
     * @param bool                      $imagenObligatoria para una sala nueva; al corregir una
     *                                                     rechazada, sin foto queda la que tenía
     */
    public static function desdeCampos(array $campos, ?array $imagen, bool $imagenObligatoria): self
    {
        $formulario = new self(FormularioDeSala::desdeCampos($campos, $imagen, $imagenObligatoria));
        foreach (self::DECLARACIONES as $casilla => $mensaje) {
            $tildada = ($campos[$casilla] ?? null) === '1';
            $formulario->valores[$casilla] = $tildada;
            if (!$tildada) {
                $formulario->errores[$casilla] = $mensaje;
            }
        }

        return $formulario;
    }

    /**
     * Con los datos de la sala que le rechazaron, para corregirla. Las declaraciones se vuelven a
     * tildar.
     */
    public static function desdeDatos(DatosDeSala $datos): self
    {
        return new self(FormularioDeSala::desdeDatos($datos));
    }

    /**
     * Los datos de la sala, o null si hay algún error, también en las declaraciones.
     */
    public function datos(): ?DatosDeSala
    {
        return $this->errores === [] ? $this->sala->datos() : null;
    }

    /**
     * Las del alta de la sala y las declaraciones, obligatorias.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function reglas(bool $imagenObligatoria): array
    {
        return FormularioDeSala::reglas($imagenObligatoria) + array_fill_keys(array_keys(self::DECLARACIONES), ['requerido' => true]);
    }
}
