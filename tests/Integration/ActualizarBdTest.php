<?php

declare(strict_types=1);

namespace Patuju\Tests\Integration;

use Patuju\Tests\Support\HttpResponse;
use Patuju\Tests\Support\TestCase;
use Patuju\Tests\Support\TestDatabase;

/**
 * DB-1 / DB-2: actualizar_bd.php es re-ejecutable sin pisar datos del usuario.
 * Desde la web solo lo ejecuta el admin; por consola, siempre.
 */
final class ActualizarBdTest extends TestCase
{
    /**
     * Sin sesión o con otro rol, la web no ejecuta la migración: redirige y no toca la BD.
     */
    public function testWebAccessRequiresAdmin(): void
    {
        $this->db()->exec('DROP TABLE usuario_sucursales');
        $tablaExiste = fn (): bool => (int) $this->scalar(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'usuario_sucursales'"
        ) === 1;

        $anonimo = $this->get('/actualizar_bd.php');
        $this->assertSame(302, $anonimo->status, $anonimo->body);
        $this->assertSame('login.php', $anonimo->header('location'));
        $this->assertStringNotContainsString('Actualización', $anonimo->body);
        $this->assertFalse($tablaExiste());

        foreach (['caja_caja_central', 'encargado_encargado_central'] as $usuario) {
            $this->loginAs($usuario);
            $response = $this->get('/actualizar_bd.php', $usuario);
            $this->assertSame(302, $response->status, $usuario);
            $this->assertStringContainsString('login.php?error=sin_permisos', (string) $response->header('location'), $usuario);
            $this->assertFalse($tablaExiste(), $usuario);
        }

        $this->runActualizarBd();
        $this->assertTrue($tablaExiste());
    }

    public function testAdminPanelLinksToMigration(): void
    {
        $this->loginAs('admin');
        $this->assertStringContainsString('href="actualizar_bd.php"', $this->get('/admin.php', 'admin')->body);
    }

    public function testDoesNotResetChangedAdminPassword(): void
    {
        $this->loginAs('admin');
        $reset = $this->post('/ajax/usuarios.php', [
            'accion' => 'reset_password',
            'usuario_id' => 1,
            'password' => 'adminClave9',
        ], 'admin');
        $this->assertSame(200, $reset->status, $reset->body);

        $this->runActualizarBd(false);

        $hash = (string) $this->scalar('SELECT password_hash FROM usuarios WHERE id = 1');
        $this->assertTrue(password_verify('adminClave9', $hash));
        $this->assertFalse(password_verify('patuju2024', $hash));

        $this->loginAs('admin', 'adminClave9');
        $anterior = $this->post('/ajax/login.php', [
            'username' => 'admin',
            'password' => 'patuju2024',
        ]);
        $this->assertSame(401, $anterior->status, $anterior->body);
    }

    public function testFixesAdminPasswordWithoutUsableHash(): void
    {
        $this->db()->exec("UPDATE usuarios SET password_hash = 'texto_plano' WHERE id = 1");

        $this->runActualizarBdCli();

        $this->loginAs('admin', 'patuju2024');
    }

    public function testDoesNotReseedModifiedAssignments(): void
    {
        $this->loginAs('admin');
        $asignar = $this->post('/ajax/usuarios.php', [
            'accion' => 'asignar_sucursales',
            'usuario_id' => 2,
            'sucursales_ids' => [1, 2],
        ], 'admin');
        $this->assertSame(200, $asignar->status, $asignar->body);
        $antes = $this->asignaciones();

        $this->runActualizarBd();

        $this->assertSame($antes, $this->asignaciones());
        $this->assertSame([1, 2], $this->asignaciones()[2]);
    }

    public function testDoesNotReseedEmptiedAssignments(): void
    {
        $this->db()->exec('DELETE FROM usuario_sucursales');

        $response = $this->runActualizarBd();

        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM usuario_sucursales'));
        $this->assertStringContainsString('se respetan las asignaciones actuales', $response->body);
    }

    /**
     * Abrir la pestaña Usuarios antes de migrar no crea la tabla vacía (eso anularía la siembra):
     * responde 503 pidiendo la migración, y la migración posterior siembra igual.
     */
    public function testUsuariosEndpointBeforeMigrationDoesNotBlockSeeding(): void
    {
        $this->db()->exec('DROP TABLE usuario_sucursales');

        $this->loginAs('admin');
        $listar = $this->get('/ajax/usuarios.php?accion=listar', 'admin');
        $this->assertSame(503, $listar->status, $listar->body);
        $this->assertStringContainsString('actualizar_bd.php', $listar->json()['error']);
        $this->assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'usuario_sucursales'"));

        $this->runActualizarBd();

        $this->assertSame([
            2 => [1, 2, 3, 4, 5],
            3 => [6, 7],
            4 => [8, 9, 10, 11, 12, 13, 14, 15],
        ], $this->asignaciones());
    }

    /**
     * Paso 9: textos doblemente codificados ("SalteÃ±a") vuelven a UTF-8 correcto;
     * los que ya están bien, o que no se pueden revertir sin perder datos, no se tocan.
     */
    public function testRepairsDoubleEncodedText(): void
    {
        // Simula un schema.sql importado en latin1: bytes UTF-8 leídos como latin1
        $romper = fn (string $texto): string => "CONVERT(CONVERT(CAST(" . $this->db()->quote($texto) . " AS BINARY) USING latin1) USING utf8mb4)";
        $this->db()->exec('UPDATE productos SET nombre = ' . $romper('Salteña Grande') . ', descripcion = ' . $romper('Recién horneada, con ají') . ' WHERE id = 1');
        $this->db()->exec('UPDATE categorias SET nombre = ' . $romper('Salteñas') . ' WHERE id = 1');
        $this->db()->exec('UPDATE sucursales SET responsable = ' . $romper('María López') . ', direccion = ' . $romper('Av. Cañoto #100') . ' WHERE id = 1');
        // Bien escrito aunque contenga "Ã": no se toca
        $this->db()->exec("UPDATE sucursales SET direccion = 'Rua SÃO João 12' WHERE id = 2");
        // Roto pero con un emoji que latin1 no representa: revertirlo lo perdería, no se toca
        $this->db()->exec('UPDATE productos SET descripcion = CONCAT(' . $romper('Salteña') . ", ' 🥟') WHERE id = 2");
        $conEmoji = (string) $this->scalar('SELECT descripcion FROM productos WHERE id = 2');
        $this->assertSame('SalteÃ±a', mb_substr($conEmoji, 0, 8));

        $this->assertStringContainsString('Textos reparados', $this->runActualizarBd()->body);

        $this->assertSame('Salteña Grande', $this->scalar('SELECT nombre FROM productos WHERE id = 1'));
        $this->assertSame('Recién horneada, con ají', $this->scalar('SELECT descripcion FROM productos WHERE id = 1'));
        $this->assertSame('Salteñas', $this->scalar('SELECT nombre FROM categorias WHERE id = 1'));
        $this->assertSame('María López', $this->scalar('SELECT responsable FROM sucursales WHERE id = 1'));
        $this->assertSame('Av. Cañoto #100', $this->scalar('SELECT direccion FROM sucursales WHERE id = 1'));
        $this->assertSame('Rua SÃO João 12', $this->scalar('SELECT direccion FROM sucursales WHERE id = 2'));
        $this->assertSame($conEmoji, $this->scalar('SELECT descripcion FROM productos WHERE id = 2'));

        // Idempotente: una segunda pasada no cambia nada, pero avisa del texto que no pudo reparar
        $segunda = $this->runActualizarBd()->body;
        $this->assertStringContainsString('No hay textos con caracteres rotos para reparar', $segunda);
        $this->assertStringContainsString('productos.descripcion (1)', $segunda);
        $this->assertSame('Salteña Grande', $this->scalar('SELECT nombre FROM productos WHERE id = 1'));
    }

    public function testSeedsAssignmentsWhenTableIsMissing(): void
    {
        $this->db()->exec('DROP TABLE usuario_sucursales');

        $this->runActualizarBd();

        $this->assertSame([
            2 => [1, 2, 3, 4, 5],
            3 => [6, 7],
            4 => [8, 9, 10, 11, 12, 13, 14, 15],
        ], $this->asignaciones());
    }

    private function runActualizarBd(bool $iniciarSesion = true): HttpResponse
    {
        if ($iniciarSesion) {
            $this->loginAs('admin');
        }
        $response = $this->get('/actualizar_bd.php', 'admin');

        $this->assertSame(200, $response->status, $response->body);
        $this->assertStringContainsString('Actualización completada', $response->body);
        $this->assertStringNotContainsString('Error durante la actualización', $response->body);

        return $response;
    }

    /**
     * Ejecuta "php actualizar_bd.php" contra la BD de pruebas (sin sesión: vía consola).
     */
    private function runActualizarBdCli(): string
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proceso = proc_open(
            [PHP_BINARY, PATUJU_ROOT . '/actualizar_bd.php'],
            $descriptors,
            $pipes,
            PATUJU_ROOT,
            array_merge(getenv(), TestDatabase::processEnv())
        );
        $this->assertIsResource($proceso);
        $salida = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($proceso), $salida);
        $this->assertStringContainsString('Actualización completada', $salida);
        $this->assertStringNotContainsString('Error durante la actualización', $salida);

        return $salida;
    }

    /**
     * @return array<int, list<int>> usuario_id => sucursales asignadas (ordenadas)
     */
    private function asignaciones(): array
    {
        $mapa = [];
        foreach ($this->rows('SELECT usuario_id, sucursal_id FROM usuario_sucursales ORDER BY usuario_id, sucursal_id') as $row) {
            $mapa[(int) $row['usuario_id']][] = (int) $row['sucursal_id'];
        }

        return $mapa;
    }
}
