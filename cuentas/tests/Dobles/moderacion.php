<?php

/*
 * Moderación de mentira, atendiendo con `php -S` la ruta que llama «Mi cuenta» (EF-07.6): así
 * MiCuentaTest prueba la llamada por la red, como en el compose. Responde lo mismo que
 * /interno/solicitudes/ultima/{usuario} de moderación (SolicitudesInternasTest de moderación prueba
 * ese mismo JSON del otro lado), y como ella, sólo a cuentas con su token.
 *
 * La última solicitud de cada usuario sale del JSON del archivo de la variable SOLICITUDES; sin
 * una, 404. Si existe el archivo de la variable CAIDA, responde 500 a todo.
 */

declare(strict_types=1);

header('Content-Type: application/json');
$cabeceras = getallheaders();
if (($cabeceras['X-Llamador'] ?? null) !== 'cuentas' || ($cabeceras['Authorization'] ?? null) !== 'Bearer token-de-cuentas') {
    http_response_code(403);
    echo '{"error":"Llamador no autorizado."}';

    return;
}
if (is_file((string) getenv('CAIDA'))) {
    http_response_code(500);

    return;
}
if (!preg_match('#^/interno/solicitudes/ultima/([^/]+)$#', (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), $partes)) {
    http_response_code(404);

    return;
}
$solicitudes = (array) json_decode((string) file_get_contents((string) getenv('SOLICITUDES')), true);
if (!isset($solicitudes[$partes[1]])) {
    http_response_code(404);
    echo '{"error":"No existe."}';

    return;
}

echo json_encode($solicitudes[$partes[1]]);
