<?php

declare(strict_types=1);

namespace Patuju\Tests\Integration;

use Patuju\Tests\Support\TestCase;

/**
 * SEC-2 / C6: la sesión se relee de BD en cada petición (rol, activo y sucursal).
 */
final class SessionSyncTest extends TestCase
{
    public function testDeactivatedUserLosesOpenAjaxSession(): void
    {
        $this->loginAs('caja_caja_central');
        $this->assertSame(200, $this->get('/ajax/productos.php', 'caja_caja_central')->status);

        $this->loginAs('admin');
        $toggle = $this->post('/ajax/usuarios.php', [
            'accion' => 'toggle_activo',
            'usuario_id' => 5,
        ], 'admin');
        $this->assertSame(200, $toggle->status, $toggle->body);
        $this->assertSame(0, $toggle->json()['nuevo_estado']);

        // Misma cookie de antes: la siguiente petición AJAX ya no es válida
        $response = $this->get('/ajax/productos.php', 'caja_caja_central');
        $this->assertSame(401, $response->status, $response->body);
        $data = $response->json();
        $this->assertFalse($data['success']);
        $this->assertSame('Sesión expirada', $data['error']);

        // Reactivar al usuario no revive la sesión destruida: debe volver a iniciar sesión
        $this->post('/ajax/usuarios.php', [
            'accion' => 'toggle_activo',
            'usuario_id' => 5,
        ], 'admin');
        $this->assertSame(1, (int) $this->scalar('SELECT activo FROM usuarios WHERE id = 5'));
        $this->assertSame(401, $this->get('/ajax/productos.php', 'caja_caja_central')->status);
    }

    public function testDeactivatedUserIsRedirectedFromPages(): void
    {
        $this->loginAs('caja_caja_central');
        $this->assertSame(200, $this->get('/index.php', 'caja_caja_central')->status);

        $this->db()->exec('UPDATE usuarios SET activo = 0 WHERE id = 5');

        $response = $this->get('/index.php', 'caja_caja_central');
        $this->assertSame(302, $response->status);
        $location = (string) $response->header('location');
        $this->assertStringContainsString('login.php', $location);
        $this->assertStringNotContainsString('sin_permisos', $location);
    }

    public function testDeletedUserLosesOpenSession(): void
    {
        $this->loginAs('admin');
        $crear = $this->post('/ajax/usuarios.php', [
            'accion' => 'crear',
            'username' => 'cajero_temporal',
            'password' => 'secreto123',
            'nombre_display' => 'Cajero Temporal',
            'rol' => 'caja',
            'sucursal_id' => 2,
        ], 'admin');
        $this->assertSame(200, $crear->status, $crear->body);
        $id = (int) $crear->json()['usuario_id'];

        $this->loginAs('cajero_temporal', 'secreto123');
        $this->assertSame(200, $this->get('/ajax/productos.php', 'cajero_temporal')->status);

        $this->db()->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$id]);

        $response = $this->get('/ajax/productos.php', 'cajero_temporal');
        $this->assertSame(401, $response->status, $response->body);
        $this->assertSame('Sesión expirada', $response->json()['error']);
    }

    public function testDemotedAdminCannotUseUsuariosEndpoint(): void
    {
        $this->loginAs('admin');
        $crear = $this->post('/ajax/usuarios.php', [
            'accion' => 'crear',
            'username' => 'admin_dos',
            'password' => 'secreto123',
            'nombre_display' => 'Segundo Admin',
            'rol' => 'admin',
        ], 'admin');
        $this->assertSame(200, $crear->status, $crear->body);
        $id = (int) $crear->json()['usuario_id'];

        $this->loginAs('admin_dos', 'secreto123');
        $this->assertSame(200, $this->get('/ajax/usuarios.php', 'admin_dos')->status);

        // El primer admin lo degrada a caja mientras su sesión sigue abierta
        $degradar = $this->post('/ajax/usuarios.php', [
            'accion' => 'editar',
            'id' => $id,
            'nombre_display' => 'Segundo Admin',
            'rol' => 'caja',
            'sucursal_id' => 1,
            'activo' => 1,
        ], 'admin');
        $this->assertSame(200, $degradar->status, $degradar->body);

        $listar = $this->get('/ajax/usuarios.php', 'admin_dos');
        $this->assertContains($listar->status, [401, 403], $listar->body);

        $toggle = $this->post('/ajax/usuarios.php', [
            'accion' => 'toggle_activo',
            'usuario_id' => 6,
        ], 'admin_dos');
        $this->assertContains($toggle->status, [401, 403], $toggle->body);
        $this->assertSame(1, (int) $this->scalar('SELECT activo FROM usuarios WHERE id = 6'));

        $admin = $this->get('/admin.php', 'admin_dos');
        $this->assertSame(302, $admin->status);
        $this->assertStringContainsString('login.php', (string) $admin->header('location'));
    }

    public function testDemotedEncargadoLosesManagerAccess(): void
    {
        $this->loginAs('encargado_encargado_central');
        $this->assertSame(200, $this->get('/encargado.php', 'encargado_encargado_central')->status);

        $this->loginAs('admin');
        $degradar = $this->post('/ajax/usuarios.php', [
            'accion' => 'editar',
            'id' => 2,
            'nombre_display' => 'Ex Encargado',
            'rol' => 'caja',
            'sucursal_id' => 3,
            'activo' => 1,
        ], 'admin');
        $this->assertSame(200, $degradar->status, $degradar->body);

        $pagina = $this->get('/encargado.php', 'encargado_encargado_central');
        $this->assertSame(302, $pagina->status);
        $this->assertStringContainsString('login.php?error=sin_permisos', (string) $pagina->header('location'));

        $ajuste = $this->post('/ajax/stock.php', [
            'accion' => 'ajuste',
            'producto_id' => 1,
            'cantidad' => 5,
        ], 'encargado_encargado_central');
        $this->assertSame(403, $ajuste->status);
        $this->assertSame(80, $this->stock(1, 1));
        $this->assertSame(80, $this->stock(3, 1));

        // Como caja solo ve la sucursal nueva (3): pedir una que tenía asignada responde 403
        $this->assertSame(403, $this->get('/ajax/stock.php?sucursal_id=2', 'encargado_encargado_central')->status);
        $stock = $this->get('/ajax/stock.php', 'encargado_encargado_central');
        $this->assertSame(200, $stock->status, $stock->body);
        foreach ($stock->json()['data'] as $row) {
            $this->assertSame(3, (int) $row['sucursal_id']);
        }
    }

    public function testBranchChangeAppliesOnNextRequest(): void
    {
        $this->loginAs('caja_caja_central');
        $this->assertStringContainsString('data-sucursal-id="1"', $this->get('/index.php', 'caja_caja_central')->body);

        $this->loginAs('admin');
        $editar = $this->post('/ajax/usuarios.php', [
            'accion' => 'editar',
            'id' => 5,
            'nombre_display' => 'Caja Central',
            'rol' => 'caja',
            'sucursal_id' => 2,
            'activo' => 1,
        ], 'admin');
        $this->assertSame(200, $editar->status, $editar->body);

        $stock = $this->get('/ajax/stock.php', 'caja_caja_central');
        $this->assertSame(200, $stock->status, $stock->body);
        $data = $stock->json()['data'];
        $this->assertCount(16, $data);
        foreach ($data as $row) {
            $this->assertSame(2, (int) $row['sucursal_id']);
        }

        $pagina = $this->get('/index.php', 'caja_caja_central');
        $this->assertSame(200, $pagina->status);
        $this->assertStringContainsString('data-sucursal-id="2"', $pagina->body);
    }
}
