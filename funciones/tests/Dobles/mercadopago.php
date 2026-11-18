<?php

/*
 * La API de Mercado Pago de mentira, atendiendo con `php -S` las rutas que llama funciones: así
 * ClienteMercadoPago se prueba por la red, con su curl, como contra la de verdad. Responde lo
 * mismo que la API (los campos que funciones lee), y como ella, sólo con el access token de la
 * cuenta vendedora.
 *
 * Lo que recibe lo anota en la carpeta de MERCADOPAGO_DE_PRUEBA, un JSON por pedido, para que la
 * prueba vea qué le mandaron; y los pagos que la prueba deja en esa carpeta (pago-<id>.json) son
 * los que devuelve GET /v1/payments/{id}.
 */

declare(strict_types=1);

const ACCESS_TOKEN = 'TEST-token-de-la-vendedora';

$carpeta = (string) getenv('MERCADOPAGO_DE_PRUEBA');
$ruta = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$metodo = $_SERVER['REQUEST_METHOD'];
$cabeceras = array_change_key_case(getallheaders());

header('Content-Type: application/json');

/**
 * El pedido, para que la prueba lo lea en orden: pedido-<microtime>.json.
 */
$anotar = static function (array $pedido) use ($carpeta): void {
    file_put_contents(sprintf('%s/pedido-%.6f-%s.json', $carpeta, microtime(true), bin2hex(random_bytes(2))), json_encode($pedido));
};

$responder = static function (int $estado, array $datos): void {
    http_response_code($estado);
    echo json_encode($datos);
};

if (($cabeceras['authorization'] ?? null) !== 'Bearer ' . ACCESS_TOKEN) {
    $responder(401, ['message' => 'invalid access token', 'error' => 'unauthorized', 'status' => 401]);

    return;
}

$cuerpo = json_decode((string) file_get_contents('php://input'), true);
$anotar([
    'metodo' => $metodo,
    'ruta' => $ruta,
    'idempotencia' => $cabeceras['x-idempotency-key'] ?? null,
    'cuerpo' => $cuerpo,
]);

// Una falla de Mercado Pago, para probar que la reserva no queda a medias.
if (is_file("{$carpeta}/caida")) {
    $responder(503, ['message' => 'service unavailable', 'status' => 503]);

    return;
}

if ($metodo === 'POST' && $ruta === '/checkout/preferences') {
    if (!is_array($cuerpo) || !isset($cuerpo['items'][0]['unit_price'])) {
        $responder(400, ['message' => 'items needed', 'error' => 'bad_request', 'status' => 400]);

        return;
    }
    $id = '123456789-' . bin2hex(random_bytes(8));
    $responder(201, [
        'id' => $id,
        'init_point' => "https://www.mercadopago.com.ar/checkout/v1/redirect?pref_id={$id}",
        'sandbox_init_point' => "https://sandbox.mercadopago.com.ar/checkout/v1/redirect?pref_id={$id}",
        'external_reference' => $cuerpo['external_reference'] ?? null,
    ]);

    return;
}

if ($metodo === 'GET' && preg_match('#^/v1/payments/(\d+)$#', $ruta, $coincidencia) === 1) {
    $archivo = "{$carpeta}/pago-{$coincidencia[1]}.json";
    if (!is_file($archivo)) {
        $responder(404, ['message' => 'Payment not found', 'error' => 'not_found', 'status' => 404]);

        return;
    }
    echo file_get_contents($archivo);

    return;
}

if ($metodo === 'POST' && preg_match('#^/v1/payments/(\d+)/refunds$#', $ruta, $coincidencia) === 1) {
    $responder(201, ['id' => random_int(1, PHP_INT_MAX), 'payment_id' => (int) $coincidencia[1], 'status' => 'approved']);

    return;
}

$responder(404, ['message' => 'resource not found', 'error' => 'not_found', 'status' => 404]);
