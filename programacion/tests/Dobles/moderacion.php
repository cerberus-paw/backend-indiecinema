<?php

/*
 * Moderación de mentira para el castigo en la cartelera (EF-22.11): atiende con `php -S`
 * GET /interno/denuncias/castigadas, como moderación (DenunciasTest de moderación prueba ese mismo
 * JSON del otro lado), y sólo a programación con su token.
 *
 * Responde las salas del JSON que nombra la variable de entorno CASTIGADAS, {salas: [id, …]}, y
 * anota en ese mismo directorio, en CASTIGADAS.pedidos, una línea por pedido.
 */

declare(strict_types=1);

header('Content-Type: application/json');
$cabeceras = getallheaders();
if (($cabeceras['X-Llamador'] ?? null) !== 'programacion' || ($cabeceras['Authorization'] ?? null) !== 'Bearer token-de-programacion') {
    http_response_code(403);
    echo '{"error":"Llamador no autorizado."}';

    return;
}

$archivo = (string) getenv('CASTIGADAS');
if ((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) !== '/interno/denuncias/castigadas') {
    http_response_code(404);

    return;
}
file_put_contents("{$archivo}.pedidos", "castigadas\n", FILE_APPEND);
echo is_file($archivo) ? (string) file_get_contents($archivo) : '{"salas":[]}';
