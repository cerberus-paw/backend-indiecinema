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
}
