<?php

/*
 * Cuentas de mentira, atendiendo con `php -S` los nombres de los usuarios que pide recaudación
 * (GET /interno/usuarios?ids=…). Responde lo mismo que UsuariosInternos de cuentas, y como ella,
 * sólo a recaudación con su token.
 *
 * Es para la pantalla de liquidaciones del administrador (EF-08.3), que muestra a quién hay que
 * transferirle. Un id que no conoce no viene en la respuesta: el que llama tiene que bancarse que
 * falte, como cuando cuentas no responde.
 */

declare(strict_types=1);

const ORGANIZADORA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
const DUENA = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';

header('Content-Type: application/json');
$cabeceras = getallheaders();
if (($cabeceras['X-Llamador'] ?? null) !== 'recaudacion' || ($cabeceras['Authorization'] ?? null) !== 'Bearer token-de-recaudacion') {
    http_response_code(403);
    echo '{"error":"Llamador no autorizado."}';

    return;
}
if ((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) !== '/interno/usuarios') {
    http_response_code(404);

    return;
}

$nombres = [
    ORGANIZADORA => ['id' => ORGANIZADORA, 'nombre' => 'Olga Organizadora', 'correo' => 'olga@indiecinema.test'],
    DUENA => ['id' => DUENA, 'nombre' => 'Dalia Dueña', 'correo' => 'dalia@indiecinema.test'],
];
$pedidos = explode(',', (string) ($_GET['ids'] ?? ''));

echo json_encode((object) array_intersect_key($nombres, array_flip($pedidos)));
