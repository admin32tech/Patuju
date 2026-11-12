<?php

declare(strict_types=1);

namespace Patuju\Tests\Integration;

use Patuju\Tests\Support\TestCase;

final class RolesAccessTest extends TestCase
{
    public function testCajaCannotAccessAdminEndpoints(): void
    {
        $this->loginAs('caja_caja_central');

        $this->assertSame(403, $this->get('/ajax/analytics.php', 'caja_caja_central')->status);
        $this->assertSame(403, $this->get('/ajax/usuarios.php', 'caja_caja_central')->status);
    }

    public function testEncargadoCannotAccessAdminOnlyEndpoints(): void
    {
        $this->loginAs('encargado_encargado_central');

        $this->assertSame(403, $this->get('/ajax/usuarios.php', 'encargado_encargado_central')->status);
    }

    public function testCajaCannotModifyStock(): void
    {
        $this->loginAs('caja_caja_central');

        $response = $this->post('/ajax/stock.php', [
            'accion' => 'ajuste',
            'producto_id' => 1,
            'cantidad' => 1,
        ], 'caja_caja_central');

        $this->assertSame(403, $response->status);
        $this->assertSame(80, $this->stock(1, 1));
    }

    public function testCajaCannotModifyProducts(): void
    {
        $this->loginAs('caja_caja_central');

        $create = $this->post('/ajax/crud_productos.php', [
            'nombre' => 'Intruso',
            'precio' => 1,
            'categoria_id' => 1,
        ], 'caja_caja_central');
        $this->assertSame(403, $create->status);

        $update = $this->put('/ajax/crud_productos.php?id=1', [
            'nombre' => 'Hackeado',
            'precio' => 1,
            'categoria_id' => 1,
        ], 'caja_caja_central');
        $this->assertSame(403, $update->status);

        $delete = $this->delete('/ajax/crud_productos.php?id=1', 'caja_caja_central');
        $this->assertSame(403, $delete->status);

        $this->assertSame(1, (int) $this->scalar('SELECT activo FROM productos WHERE id = 1'));
    }

    public function testCajaCannotAccessAdminPage(): void
    {
        $this->loginAs('caja_caja_central');

        $response = $this->get('/admin.php', 'caja_caja_central');

        $this->assertSame(302, $response->status);
        $this->assertStringContainsString('login.php?error=sin_permisos', (string) $response->header('location'));
    }

    public function testEncargadoCannotAccessAdminPage(): void
    {
        $this->loginAs('encargado_encargado_central');

        $response = $this->get('/admin.php', 'encargado_encargado_central');

        $this->assertSame(302, $response->status);
        $this->assertStringContainsString('login.php?error=sin_permisos', (string) $response->header('location'));
    }

    public function testCajaCannotAccessEncargadoPage(): void
    {
        $this->loginAs('caja_caja_central');

        $response = $this->get('/encargado.php', 'caja_caja_central');

        $this->assertSame(302, $response->status);
        $this->assertStringContainsString('login.php?error=sin_permisos', (string) $response->header('location'));
    }

    public function testAdminCanAccessAllViews(): void
    {
        $this->loginAs('admin');

        $this->assertSame(200, $this->get('/admin.php', 'admin')->status);
        $this->assertSame(200, $this->get('/encargado.php', 'admin')->status);
        $this->assertSame(200, $this->get('/index.php', 'admin')->status);
    }

    public function testEncargadoCanAccessCajaView(): void
    {
        $this->loginAs('encargado_encargado_central');

        $this->assertSame(200, $this->get('/index.php', 'encargado_encargado_central')->status);
    }

    public function testUnauthenticatedPageRedirectsToLogin(): void
    {
        $response = $this->get('/index.php');

        $this->assertSame(302, $response->status);
        $this->assertStringContainsString('login.php', (string) $response->header('location'));
    }

    public function testEncargadoCanManageAssignedBranchButNotOthers(): void
    {
        $this->loginAs('encargado_encargado_central');

        $allowed = $this->get('/ajax/stock.php?sucursal_id=2', 'encargado_encargado_central');
        $this->assertSame(200, $allowed->status, $allowed->body);

        $forbidden = $this->get('/ajax/stock.php?sucursal_id=6', 'encargado_encargado_central');
        $this->assertSame(403, $forbidden->status);

        $adjust = $this->post('/ajax/stock.php', [
            'accion' => 'ajuste',
            'sucursal_id' => 2,
            'producto_id' => 1,
            'cantidad' => 5,
        ], 'encargado_encargado_central');
        $this->assertSame(200, $adjust->status, $adjust->body);
        // El ajuste cae en la sucursal pedida (2), no en la principal de la sesión (1)
        $this->assertSame(5, $this->stock(2, 1));
        $this->assertSame(80, $this->stock(1, 1));

        $denied = $this->post('/ajax/stock.php', [
            'accion' => 'ajuste',
            'sucursal_id' => 6,
            'producto_id' => 1,
            'cantidad' => 5,
        ], 'encargado_encargado_central');
        $this->assertSame(403, $denied->status);
        $this->assertSame(80, $this->stock(6, 1));
    }

    public function testEncargadoResumenOnlyShowsAssignedBranches(): void
    {
        $this->loginAs('encargado_encargado_central');

        $response = $this->get('/ajax/stock.php?resumen_sucursales=1', 'encargado_encargado_central');
        $this->assertSame(200, $response->status);

        $data = $response->json();
        $ids = array_map(static fn (array $s): int => (int) $s['id'], $data['sucursales']);

        $this->assertSame([1, 2, 3, 4, 5], $ids);
        $this->assertCount(count(array_unique($ids)), $ids, 'No debe haber sucursales duplicadas');
    }

    public function testAdminResumenShowsAllActiveBranchesWithoutDuplicates(): void
    {
        $this->loginAs('admin');

        $response = $this->get('/ajax/stock.php?resumen_sucursales=1', 'admin');
        $this->assertSame(200, $response->status);

        $data = $response->json();
        $ids = array_map(static fn (array $s): int => (int) $s['id'], $data['sucursales']);

        $this->assertCount(16, $ids);
        $this->assertCount(count(array_unique($ids)), $ids, 'No debe haber sucursales duplicadas');
    }
}
