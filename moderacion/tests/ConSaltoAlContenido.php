<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Pruebas;

/**
 * «Saltar al contenido» (E4-10.1), para las pruebas que piden una página entera: es lo primero que
 * toma el foco con Tab, antes del encabezado o de la barra del panel, y lleva al contenido
 * principal, que acepta el foco desde un enlace (tabindex -1) para que el foco baje de verdad y no
 * sólo el desplazamiento.
 */
trait ConSaltoAlContenido
{
    private static function assertSaltaAlContenido(string $cuerpo): void
    {
        $desdeElCuerpo = (string) strstr($cuerpo, '<body');
        // Lo primero que toma el foco: un enlace, un control de formulario o el resumen de un desplegable.
        preg_match('#<(?:a|button|input|select|textarea|summary)\b[^>]*>[^<]*#', $desdeElCuerpo, $primero);

        self::assertMatchesRegularExpression('#^<a href="\#contenido-principal"[^>]*>Saltar al contenido$#', $primero[0] ?? '');
        self::assertMatchesRegularExpression('#<main id="contenido-principal"[^>]* tabindex="-1"[^>]*>#', $cuerpo);
    }
}
