<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Pruebas;

/**
 * Para las pruebas que cobran con Mercado Pago (X-01): levanta su API de mentira
 * (Dobles/mercadopago.php) con `php -S` en un puerto libre, con una carpeta propia donde el doble
 * anota lo que recibe y de donde lee los pagos que deja la prueba. Se llama desde el
 * setUpBeforeClass() de la prueba, como ConCuentasDePrueba.
 */
trait ConMercadoPagoDePrueba
{
    /** El que acepta el doble: el de la cuenta vendedora. */
    private const ACCESS_TOKEN = 'TEST-token-de-la-vendedora';
    private const CLAVE_WEBHOOK = 'clave-secreta-de-los-avisos';

    /** @var resource|null */
    private static $mercadoPago = null;

    private static string $urlMercadoPago;
    private static string $carpetaMercadoPago;

    private static function iniciarMercadoPago(): void
    {
        self::$carpetaMercadoPago = sys_get_temp_dir() . '/mercadopago-' . bin2hex(random_bytes(6));
        mkdir(self::$carpetaMercadoPago);
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $puerto = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        self::$urlMercadoPago = "http://127.0.0.1:{$puerto}";
        $proceso = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$puerto}", __DIR__ . '/Dobles/mercadopago.php'],
            [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
            $tuberias,
            null,
            [...getenv(), 'MERCADOPAGO_DE_PRUEBA' => self::$carpetaMercadoPago],
        );
        self::$mercadoPago = $proceso === false ? null : $proceso;
        for ($intento = 0; $intento < 100; ++$intento) {
            $conexion = @fsockopen('127.0.0.1', $puerto, $codigo, $mensaje, 0.05);
            if ($conexion !== false) {
                fclose($conexion);

                return;
            }
            usleep(20_000);
        }
        self::fail('No arrancó el Mercado Pago de prueba.');
    }

    private static function detenerMercadoPago(): void
    {
        if (self::$mercadoPago !== null) {
            proc_terminate(self::$mercadoPago);
            proc_close(self::$mercadoPago);
            self::$mercadoPago = null;
        }
        array_map(unlink(...), glob(self::$carpetaMercadoPago . '/*') ?: []);
        @rmdir(self::$carpetaMercadoPago);
    }

    /**
     * Antes de cada prueba: sin pedidos anotados, sin pagos y andando.
     */
    private static function vaciarMercadoPago(): void
    {
        array_map(unlink(...), glob(self::$carpetaMercadoPago . '/*') ?: []);
    }

    /**
     * La sección [mercadopago] de la configuración, prendida y apuntando al doble.
     *
     * @return array<string, mixed>
     */
    private static function mercadoPagoPrendido(): array
    {
        return [
            'activo' => true,
            'access_token' => self::ACCESS_TOKEN,
            'clave_webhook' => self::CLAVE_WEBHOOK,
            'minutos_para_pagar' => 15,
            'api' => self::$urlMercadoPago,
        ];
    }

    /**
     * Lo que recibió el doble, en orden.
     *
     * @return list<array{metodo: string, ruta: string, idempotencia: ?string, cuerpo: mixed}>
     */
    private static function pedidosAMercadoPago(): array
    {
        $archivos = glob(self::$carpetaMercadoPago . '/pedido-*.json') ?: [];
        sort($archivos);

        return array_map(static fn (string $archivo): array => json_decode((string) file_get_contents($archivo), true), $archivos);
    }

    /**
     * Desde ahora, Mercado Pago responde 503 a todo.
     */
    private static function mercadoPagoCaido(): void
    {
        touch(self::$carpetaMercadoPago . '/caida');
    }
}
