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

// Las franjas, a diez y once días de hoy: con fechas fijas, la prueba de confirmar empezó a
// fallar cuando esas fechas pasaron. FuncionesDelOrganizadorTest calcula las mismas.
$franja0 = (new DateTimeImmutable('+10 days'))->format('Y-m-d');
$franja1 = (new DateTimeImmutable('+11 days'))->format('Y-m-d');
$metodo = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

// Los lugares ocupados de una función (F-01.1), como GET /interno/funciones/{id}/ocupacion de
// funciones: los que la prueba escribió en ocupacion_de_prueba.json (funcion_id => ocupados), y 0
// las que no están. Sólo si la prueba dejó el archivo: las que no lo dejan siguen viendo una
// funciones que no contesta la ocupación, como hasta ahora.
$archivoDeOcupacion = sys_get_temp_dir() . '/ocupacion_de_prueba.json';
if ($metodo === 'GET' && is_file($archivoDeOcupacion) && preg_match('#^/interno/funciones/([0-9a-f-]{36})/ocupacion$#', $ruta, $partes) === 1) {
    $ocupados = json_decode((string) file_get_contents($archivoDeOcupacion), true);
    echo json_encode(['funcion_id' => $partes[1], 'lugares_ocupados' => (int) ($ocupados[$partes[1]] ?? 0)]);

    return;
}

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
    // Otros casos que las pruebas eligen escribiendo el archivo: sin acuerdo todavía, funciones
    // caído, o la franja que lidera ya empezó.
    if ($estado === 'ninguno') {
        http_response_code(404);
        echo '{"error":"No hay acuerdo de fecha para esta función."}';

        return;
    }
    if ($estado === 'caido') {
        http_response_code(500);
        echo '{"error":"Error interno."}';

        return;
    }
    if ($estado === 'pasada') {
        $franja0 = (new DateTimeImmutable('-1 day'))->format('Y-m-d');
        $estado = 'abierto';
    }
    // Pasó la fecha límite pero el acuerdo sigue abierto, porque llegó al mínimo (E3-13.F8).
    $votacionAbierta = $estado === 'abierto';
    if ($estado === 'vencido') {
        $estado = 'abierto';
        $votacionAbierta = false;
    }
    // Como la calcula funciones: las 23:59 del día anterior a la primera franja.
    $fechaLimite = (new DateTimeImmutable($franja0))->modify('-1 day')->format('Y-m-d') . ' 23:59:59';
    $votacionAbierta = $votacionAbierta && new DateTimeImmutable($fechaLimite) >= new DateTimeImmutable();
    echo json_encode([
        'id' => 'acuerdo-mock-1',
        'funcion_id' => $funcionId,
        'minimo_asistentes' => 2,
        'estado' => $estado,
        'estado_etiqueta' => $estado,
        'franja_ganadora_id' => null,
        'alcanzo_minimo' => false,
        'fecha_limite' => $fechaLimite,
        'votacion_abierta' => $votacionAbierta,
        'franjas_del_usuario' => ['franja-mock-0'],
        'franjas' => [
            ['id' => 'franja-mock-0', 'inicio' => "{$franja0} 20:00:00", 'fin' => "{$franja0} 22:30:00", 'votos' => ($estado === 'sin_minimo' ? 1 : 2)],
            ['id' => 'franja-mock-1', 'inicio' => "{$franja1} 21:00:00", 'fin' => null, 'votos' => 0],
        ],
    ]);

    return;
}

if ($metodo === 'POST' && preg_match('#^/interno/acuerdos/([^/]+)/(votar|quitar-voto)$#', $ruta, $matches)) {
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
            'inicio' => "{$franja0} 20:00:00",
            'fin' => "{$franja0} 22:30:00",
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
