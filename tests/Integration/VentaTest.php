<?php

declare(strict_types=1);

namespace Patuju\Tests\Integration;

use Patuju\Tests\Support\TestCase;

final class VentaTest extends TestCase
{
    public function testSaleRequiresAuthentication(): void
    {
        $response = $this->sell([['id' => 1, 'cantidad' => 1]], 10);

        $this->assertSame(401, $response->status);
    }

    public function testSaleRejectsGetMethod(): void
    {
        $this->loginAs('caja_caja_central');

        $response = $this->get('/ajax/registrar_venta.php', 'caja_caja_central');

        $this->assertSame(405, $response->status);
    }

    public function testSaleRequiresOpenShift(): void
    {
        $this->loginAs('caja_caja_central');

        $response = $this->sell([['id' => 1, 'cantidad' => 1]], 10);

        $this->assertSame(400, $response->status);
        $this->assertStringContainsString('turno de caja abierto', $response->json()['error']);
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM ventas'));
    }

    public function testSaleDecrementsStockAndRecordsAuditTrail(): void
    {
        $this->loginAs('caja_caja_central');
        $this->openShift();

        $response = $this->sell([
            ['id' => 1, 'cantidad' => 2],
            ['id' => 6, 'cantidad' => 1],
        ], 30);

        $this->assertSame(200, $response->status, $response->body);
        $data = $response->json();
        $this->assertTrue($data['success']);
        $this->assertSame('19.00', $data['total']);
        $this->assertSame('11.00', $data['cambio']);
        $this->assertSame(3, $data['items']);

        $this->assertSame(78, $this->stock(1, 1));
        $this->assertSame(49, $this->stock(1, 6));

        $venta = $this->row('SELECT * FROM ventas WHERE id = ?', [$data['venta_id']]);
        $this->assertNotNull($venta);
        $this->assertSame('19.00', $venta['total']);
        $this->assertSame(3, (int) $venta['items_count']);
        $this->assertSame(1, (int) $venta['sucursal_id']);
        $this->assertSame(5, (int) $venta['usuario_id']);
        $this->assertSame('efectivo', $venta['metodo_pago']);
        $this->assertSame('30.00', $venta['monto_recibido']);
        $this->assertSame('11.00', $venta['cambio']);
        $this->assertNotNull($venta['turno_id']);

        $detalle = $this->rows('SELECT * FROM detalle_ventas WHERE venta_id = ? ORDER BY producto_id', [$data['venta_id']]);
        $this->assertCount(2, $detalle);
        $this->assertSame(2, (int) $detalle[0]['cantidad']);
        $this->assertSame('7.00', $detalle[0]['precio_unitario']);
        $this->assertSame('14.00', $detalle[0]['subtotal']);

        $movimientos = $this->rows(
            "SELECT * FROM stock_movimientos WHERE sucursal_id = 1 AND tipo_movimiento = 'venta' ORDER BY producto_id"
        );
        $this->assertCount(2, $movimientos);
        $this->assertSame(-2, (int) $movimientos[0]['cantidad']);
        $this->assertSame(80, (int) $movimientos[0]['stock_anterior']);
        $this->assertSame(78, (int) $movimientos[0]['stock_posterior']);
    }

    public function testSaleUsesBranchSpecificPriceWhenConfigured(): void
    {
        $this->loginAs('caja_caja_central');
        $this->openShift();
        $this->db()->exec('UPDATE stock_sucursal SET precio_sucursal = 9.50 WHERE sucursal_id = 1 AND producto_id = 1');

        $response = $this->sell([['id' => 1, 'cantidad' => 1]], 10);

        $this->assertSame(200, $response->status, $response->body);
        $data = $response->json();
        $this->assertSame('9.50', $data['total']);
        $this->assertSame('0.50', $data['cambio']);
    }

    public function testSaleRejectsInsufficientCash(): void
    {
        $this->loginAs('caja_caja_central');
        $this->openShift();

        $response = $this->sell([['id' => 1, 'cantidad' => 1]], 5);

        $this->assertSame(400, $response->status);
        $this->assertStringContainsString('insuficiente', $response->json()['error']);
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM ventas'));
        $this->assertSame(80, $this->stock(1, 1));
    }

    public function testSaleRejectsInsufficientStock(): void
    {
        $this->loginAs('caja_caja_central');
        $this->openShift();

        $response = $this->sell([['id' => 5, 'cantidad' => 31]], 200);

        $this->assertSame(400, $response->status);
        $this->assertStringContainsString('Stock insuficiente', $response->json()['error']);
        $this->assertSame(30, $this->stock(1, 5));
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM ventas'));
    }

    public function testSaleRejectsInactiveProduct(): void
    {
        $this->loginAs('caja_caja_central');
        $this->openShift();
        $this->db()->exec('UPDATE productos SET activo = 0 WHERE id = 2');

        $response = $this->sell([['id' => 2, 'cantidad' => 1]], 10);

        $this->assertSame(400, $response->status);
        $this->assertStringContainsString('no está habilitado', $response->json()['error']);
        $this->assertSame(60, $this->stock(1, 2));
    }

    public function testSaleRejectsProductPausedForSale(): void
    {
        $this->loginAs('caja_caja_central');
        $this->openShift();
        $this->db()->exec('UPDATE stock_sucursal SET disponible_venta = 0 WHERE sucursal_id = 1 AND producto_id = 1');

        $response = $this->sell([['id' => 1, 'cantidad' => 1]], 10);

        $this->assertSame(400, $response->status);
        $this->assertSame(80, $this->stock(1, 1));
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM ventas'));
    }

    public function testSaleRejectsDuplicateLinesThatExceedStockTogether(): void
    {
        $this->loginAs('caja_caja_central');
        $this->openShift();

        $response = $this->sell([
            ['id' => 5, 'cantidad' => 20],
            ['id' => 5, 'cantidad' => 20],
        ], 200);

        $this->assertSame(400, $response->status);
        $this->assertStringContainsString('Stock insuficiente', $response->json()['error']);
        $this->assertSame(30, $this->stock(1, 5));
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM ventas'));
    }

    public function testSaleOnlyAffectsSessionBranch(): void
    {
        $this->loginAs('caja_caja_central');
        $this->openShift();

        $response = $this->sell([['id' => 1, 'cantidad' => 3]], 30);
        $this->assertSame(200, $response->status, $response->body);

        $this->assertSame(77, $this->stock(1, 1));
        $this->assertSame(80, $this->stock(2, 1));
    }

    public function testSaleRequiresShiftOfTheSameUser(): void
    {
        $this->loginAs('caja_caja_central');
        $this->openShift();

        $this->loginAs('caja_caja_lujan');
        $response = $this->sell([['id' => 1, 'cantidad' => 1]], 10, 'caja_caja_lujan');

        $this->assertSame(400, $response->status);
        $this->assertStringContainsString('turno de caja abierto', $response->json()['error']);
        $this->assertSame(80, $this->stock(2, 1));
    }

    public function testQrSaleRegistersExactTotalWithoutChange(): void
    {
        $this->loginAs('caja_caja_central');
        $this->openShift();

        $response = $this->sell([['id' => 1, 'cantidad' => 1]], 0, 'caja_caja_central', 'qr');

        $this->assertSame(200, $response->status, $response->body);
        $data = $response->json();
        $this->assertSame('7.00', $data['total']);
        $this->assertSame('7.00', $data['monto_recibido']);
        $this->assertSame('0.00', $data['cambio']);
    }

    public function testSaleRejectsEmptyCart(): void
    {
        $this->loginAs('caja_caja_central');
        $this->openShift();

        $response = $this->post('/ajax/registrar_venta.php', ['items' => []], 'caja_caja_central');

        $this->assertSame(400, $response->status);
        $this->assertSame('No hay productos en la venta', $response->json()['error']);
    }

    public function testSaleRejectsInvalidLines(): void
    {
        $this->loginAs('caja_caja_central');
        $this->openShift();

        $response = $this->sell([
            ['id' => 0, 'cantidad' => 3],
            ['id' => 1, 'cantidad' => 0],
        ], 10);

        $this->assertSame(400, $response->status);
        $this->assertSame('No se encontraron productos válidos para procesar.', $response->json()['error']);
    }

    public function testSaleRejectsUnknownProduct(): void
    {
        $this->loginAs('caja_caja_central');
        $this->openShift();

        $response = $this->sell([['id' => 9999, 'cantidad' => 1]], 10);

        $this->assertSame(400, $response->status);
        $this->assertStringContainsString('no está habilitado', $response->json()['error']);
    }
}
