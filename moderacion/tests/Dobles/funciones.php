<?php

/*
 * Funciones de mentira, atendiendo con `php -S` las rutas de la API interna de calificaciones que
 * llama moderación por una denuncia (EF-07.8). Responde lo mismo que CalificacionesInternas de
 * funciones (CalificacionesInternasTest prueba ese JSON del otro lado), y como ella, sólo a
 * moderación con su token.
 *
 * Las calificaciones que conoce son las del JSON de la variable CALIFICACIONES (id => datos), y
 * las va actualizando. Cada pedido de ocultar lo anota en el archivo de la variable REGISTRO. Si
 * existe el archivo de la variable CAIDA, responde 500 a todo.
 */

declare(strict_types=1);

header('Content-Type: application/json');
$cabeceras = getallheaders();
if (($cabeceras['X-Llamador'] ?? null) !== 'moderacion' || ($cabeceras['Authorization'] ?? null) !== 'Bearer token-de-moderacion') {
    http_response_code(403);
    echo '{"error":"Llamador no autorizado."}';

    return;
}
if (is_file((string) getenv('CAIDA'))) {
    http_response_code(500);
    echo '{"error":"Algo salió mal."}';

    return;
}

$archivo = (string) getenv('CALIFICACIONES');
/** @var array<string, array<string, mixed>> $calificaciones */
$calificaciones = is_file($archivo) ? (array) json_decode((string) file_get_contents($archivo), true) : [];
$ruta = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if (preg_match('#^/interno/calificaciones/([^/]+)(/ocultar)?$#', $ruta, $partes) !== 1) {
    http_response_code(404);

    return;
}
$id = $partes[1];
if (!isset($calificaciones[$id])) {
    http_response_code(404);

    return;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($partes[2] ?? '') === '/ocultar') {
    $pedido = (array) json_decode((string) file_get_contents('php://input'), true);
    file_put_contents((string) getenv('REGISTRO'), json_encode(['ruta' => 'ocultar', 'calificacion' => $id] + $pedido) . "\n", FILE_APPEND);
    $cambio = $calificaciones[$id]['oculto'] !== true;
    $calificaciones[$id]['oculto'] = true;
    file_put_contents($archivo, json_encode($calificaciones));
    echo json_encode(['id' => $id, 'cambio' => $cambio]);

    return;
}

echo json_encode($calificaciones[$id]);
