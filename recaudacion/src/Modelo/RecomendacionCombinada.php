<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Modelo;

/**
 * Una recomendación que cruza varias variables (EF-22.3): qué hacer en una sala y los datos que la
 * justifican, para poder contestar «¿cómo llegaste a eso?». Las reglas están escritas en
 * docs/recomendaciones.md.
 */
final readonly class RecomendacionCombinada
{
    /** El día flojo, con lo que el público pidió y a la gorra, porque en esa sala ya funcionó. */
    public const PEDIDO_A_LA_GORRA = 'pedido_a_la_gorra';

    /** El día flojo, en la franja horaria que mejor llena en esa sala. */
    public const OTRO_HORARIO = 'otro_horario';

    /** Lo que se agota y gusta, también el día flojo. */
    public const AGOTADA_EL_DIA_FLOJO = 'agotada_el_dia_flojo';

    /**
     * @param string       $regla  una de las constantes
     * @param string       $accion qué hacer, en una oración
     * @param list<string> $porque cada dato que la justifica, en una oración
     */
    public function __construct(
        public string $regla,
        public string $salaId,
        public string $salaNombre,
        public string $accion,
        public array $porque,
        public ?FuncionParaProgramar $programar = null,
    ) {
    }
}
