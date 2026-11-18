<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Modelo;

/**
 * Para qué se emitió un token de un solo uso (X-02).
 */
enum PropositoDeToken: string
{
    case Verificar = 'verificar';
    case Recuperar = 'recuperar';
}
