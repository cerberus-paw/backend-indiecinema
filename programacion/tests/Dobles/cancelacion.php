<?php

/*
 * Funciones de mentira para la cancelación de una función (E4-03.4): atiende con `php -S`
 * POST /interno/funciones/{id}/cancelar-reservas y anota lo que le mandó programación en el
 * archivo que nombra la variable de entorno CANCELACIONES (una línea JSON por pedido), para que
 * CancelacionConReservasTest lo lea. Como la de verdad, sólo atiende a programación con su token.
 */

declare(strict_types=1);

header('Content-Type: application/json');
$cabeceras = getallheaders();
if (($cabeceras['X-Llamador'] ?? null) !== 'programacion' || ($cabeceras['Authorization'] ?? null) !== 'Bearer token-de-programacion') {
    http_response_code(403);
    echo '{"error":"Llamador no autorizado."}';

    return;
}

$ruta = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || preg_match('#^/interno/funciones/([0-9a-f-]{36})/cancelar-reservas$#', $ruta, $partes) !== 1) {
    http_response_code(404);

    return;
}
$cuerpo = json_decode((string) file_get_contents('php://input'), true);
file_put_contents((string) getenv('CANCELACIONES'), json_encode(['funcion' => $partes[1], ...$cuerpo]) . "\n", FILE_APPEND);
echo '{"canceladas":2,"avisadas":2}';
