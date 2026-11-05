<?php

declare(strict_types=1);

namespace Patuju\Tests\Integration;

use Patuju\Tests\Support\TestCase;

final class AdminSyncTest extends TestCase
{
    public function testRequiresAuthentication(): void
    {
        $this->assertSame(401, $this->get('/ajax/admin_sync.php?action=validate')->status);
    }

    public function testRejectsPostMethod(): void
    {
        $this->loginAs('admin');

        $response = $this->post('/ajax/admin_sync.php?action=validate', [], 'admin');

        $this->assertSame(405, $response->status);
    }

    public function testAdminSeesLastSaleAcrossAllBranches(): void
    {
        $this->createDatedSale('caja_caja_central', '2026-01-01 10:00:00');
        $this->createDatedSale('caja_caja_lujan', '2026-01-02 10:00:00');

        $this->loginAs('admin');
        $response = $this->get('/ajax/admin_sync.php?action=validate', 'admin');

        $this->assertSame(200, $response->status);
        $data = $response->json();
        $this->assertSame('ok', $data['status']);
        $this->assertSame('2026-01-02 10:00:00', $data['last_change']);
        $this->assertSame(strtotime('2026-01-02 10:00:00'), $data['timestamp']);
    }

    public function testEncargadoSeesLastSaleAcrossAssignedBranches(): void
    {
        $this->createDatedSale('caja_caja_central', '2026-01-01 10:00:00');
        $this->createDatedSale('caja_caja_lujan', '2026-01-02 10:00:00');
        // Venta más reciente en una sucursal NO asignada (6): no debe contar
        $this->createDatedSale('caja_caja_1plan3000', '2026-01-03 10:00:00');

        $this->loginAs('encargado_encargado_central');
        $response = $this->get('/ajax/admin_sync.php?action=validate', 'encargado_encargado_central');

        $this->assertSame(200, $response->status);
        $this->assertSame('2026-01-02 10:00:00', $response->json()['last_change']);
    }

    public function testCajaOnlySeesOwnBranch(): void
    {
        $this->createDatedSale('caja_caja_central', '2026-01-01 10:00:00');
        $this->createDatedSale('caja_caja_lujan', '2026-01-02 10:00:00');

        $this->loginAs('caja_caja_central');
        $central = $this->get('/ajax/admin_sync.php?action=validate', 'caja_caja_central');
        $this->assertSame('2026-01-01 10:00:00', $central->json()['last_change']);

        $this->loginAs('caja_caja_lujan');
        $lujan = $this->get('/ajax/admin_sync.php?action=validate', 'caja_caja_lujan');
        $this->assertSame('2026-01-02 10:00:00', $lujan->json()['last_change']);
    }

    public function testWithoutSalesReturnsNullAndZero(): void
    {
        $this->loginAs('admin');

        $response = $this->get('/ajax/admin_sync.php?action=validate', 'admin');

        $this->assertSame(200, $response->status);
        $data = $response->json();
        $this->assertNull($data['last_change']);
        $this->assertSame(0, $data['timestamp']);
    }

    private function createDatedSale(string $user, string $fecha): void
    {
        $this->loginAs($user);
        $this->openShift($user);
        $response = $this->sell([['id' => 1, 'cantidad' => 1]], 10, $user);
        $this->assertSame(200, $response->status, $response->body);

        $ventaId = (int) $response->json()['venta_id'];
        $stmt = $this->db()->prepare('UPDATE ventas SET fecha = ? WHERE id = ?');
        $stmt->execute([$fecha, $ventaId]);
    }
}
