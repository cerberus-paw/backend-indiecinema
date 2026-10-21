<?php

/*
 * Programación de mentira, atendiendo con `php -S` la ruta que llama funciones: así
 * FuncionesDeProgramacionTest prueba el cliente por la red, como en el compose. Responde lo mismo
 * que GET /interno/funciones/{id} de programación (FuncionesInternasTest de programación prueba
 * ese mismo JSON del otro lado), y como ella, sólo a funciones con su token.
 */

declare(strict_types=1);

const PROGRAMADA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
const EN_VOTACION = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
const ROTA = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
const LENTA = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';
const SIN_PUBLICAR = '99999999-9999-4999-8999-999999999999';

header('Content-Type: application/json');
$cabeceras = getallheaders();
if (($cabeceras['X-Llamador'] ?? null) !== 'funciones' || ($cabeceras['Authorization'] ?? null) !== 'Bearer token-de-funciones') {
    http_response_code(403);
    echo '{"error":"Llamador no autorizado."}';

    return;
}

$funcion = static fn (string $id, string $estado, ?string $fecha, array $titulos, bool $elegidos, bool $publicada = true): array => [
    'id' => $id,
    'estado' => $estado,
    'fecha_hora' => $fecha,
    'precio_base' => 3500,
    'cupo' => 40,
    'titulos' => $titulos,
    'titulos_elegidos' => $elegidos,
    'sala' => ['id' => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee', 'nombre' => 'Cine Club El Galpón', 'localidad' => 'Luján', 'habilitada' => $publicada],
    'organizador_id' => 'ffffffff-ffff-4fff-8fff-ffffffffffff',
];

$id = (string) preg_replace('#^/interno/funciones/#', '', (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
match ($id) {
    PROGRAMADA => print json_encode($funcion($id, 'programada', '2026-10-24T20:30:00', ['Zama'], true)),
    EN_VOTACION => print json_encode($funcion($id, 'en_votacion_titulos', null, ['La ciénaga', 'Zama'], false)),
    // Su sala todavía no se aprobó, o se retiró de la cartelera.
    SIN_PUBLICAR => print json_encode($funcion($id, 'programada', '2026-10-24T20:30:00', ['Zama'], true, false)),
    // Un cambio en programación que rompe lo que funciones espera.
    ROTA => print json_encode(['id' => $id, 'estado' => 'programada']),
    LENTA => sleep(2),
    default => http_response_code(404),
};
