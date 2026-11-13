<?php

/*
 * Programación de mentira, atendiendo con `php -S` las rutas de la API interna de salas que llama
 * moderación (EF-07.1): así AdministracionDeSolicitudesTest prueba las llamadas por la red, como en
 * el compose. Responde lo mismo que SalasInternas de programación (SalasInternasTest prueba ese
 * JSON del otro lado), y como ella, sólo a moderación con su token.
 *
 * Cada pedido lo anota en el archivo de la variable REGISTRO, con la foto como su SHA-1, para que
 * la prueba vea qué le llegó. Las salas que conoce son las del JSON de la variable SALAS (id =>
 * {nombre, localidad, organizador_id, habilitada}), y las va actualizando. Si existe el archivo de
 * la variable CAIDA, responde 500 a todo, como si estuviera caída.
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

$archivoDeSalas = (string) getenv('SALAS');
/** @var array<string, array<string, mixed>> $salas */
$salas = is_file($archivoDeSalas) ? (array) json_decode((string) file_get_contents($archivoDeSalas), true) : [];
$ruta = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$metodo = $_SERVER['REQUEST_METHOD'];
$anotar = static function (array $pedido): void {
    file_put_contents((string) getenv('REGISTRO'), json_encode($pedido) . "\n", FILE_APPEND);
};
$guardar = static function (array $salas) use ($archivoDeSalas): void {
    file_put_contents($archivoDeSalas, json_encode($salas));
};

if ($metodo === 'GET' && $ruta === '/interno/salas') {
    $ids = explode(',', (string) ($_GET['ids'] ?? ''));
    echo json_encode((object) array_intersect_key($salas, array_flip($ids)));

    return;
}

if ($metodo === 'POST' && $ruta === '/interno/salas') {
    // Multipart con la foto, o JSON sin ella (una sala que ya existe).
    $campos = $_POST !== [] ? $_POST : (array) json_decode((string) file_get_contents('php://input'), true);
    $foto = $_FILES['imagen']['tmp_name'] ?? null;
    $anotar(['ruta' => 'crear'] + $campos + ['foto' => is_string($foto) && is_uploaded_file($foto) ? sha1_file($foto) : null]);
    $id = (string) $campos['id'];
    $existia = isset($salas[$id]);
    $salas[$id] = ['nombre' => $campos['nombre'], 'localidad' => $campos['localidad'], 'organizador_id' => $campos['organizador_id'], 'habilitada' => true];
    $guardar($salas);
    http_response_code($existia ? 200 : 201);
    echo json_encode(['id' => $id] + $salas[$id]);

    return;
}

if ($metodo === 'POST' && preg_match('#^/interno/salas/([^/]+)/habilitacion$#', $ruta, $partes) === 1) {
    $pedido = (array) json_decode((string) file_get_contents('php://input'), true);
    $anotar(['ruta' => 'habilitacion', 'sala' => $partes[1]] + $pedido);
    if (!isset($salas[$partes[1]])) {
        http_response_code(404);

        return;
    }
    $salas[$partes[1]]['habilitada'] = (bool) $pedido['habilitada'];
    $guardar($salas);
    echo json_encode(['id' => $partes[1], 'habilitada' => (bool) $pedido['habilitada']]);

    return;
}

http_response_code(404);
