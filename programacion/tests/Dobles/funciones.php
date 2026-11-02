<?php

declare(strict_types=1);

/**
 * Doble de pruebas de funciones para programación: responde a las llamadas de acuerdos de fecha (E3-13).
 */

header('Content-Type: application/json');
$cabeceras = array_change_key_case(getallheaders(), CASE_LOWER);
if (($cabeceras['x-llamador'] ?? null) !== 'programacion' || ($cabeceras['authorization'] ?? null) !== 'Bearer token-de-programacion') {
    http_response_code(403);
    echo '{"error":"Llamador no autorizado."}';

    return;
}

$ruta = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$metodo = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($metodo === 'POST' && $ruta === '/interno/acuerdos') {
    $cuerpo = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($cuerpo) || empty($cuerpo['funcion_id']) || empty($cuerpo['minimo_asistentes']) || empty($cuerpo['franjas'])) {
        http_response_code(422);
        echo '{"error":"Datos incompletos para crear acuerdo."}';

        return;
    }

    http_response_code(201);
    @unlink(sys_get_temp_dir() . '/acuerdo_estado_global.txt');
    echo json_encode([
        'id' => 'acuerdo-mock-1',
        'funcion_id' => $cuerpo['funcion_id'],
        'minimo_asistentes' => (int) $cuerpo['minimo_asistentes'],
        'estado' => 'abierto',
        'estado_etiqueta' => 'abierto',
        'franja_ganadora_id' => null,
        'alcanzo_minimo' => false,
        'creado_en' => '2026-10-22 12:00:00',
        'franjas' => array_map(static fn (array $f, int $i): array => [
            'id' => "franja-mock-{$i}",
            'acuerdo_id' => 'acuerdo-mock-1',
            'inicio' => $f['fecha_hora_inicio'],
            'fin' => $f['fecha_hora_fin'] ?? null,
            'votos' => 0,
        ], $cuerpo['franjas'], array_keys($cuerpo['franjas'])),
    ]);

    return;
}

if ($metodo === 'GET' && str_starts_with($ruta, '/interno/acuerdos/por-funcion/')) {
    $funcionId = (string) substr($ruta, strlen('/interno/acuerdos/por-funcion/'));
    $archivoGlobal = sys_get_temp_dir() . '/acuerdo_estado_global.txt';
    $estado = file_exists($archivoGlobal) ? trim((string) file_get_contents($archivoGlobal)) : 'abierto';
    echo json_encode([
        'id' => 'acuerdo-mock-1',
        'funcion_id' => $funcionId,
        'minimo_asistentes' => 2,
        'estado' => $estado,
        'estado_etiqueta' => $estado,
        'franja_ganadora_id' => null,
        'alcanzo_minimo' => false,
        'voto_usuario_franja_id' => 'franja-mock-0',
        'franjas' => [
            ['id' => 'franja-mock-0', 'inicio' => '2026-10-25 20:00:00', 'fin' => '2026-10-25 22:30:00', 'votos' => ($estado === 'sin_minimo' ? 1 : 2)],
            ['id' => 'franja-mock-1', 'inicio' => '2026-10-26 21:00:00', 'fin' => null, 'votos' => 0],
        ],
    ]);

    return;
}

if ($metodo === 'POST' && preg_match('#^/interno/acuerdos/([^/]+)/votar$#', $ruta, $matches)) {
    echo json_encode(['ok' => true]);

    return;
}

if ($metodo === 'POST' && preg_match('#^/interno/acuerdos/([^/]+)/confirmar$#', $ruta, $matches)) {
    echo json_encode([
        'id' => $matches[1],
        'estado' => 'confirmado',
        'franja_ganadora_id' => 'franja-mock-0',
        'franja_ganadora' => [
            'id' => 'franja-mock-0',
            'inicio' => '2026-10-25 20:00:00',
            'fin' => '2026-10-25 22:30:00',
            'votos' => 2,
        ],
    ]);

    return;
}

if ($metodo === 'POST' && preg_match('#^/interno/acuerdos/([^/]+)/cerrar-sin-minimo$#', $ruta, $matches)) {
    file_put_contents(sys_get_temp_dir() . '/acuerdo_estado_global.txt', 'sin_minimo');
    echo json_encode([
        'id' => $matches[1],
        'estado' => 'sin_minimo',
    ]);

    return;
}

http_response_code(404);
echo '{"error":"Ruta no encontrada"}';
