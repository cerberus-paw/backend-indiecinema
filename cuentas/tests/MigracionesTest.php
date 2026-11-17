<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Pruebas;

use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Uuid;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Las tablas usuario y rol tal como las dejan las migraciones, contra un MySQL de verdad: lo que
 * se prueba son las restricciones de la base, no las de la aplicación.
 */
final class MigracionesTest extends TestCase
{
    use ConBaseDePruebas;

    private const CLAVE_DUPLICADA = 1062;
    private const FALTA_EL_PADRE = 1452;
    private const VALOR_INVALIDO = 1265;

    private BaseDeDatos $base;

    protected function setUp(): void
    {
        $this->base = self::baseConTablasNuevas(new Configuracion(['base' => self::baseDePruebas()]));
    }

    private function registrar(string $correo, string $rol = 'espectador'): string
    {
        $id = Uuid::nuevo();
        $this->base->ejecutar(
            'INSERT INTO usuario (id, nombre, correo, contrasena_hash, estado) VALUES (?, ?, ?, ?, ?)',
            [$id, 'Ana Pérez', $correo, password_hash('una clave larga', PASSWORD_ARGON2ID), 'activa'],
        );
        $this->base->ejecutar('INSERT INTO rol (usuario_id, tipo) VALUES (?, ?)', [$id, $rol]);

        return $id;
    }

    private function assertRechaza(int $codigo, callable $escritura): void
    {
        try {
            $escritura();
            self::fail("La base aceptó una escritura que tenía que rechazar con el error {$codigo}.");
        } catch (PDOException $e) {
            self::assertSame($codigo, $e->errorInfo[1] ?? null, $e->getMessage());
        }
    }

    public function testElUsuarioQuedaGuardadoConSuRolYSinVerificar(): void
    {
        $id = $this->registrar('ana@ejemplo.com');

        $usuario = $this->base->fila('SELECT correo, contrasena_hash, estado, correo_verificado_en, creado_en FROM usuario WHERE id = ?', [$id]);
        self::assertNotNull($usuario);
        self::assertSame('ana@ejemplo.com', $usuario['correo']);
        self::assertTrue(password_verify('una clave larga', $usuario['contrasena_hash']));
        self::assertSame('activa', $usuario['estado']);
        self::assertNull($usuario['correo_verificado_en']);
        self::assertNotNull($usuario['creado_en']);
        self::assertSame([['tipo' => 'espectador']], $this->base->filas('SELECT tipo FROM rol WHERE usuario_id = ?', [$id]));
    }

    public function testLaBaseRechazaUnCorreoRepetidoAunqueCambienLasMayusculas(): void
    {
        $this->registrar('ana@ejemplo.com');

        $this->assertRechaza(self::CLAVE_DUPLICADA, fn () => $this->registrar('ana@ejemplo.com'));
        $this->assertRechaza(self::CLAVE_DUPLICADA, fn () => $this->registrar('Ana@Ejemplo.COM'));
        self::assertSame(1, $this->base->valor('SELECT COUNT(*) FROM usuario'));
    }

    public function testUnUsuarioSumaRolesPeroNoRepiteUno(): void
    {
        $id = $this->registrar('ana@ejemplo.com');
        $this->base->ejecutar('INSERT INTO rol (usuario_id, tipo) VALUES (?, ?)', [$id, 'organizador']);

        $this->assertRechaza(self::CLAVE_DUPLICADA, fn () => $this->base->ejecutar('INSERT INTO rol (usuario_id, tipo) VALUES (?, ?)', [$id, 'organizador']));
        self::assertSame(2, $this->base->valor('SELECT COUNT(*) FROM rol WHERE usuario_id = ?', [$id]));
    }

    public function testNoHayRolesSinUsuarioNiFueraDeLaLista(): void
    {
        $this->assertRechaza(self::FALTA_EL_PADRE, fn () => $this->base->ejecutar('INSERT INTO rol (usuario_id, tipo) VALUES (?, ?)', [Uuid::nuevo(), 'espectador']));

        $id = $this->registrar('ana@ejemplo.com');
        // Visitante es quien no tiene cuenta: no se guarda.
        $this->assertRechaza(self::VALOR_INVALIDO, fn () => $this->base->ejecutar('INSERT INTO rol (usuario_id, tipo) VALUES (?, ?)', [$id, 'visitante']));
    }

    public function testAlBorrarUnUsuarioSeVanSusRoles(): void
    {
        $id = $this->registrar('ana@ejemplo.com');

        $this->base->ejecutar('DELETE FROM usuario WHERE id = ?', [$id]);

        self::assertSame(0, $this->base->valor('SELECT COUNT(*) FROM rol'));
    }

    public function testTokensDeCuentaValidaPropositoUnicidadYBorradoEnCascada(): void
    {
        $id = $this->registrar('ana@ejemplo.com');
        $huella = hash('sha256', 'token-de-prueba');
        $this->base->ejecutar(
            "INSERT INTO token_de_cuenta (huella, usuario_id, proposito, creado_en, vence_en)
             VALUES (?, ?, 'verificar', UTC_TIMESTAMP(), UTC_TIMESTAMP() + INTERVAL 24 HOUR)",
            [$huella, $id],
        );

        $this->assertRechaza(
            self::CLAVE_DUPLICADA,
            fn () => $this->base->ejecutar(
                "INSERT INTO token_de_cuenta (huella, usuario_id, proposito, creado_en, vence_en)
                 VALUES (?, ?, 'verificar', UTC_TIMESTAMP(), UTC_TIMESTAMP() + INTERVAL 24 HOUR)",
                [$huella, $id],
            ),
        );
        $this->assertRechaza(
            self::VALOR_INVALIDO,
            fn () => $this->base->ejecutar(
                "INSERT INTO token_de_cuenta (huella, usuario_id, proposito, creado_en, vence_en)
                 VALUES (?, ?, 'otro', UTC_TIMESTAMP(), UTC_TIMESTAMP() + INTERVAL 24 HOUR)",
                [hash('sha256', 'otro'), $id],
            ),
        );
        $this->assertRechaza(
            self::FALTA_EL_PADRE,
            fn () => $this->base->ejecutar(
                "INSERT INTO token_de_cuenta (huella, usuario_id, proposito, creado_en, vence_en)
                 VALUES (?, ?, 'verificar', UTC_TIMESTAMP(), UTC_TIMESTAMP() + INTERVAL 24 HOUR)",
                [hash('sha256', 'huerfano'), Uuid::nuevo()],
            ),
        );

        $this->base->ejecutar('DELETE FROM usuario WHERE id = ?', [$id]);
        self::assertSame(0, $this->base->valor('SELECT COUNT(*) FROM token_de_cuenta'));
    }

    public function testMigracionVerificaCuentasExistentesSinPisarLasYaVerificadas(): void
    {
        $sinVerificar = $this->registrar('vieja@ejemplo.com');
        $yaVerificada = $this->registrar('verificada@ejemplo.com');
        $this->base->ejecutar("UPDATE usuario SET correo_verificado_en = '2026-01-15 10:00:00' WHERE id = ?", [$yaVerificada]);

        $sql = (string) file_get_contents(dirname(__DIR__) . '/migraciones/007_verificar_cuentas_existentes.sql');
        $this->base->ejecutar($sql);

        self::assertNotNull($this->base->valor('SELECT correo_verificado_en FROM usuario WHERE id = ?', [$sinVerificar]));
        self::assertSame('2026-01-15 10:00:00', $this->base->valor('SELECT correo_verificado_en FROM usuario WHERE id = ?', [$yaVerificada]));
    }
}
