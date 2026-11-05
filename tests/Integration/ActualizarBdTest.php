<?php

declare(strict_types=1);

namespace Patuju\Tests\Integration;

use Patuju\Tests\Support\HttpResponse;
use Patuju\Tests\Support\TestCase;

/**
 * DB-1 / DB-2: actualizar_bd.php es re-ejecutable sin pisar datos del usuario.
 */
final class ActualizarBdTest extends TestCase
{
    public function testDoesNotResetChangedAdminPassword(): void
    {
        $this->loginAs('admin');
        $reset = $this->post('/ajax/usuarios.php', [
            'accion' => 'reset_password',
            'usuario_id' => 1,
            'password' => 'adminClave9',
        ], 'admin');
        $this->assertSame(200, $reset->status, $reset->body);

        $this->runActualizarBd();

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

        $this->runActualizarBd();

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

    private function runActualizarBd(): HttpResponse
    {
        $response = $this->get('/actualizar_bd.php');

        $this->assertSame(200, $response->status, $response->body);
        $this->assertStringContainsString('Actualización completada', $response->body);
        $this->assertStringNotContainsString('Error durante la actualización', $response->body);

        return $response;
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
