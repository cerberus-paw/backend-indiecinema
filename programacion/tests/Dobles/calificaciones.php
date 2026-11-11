<?php

/*
 * Funciones de mentira para las calificaciones de las fichas (EF-04.3): atiende con `php -S`
 * GET /interno/calificaciones/resumen y GET /interno/calificaciones, como funciones
 * (CalificacionesInternasTest de funciones prueba ese mismo JSON del otro lado), y sólo a
 * programación con su token.
 *
 * Lo que responde lo decide la prueba, en el JSON que nombra la variable de entorno CALIFICACIONES:
 * {salas, obras, organizadores, funciones: {id: {promedio, cantidad}}, asistentes: {id: n},
 * ultimas: {sala|obra: {id: [...]}}}. Del resumen devuelve sólo los ids que se pidieron. Las
 * ocupaciones de la ficha no las atiende: la ficha sale sin los lugares libres.
 */

declare(strict_types=1);

header('Content-Type: application/json');
$cabeceras = getallheaders();
if (($cabeceras['X-Llamador'] ?? null) !== 'programacion' || ($cabeceras['Authorization'] ?? null) !== 'Bearer token-de-programacion') {
    http_response_code(403);
    echo '{"error":"Llamador no autorizado."}';

    return;
}

$archivo = (string) getenv('CALIFICACIONES');
$datos = is_file($archivo) ? json_decode((string) file_get_contents($archivo), true) : [];
$ruta = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($ruta === '/interno/calificaciones/resumen') {
    $respuesta = [];
    foreach (['salas', 'obras', 'organizadores', 'funciones'] as $tipo) {
        $pedidos = array_filter(explode(',', (string) ($_GET[$tipo] ?? '')));
        $respuesta[$tipo] = (object) array_intersect_key($datos[$tipo] ?? [], array_flip($pedidos));
    }
    $funciones = array_filter(explode(',', (string) ($_GET['funciones'] ?? '')));
    $respuesta['asistentes'] = (object) array_map(static fn (string $id): int => $datos['asistentes'][$id] ?? 0, array_combine($funciones, $funciones));
    echo json_encode($respuesta);
} elseif ($ruta === '/interno/calificaciones') {
    $de = isset($_GET['sala']) ? 'sala' : 'obra';
    echo json_encode(['calificaciones' => $datos['ultimas'][$de][$_GET[$de] ?? ''] ?? []]);
} else {
    http_response_code(404);
}
