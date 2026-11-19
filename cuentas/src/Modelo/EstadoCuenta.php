<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Modelo;

/**
 * El estado de la cuenta (E2, modelo de clases de cuentas), con los valores de la columna
 * usuario.estado.
 */
enum EstadoCuenta: string
{
    /** Registrada, esperando que verifique el correo. */
    case Pendiente = 'pendiente';
    case Activa = 'activa';
    /** La suspende el administrador desde «Usuarios y roles» (EF-14.3): no ingresa. */
    case Suspendida = 'suspendida';
}
