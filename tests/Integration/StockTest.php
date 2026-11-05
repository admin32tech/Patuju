<?php

declare(strict_types=1);

namespace Patuju\Tests\Integration;

use Patuju\Tests\Support\TestCase;

final class StockTest extends TestCase
{
    public function testStockListRequiresAuthentication(): void
    {
        $this->assertSame(401, $this->get('/ajax/stock.php')->status);
    }

    public function testStockListReturnsOnlySessionBranchForCaja(): void
    {
        $this->loginAs('caja_caja_central');

        // Pedir otra sucursal (o un valor manipulado) responde 403, sin datos
        foreach (['2', '0', 'abc'] as $valor) {
            $ajena = $this->get('/ajax/stock.php?sucursal_id=' . $valor, 'caja_caja_central');
            $this->assertSame(403, $ajena->status, $valor);
            $this->assertArrayNotHasKey('data', $ajena->json());
        }

        $response = $this->get('/ajax/stock.php', 'caja_caja_central');

        $this->assertSame(200, $response->status);
        $data = $response->json()['data'];
        $this->assertCount(16, $data);
        foreach ($data as $row) {
            $this->assertSame(1, (int) $row['sucursal_id']);
        }

        $producto1 = $this->row(
            'SELECT * FROM stock_sucursal WHERE sucursal_id = 1 AND producto_id = 1'
        );
        $this->assertNotNull($producto1);
        $this->assertSame(80, (int) $producto1['cantidad_disponible']);
    }

    public function testStockListReturnsRequestedBranchForAdmin(): void
    {
        $this->loginAs('admin');

        $response = $this->get('/ajax/stock.php?sucursal_id=3', 'admin');

        $this->assertSame(200, $response->status);
        $data = $response->json()['data'];
        $this->assertCount(16, $data);
        foreach ($data as $row) {
            $this->assertSame(3, (int) $row['sucursal_id']);
        }
    }

    public function testEncargadoCannotReadForeignBranchStock(): void
    {
        $this->loginAs('encargado_encargado_central');

        $response = $this->get('/ajax/stock.php?sucursal_id=6', 'encargado_encargado_central');

        $this->assertSame(403, $response->status);
    }

    public function testEncargadoCanReadAssignedBranchStock(): void
    {
        $this->loginAs('encargado_encargado_central');

        $response = $this->get('/ajax/stock.php?sucursal_id=3', 'encargado_encargado_central');

        $this->assertSame(200, $response->status);
        $data = $response->json()['data'];
        $this->assertCount(16, $data);
        foreach ($data as $row) {
            $this->assertSame(3, (int) $row['sucursal_id']);
        }
    }

    /**
     * SEC-1 / C7: fuera de admin, una sucursal inválida (0, 'abc', negativa) o ajena
     * es 403; nunca cae en el listado de "todas las sucursales".
     */
    public function testEncargadoCannotListWithoutValidAssignedBranch(): void
    {
        // Movimiento en la sucursal 1 (no asignada a Cochabamba): nunca debe filtrarse
        $this->loginAs('encargado_encargado_central');
        $ingreso = $this->post('/ajax/stock.php', [
            'accion' => 'ingreso_lote',
            'items' => [['producto_id' => 1, 'cantidad' => 3]],
        ], 'encargado_encargado_central');
        $this->assertSame(200, $ingreso->status, $ingreso->body);

        $this->loginAs('encargado_encargado_cochabamba');

        $rutas = [
            '/ajax/stock.php?sucursal_id=0',
            '/ajax/stock.php?sucursal_id=abc',
            '/ajax/stock.php?sucursal_id=-3',
            '/ajax/stock.php?sucursal_id=6.5', // antes (int) lo convertía en 6, su sucursal
            '/ajax/stock.php?sucursal_id=1',
            '/ajax/stock.php?movimientos=1&sucursal_id=0',
            '/ajax/stock.php?movimientos=1&sucursal_id=abc',
            '/ajax/stock.php?movimientos=1&sucursal_id=1',
        ];
        foreach ($rutas as $ruta) {
            $response = $this->get($ruta, 'encargado_encargado_cochabamba');
            $this->assertSame(403, $response->status, "{$ruta}: {$response->body}");
            $data = $response->json();
            $this->assertFalse($data['success'], $ruta);
            $this->assertArrayNotHasKey('data', $data, $ruta);
            $this->assertArrayNotHasKey('movimientos', $data, $ruta);
        }

        // Sin parámetro usa su sucursal principal (6): el movimiento de la 1 no aparece
        $propios = $this->get('/ajax/stock.php?movimientos=1', 'encargado_encargado_cochabamba');
        $this->assertSame(200, $propios->status, $propios->body);
        $this->assertSame([], $propios->json()['movimientos']);

        $listado = $this->get('/ajax/stock.php', 'encargado_encargado_cochabamba');
        $this->assertSame(200, $listado->status, $listado->body);
        foreach ($listado->json()['data'] as $row) {
            $this->assertSame(6, (int) $row['sucursal_id']);
        }
    }

    public function testCajaWithoutBranchCannotListStock(): void
    {
        $this->loginAs('caja_caja_central');
        $this->db()->exec('UPDATE usuarios SET sucursal_id = NULL WHERE id = 5');

        $response = $this->get('/ajax/stock.php', 'caja_caja_central');

        $this->assertSame(403, $response->status, $response->body);
        $this->assertArrayNotHasKey('data', $response->json());
    }

    public function testAdminWithoutBranchFilterSeesAllBranches(): void
    {
        $this->loginAs('admin');

        // Igual que admin.js con "Todas las sucursales": sin parámetro. La sesión del
        // admin trae SUC-000 y no debe usarse como filtro implícito.
        foreach (['/ajax/stock.php', '/ajax/stock.php?sucursal_id='] as $ruta) {
            $response = $this->get($ruta, 'admin');

            $this->assertSame(200, $response->status, $response->body);
            $sucursales = array_unique(array_map(
                static fn (array $row): int => (int) $row['sucursal_id'],
                $response->json()['data']
            ));
            $this->assertCount(16, $sucursales, $ruta);
        }

        $movimientos = $this->get('/ajax/stock.php?movimientos=1', 'admin');
        $this->assertSame(200, $movimientos->status, $movimientos->body);

        // Un valor manipulado no es "todas": responde 403 también para el admin
        foreach (['0', 'abc', '1.5'] as $valor) {
            $this->assertSame(403, $this->get('/ajax/stock.php?sucursal_id=' . $valor, 'admin')->status, $valor);
        }
    }

    public function testIngresoLoteSumsStockAndLogsMovements(): void
    {
        $this->loginAs('encargado_encargado_central');

        $response = $this->post('/ajax/stock.php', [
            'accion' => 'ingreso_lote',
            'items' => [
                ['producto_id' => 1, 'cantidad' => 10],
                ['producto_id' => 6, 'cantidad' => 5],
            ],
            'motivo' => 'Horneada de prueba',
        ], 'encargado_encargado_central');

        $this->assertSame(200, $response->status, $response->body);
        $this->assertStringContainsString('15 unidades', $response->json()['message']);
        $this->assertSame(90, $this->stock(1, 1));
        $this->assertSame(55, $this->stock(1, 6));

        $movimientos = $this->rows(
            "SELECT * FROM stock_movimientos WHERE sucursal_id = 1 AND tipo_movimiento = 'ingreso_lote' ORDER BY producto_id"
        );
        $this->assertCount(2, $movimientos);
        $this->assertSame(10, (int) $movimientos[0]['cantidad']);
        $this->assertSame(80, (int) $movimientos[0]['stock_anterior']);
        $this->assertSame(90, (int) $movimientos[0]['stock_posterior']);
        $this->assertSame('Horneada de prueba', $movimientos[0]['motivo']);
    }

    public function testIngresoLoteRejectsEmptyItems(): void
    {
        $this->loginAs('encargado_encargado_central');

        $response = $this->post('/ajax/stock.php', [
            'accion' => 'ingreso_lote',
            'items' => [],
        ], 'encargado_encargado_central');

        $this->assertSame(400, $response->status);
    }

    public function testIngresoLoteRejectsInvalidItems(): void
    {
        $this->loginAs('encargado_encargado_central');

        $response = $this->post('/ajax/stock.php', [
            'accion' => 'ingreso_lote',
            'items' => [
                ['producto_id' => 1, 'cantidad' => 0],
                ['producto_id' => 0, 'cantidad' => 5],
            ],
        ], 'encargado_encargado_central');

        $this->assertSame(400, $response->status);
        $this->assertSame(80, $this->stock(1, 1));
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM stock_movimientos'));
    }

    public function testIngresoLoteRejectsUnknownProduct(): void
    {
        $this->loginAs('encargado_encargado_central');

        $response = $this->post('/ajax/stock.php', [
            'accion' => 'ingreso_lote',
            'items' => [['producto_id' => 9999, 'cantidad' => 5]],
        ], 'encargado_encargado_central');

        $this->assertSame(400, $response->status);
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM stock_movimientos'));
    }

    public function testIngresoLoteRejectsForeignBranch(): void
    {
        $this->loginAs('encargado_encargado_central');

        $response = $this->post('/ajax/stock.php', [
            'accion' => 'ingreso_lote',
            'sucursal_id' => 6,
            'items' => [['producto_id' => 1, 'cantidad' => 5]],
        ], 'encargado_encargado_central');

        $this->assertSame(403, $response->status);
        $this->assertSame(80, $this->stock(1, 1));
    }

    /**
     * URL/cuerpo manipulado: un sucursal_id inválido en un POST no cae en la sucursal
     * de la sesión, responde 403 y no toca el stock.
     */
    public function testStockPostRejectsTamperedBranch(): void
    {
        $this->loginAs('encargado_encargado_central');

        foreach ([0, '0', 'abc', -1, '1.5', 16] as $valor) {
            $response = $this->post('/ajax/stock.php', [
                'accion' => 'ingreso_lote',
                'sucursal_id' => $valor,
                'items' => [['producto_id' => 1, 'cantidad' => 5]],
            ], 'encargado_encargado_central');

            $this->assertSame(403, $response->status, var_export($valor, true) . ' ' . $response->body);
        }

        $this->assertSame(80, $this->stock(1, 1));
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM stock_movimientos'));
    }

    public function testAjusteUpdatesStockPriceAlertAndPredeterminado(): void
    {
        $this->loginAs('encargado_encargado_central');

        $response = $this->post('/ajax/stock.php', [
            'accion' => 'ajuste',
            'producto_id' => 2,
            'cantidad' => 25,
            'precio_sucursal' => 9.75,
            'alerta_minima' => 5,
            'stock_predeterminado' => 15,
            'motivo' => 'Ajuste de prueba',
        ], 'encargado_encargado_central');

        $this->assertSame(200, $response->status, $response->body);

        $row = $this->row('SELECT * FROM stock_sucursal WHERE sucursal_id = 1 AND producto_id = 2');
        $this->assertNotNull($row);
        $this->assertSame(25, (int) $row['cantidad_disponible']);
        $this->assertSame('9.75', $row['precio_sucursal']);
        $this->assertSame(5, (int) $row['alerta_minima']);
        $this->assertSame(15, (int) $row['stock_predeterminado']);

        $movimiento = $this->row("SELECT * FROM stock_movimientos WHERE tipo_movimiento = 'ajuste_manual'");
        $this->assertNotNull($movimiento);
        $this->assertSame(-35, (int) $movimiento['cantidad']);
        $this->assertSame(60, (int) $movimiento['stock_anterior']);
        $this->assertSame(25, (int) $movimiento['stock_posterior']);
    }

    public function testAjusteRejectsNegativeQuantity(): void
    {
        $this->loginAs('encargado_encargado_central');

        $response = $this->post('/ajax/stock.php', [
            'accion' => 'ajuste',
            'producto_id' => 1,
            'cantidad' => -5,
        ], 'encargado_encargado_central');

        $this->assertSame(400, $response->status);
        $this->assertSame(80, $this->stock(1, 1));
    }

    public function testAjusteRejectsZeroPrice(): void
    {
        $this->loginAs('encargado_encargado_central');

        $response = $this->post('/ajax/stock.php', [
            'accion' => 'ajuste',
            'producto_id' => 1,
            'precio_sucursal' => 0,
        ], 'encargado_encargado_central');

        $this->assertSame(400, $response->status);
    }

    public function testAjusteRejectsUnknownProduct(): void
    {
        $this->loginAs('encargado_encargado_central');

        $response = $this->post('/ajax/stock.php', [
            'accion' => 'ajuste',
            'producto_id' => 9999,
            'cantidad' => 10,
        ], 'encargado_encargado_central');

        $this->assertSame(400, $response->status);
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM stock_movimientos'));
    }

    public function testAjustePriceOnlyDoesNotLogMovement(): void
    {
        $this->loginAs('encargado_encargado_central');

        $response = $this->post('/ajax/stock.php', [
            'accion' => 'ajuste',
            'producto_id' => 1,
            'precio_sucursal' => 8.50,
        ], 'encargado_encargado_central');

        $this->assertSame(200, $response->status, $response->body);
        $this->assertSame(80, $this->stock(1, 1));
        $this->assertSame('8.50', $this->scalar('SELECT precio_sucursal FROM stock_sucursal WHERE sucursal_id = 1 AND producto_id = 1'));
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM stock_movimientos'));
    }

    public function testCambiarDisponibilidadPausesProduct(): void
    {
        $this->loginAs('encargado_encargado_central');

        $response = $this->post('/ajax/stock.php', [
            'accion' => 'cambiar_disponibilidad',
            'producto_id' => 1,
            'disponible_venta' => 0,
        ], 'encargado_encargado_central');

        $this->assertSame(200, $response->status, $response->body);
        $this->assertSame(0, (int) $this->scalar('SELECT disponible_venta FROM stock_sucursal WHERE sucursal_id = 1 AND producto_id = 1'));
    }

    public function testCambiarDisponibilidadRejectsUnknownProduct(): void
    {
        $this->loginAs('encargado_encargado_central');

        $response = $this->post('/ajax/stock.php', [
            'accion' => 'cambiar_disponibilidad',
            'producto_id' => 9999,
            'disponible_venta' => 0,
        ], 'encargado_encargado_central');

        $this->assertSame(400, $response->status);
    }

    public function testCargarPredeterminadoDiarioAddsConfiguredDailyStock(): void
    {
        $this->loginAs('encargado_encargado_central');
        $this->db()->exec('UPDATE stock_sucursal SET disponible_venta = 0 WHERE sucursal_id = 1 AND producto_id = 1');

        $response = $this->post('/ajax/stock.php', [
            'accion' => 'cargar_predeterminado_diario',
        ], 'encargado_encargado_central');

        $this->assertSame(200, $response->status, $response->body);
        $this->assertStringContainsString('250 unidades', $response->json()['message']);

        $this->assertSame(160, $this->stock(1, 1));
        $this->assertSame(120, $this->stock(1, 2));
        $this->assertSame(80, $this->stock(1, 3));
        $this->assertSame(80, $this->stock(1, 4));
        $this->assertSame(60, $this->stock(1, 5));
        $this->assertSame(50, $this->stock(1, 6));
        $this->assertSame(1, (int) $this->scalar('SELECT disponible_venta FROM stock_sucursal WHERE sucursal_id = 1 AND producto_id = 1'));

        $this->assertSame(5, (int) $this->scalar("SELECT COUNT(*) FROM stock_movimientos WHERE tipo_movimiento = 'ingreso_lote'"));
    }

    public function testCierreMermaDiariaZeroesDailyProducts(): void
    {
        $this->loginAs('encargado_encargado_central');

        $response = $this->post('/ajax/stock.php', [
            'accion' => 'cierre_merma_diaria',
        ], 'encargado_encargado_central');

        $this->assertSame(200, $response->status, $response->body);
        $this->assertStringContainsString('250 unidades', $response->json()['message']);

        foreach ([1, 2, 3, 4, 5] as $productoId) {
            $this->assertSame(0, $this->stock(1, $productoId));
        }
        $this->assertSame(50, $this->stock(1, 6));

        $movimientos = $this->rows("SELECT * FROM stock_movimientos WHERE tipo_movimiento = 'merma' ORDER BY producto_id");
        $this->assertCount(5, $movimientos);
        $this->assertSame(-80, (int) $movimientos[0]['cantidad']);
        $this->assertSame(0, (int) $movimientos[0]['stock_posterior']);
    }

    public function testMovimientosListsRecentBranchMovements(): void
    {
        $this->loginAs('encargado_encargado_central');
        $this->post('/ajax/stock.php', [
            'accion' => 'ingreso_lote',
            'items' => [['producto_id' => 1, 'cantidad' => 3]],
        ], 'encargado_encargado_central');

        $response = $this->get('/ajax/stock.php?movimientos=1', 'encargado_encargado_central');

        $this->assertSame(200, $response->status);
        $movimientos = $response->json()['movimientos'];
        $this->assertCount(1, $movimientos);
        $this->assertSame('Salteña Grande', $movimientos[0]['producto_nombre']);
    }

    public function testUnknownStockActionReturnsBadRequest(): void
    {
        $this->loginAs('encargado_encargado_central');

        $response = $this->post('/ajax/stock.php', [
            'accion' => 'inexistente',
        ], 'encargado_encargado_central');

        $this->assertSame(400, $response->status);
    }
}
