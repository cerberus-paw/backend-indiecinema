<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Controladores;

use IndieCinema\Programacion\Modelo\DatosDePedido;
use IndieCinema\Programacion\Modelo\Obra;

/**
 * Valida en el servidor lo que manda el formulario para pedir un título ausente, y exporta las
 * mismas reglas al navegador para la validación en línea (EF-11.1 / IC-576).
 */
final class FormularioDePedido
{
    /** Campo => [etiqueta para los mensajes, largo máximo]. */
    private const TEXTOS = [
        'titulo' => ['el título', 150],
        'director' => ['la dirección', 100],
    ];

    /** @var array<string, mixed> */
    public private(set) array $valores = [];

    /** @var array<string, string> */
    public private(set) array $errores = [];

    private ?DatosDePedido $datos = null;

    /**
     * @param array<string, mixed> $campos el cuerpo del POST
     */
    public static function desdeCampos(array $campos): self
    {
        $formulario = new self();
        $formulario->validar($campos);
        if ($formulario->errores !== []) {
            $formulario->datos = null;
        }

        return $formulario;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function reglas(): array
    {
        $anioMensaje = 'El año tiene que estar entre ' . Obra::PRIMER_ANIO . ' y el actual.';
        $reglas = [];
        foreach (self::TEXTOS as $campo => [$etiqueta, $maximo]) {
            $reglas[$campo] = [
                'requerido' => true,
                'maximo' => $maximo,
                'mensajes' => [
                    'requerido' => self::faltaTexto($etiqueta),
                    'maximo' => self::textoDemasiadoLargo($etiqueta, $maximo),
                ],
            ];
        }

        $reglas['anio'] = [
            'requerido' => false,
            'valor_minimo' => Obra::PRIMER_ANIO,
            'valor_maximo' => (int) date('Y'),
            'mensajes' => [
                'valor_minimo' => $anioMensaje,
                'valor_maximo' => $anioMensaje,
                'tipo' => $anioMensaje,
            ],
        ];

        return $reglas;
    }

    public function datos(): ?DatosDePedido
    {
        return $this->datos;
    }

    /**
     * @param array<string, mixed> $campos
     */
    private function validar(array $campos): void
    {
        $textos = [];
        foreach (self::TEXTOS as $campo => [$etiqueta, $maximo]) {
            $valor = self::texto($campos[$campo] ?? null);
            $this->valores[$campo] = $valor;
            if ($valor === '') {
                $this->errores[$campo] = self::faltaTexto($etiqueta);
            } elseif (mb_strlen($valor) > $maximo) {
                $this->errores[$campo] = self::textoDemasiadoLargo($etiqueta, $maximo);
            }
            $textos[$campo] = $valor;
        }

        $anioValor = self::texto($campos['anio'] ?? null);
        $this->valores['anio'] = $anioValor;
        $anio = null;
        if ($anioValor !== '') {
            $validado = filter_var($anioValor, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => Obra::PRIMER_ANIO, 'max_range' => (int) date('Y')],
            ]);
            if ($validado === false) {
                $this->errores['anio'] = 'El año tiene que estar entre ' . Obra::PRIMER_ANIO . ' y el actual.';
            } else {
                $anio = (int) $validado;
            }
        }

        if ($this->errores === []) {
            $this->datos = new DatosDePedido($textos['titulo'], $anio, $textos['director']);
        }
    }

    private static function faltaTexto(string $etiqueta): string
    {
        return 'Falta ' . $etiqueta . '.';
    }

    private static function textoDemasiadoLargo(string $etiqueta, int $maximo): string
    {
        return ucfirst($etiqueta) . " puede tener hasta {$maximo} caracteres.";
    }

    private static function texto(mixed $valor): string
    {
        if (!is_string($valor) || !mb_check_encoding($valor, 'UTF-8')) {
            return '';
        }
        $compacto = (string) preg_replace('/\s+/u', ' ', $valor);

        return trim((string) preg_replace('/\p{Cc}/u', '', $compacto));
    }
}
