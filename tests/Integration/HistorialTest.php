<?php

declare(strict_types=1);

namespace Patuju\Tests\Integration;

use Patuju\Tests\Support\TestCase;

final class HistorialTest extends TestCase
{
    public function testRequiresAuthentication(): void
    {
        $this->assertSame(401, $this->get('/ajax/historial.php')->status);
    }

    public function testCajaOnlySeesOwnBranch(): void
    {
        $this->createSale('caja_caja_central', 1, 1, 10);
        $this->createSale('caja_caja_lujan', 1, 1, 10);

        $this->loginAs('caja_caja_central');
        $response = $this->get('/ajax/historial.php', 'caja_caja_central');

        $this->assertSame(200, $response->status);
        $ventas = $response->json()['data'];
        $this->assertCount(1, $ventas);
        $this->assertSame(1, (int) $ventas[0]['sucursal_id']);
    }

    /**
     * URL manipulada: pedir otra sucursal o un valor inválido responde 403, sin datos.
     */
    public function testCajaCannotReadForeignBranchThroughParameter(): void
    {
        $this->createSale('caja_caja_central', 1, 1, 10);
        $this->createSale('caja_caja_lujan', 1, 1, 10);

        $this->loginAs('caja_caja_central');
        foreach (['2', '0', 'abc', '-1'] as $valor) {
            foreach (['/ajax/historial.php?sucursal_id=', '/ajax/historial.php?hoy=1&sucursal_id='] as $ruta) {
                $response = $this->get($ruta . $valor, 'caja_caja_central');
                $this->assertSame(403, $response->status, $ruta . $valor);
                $this->assertFalse($response->json()['success']);
                $this->assertArrayNotHasKey('data', $response->json());
            }
        }

        // Su propia sucursal sigue funcionando
        $propia = $this->get('/ajax/historial.php?sucursal_id=1', 'caja_caja_central');
        $this->assertSame(200, $propia->status, $propia->body);
        $this->assertCount(1, $propia->json()['data']);
    }

    public function testEncargadoSeesAllAssignedBranches(): void
    {
        $this->createSale('caja_caja_central', 1, 1, 10);
        $this->createSale('caja_caja_lujan', 1, 1, 10);
        // Venta en una sucursal NO asignada (6): no debe aparecer
        $this->createSale('caja_caja_1plan3000', 1, 1, 10);

        $this->loginAs('encargado_encargado_central');
        $response = $this->get('/ajax/historial.php', 'encargado_encargado_central');

        $this->assertSame(200, $response->status);
        $ventas = $response->json()['data'];
        $this->assertCount(2, $ventas);

        $sucursales = array_map(static fn (array $v): int => (int) $v['sucursal_id'], $ventas);
        sort($sucursales);
        $this->assertSame([1, 2], $sucursales);
    }

    public function testEncargadoCanFilterAssignedBranch(): void
    {
        $this->createSale('caja_caja_central', 1, 1, 10);
        $this->createSale('caja_caja_lujan', 1, 1, 10);
        $this->createSale('caja_caja_1plan3000', 1, 1, 10);

        $this->loginAs('encargado_encargado_central');
        $response = $this->get('/ajax/historial.php?sucursal_id=2', 'encargado_encargado_central');

        $this->assertSame(200, $response->status);
        $ventas = $response->json()['data'];
        $this->assertCount(1, $ventas);
        $this->assertSame(2, (int) $ventas[0]['sucursal_id']);
    }

    public function testEncargadoCannotLeakForeignBranchThroughParameter(): void
    {
        $this->createSale('caja_caja_central', 1, 1, 10);
        $this->createSale('caja_caja_lujan', 1, 1, 10);
        // La sucursal pedida (6) tiene ventas: si se filtrara, aparecerían
        $this->createSale('caja_caja_1plan3000', 1, 1, 10);

        $this->loginAs('encargado_encargado_central');
        // 6 = ajena, 16 = SUC-000 interna; el resto son valores manipulados
        foreach (['6', '16', '0', 'abc', '-1', '1.5'] as $valor) {
            foreach (['/ajax/historial.php?sucursal_id=', '/ajax/historial.php?hoy=1&sucursal_id='] as $ruta) {
                $response = $this->get($ruta . $valor, 'encargado_encargado_central');
                $this->assertSame(403, $response->status, $ruta . $valor);
                $this->assertFalse($response->json()['success']);
                $this->assertArrayNotHasKey('data', $response->json());
                $this->assertArrayNotHasKey('total_dia', $response->json());
            }
        }
    }

    public function testHoyAggregatesAssignedBranchesForEncargado(): void
    {
        $this->createSale('caja_caja_central', 1, 1, 10);
        $this->createSale('caja_caja_lujan', 1, 1, 10);
        // Venta de hoy en una sucursal NO asignada (6): no suma
        $this->createSale('caja_caja_1plan3000', 1, 1, 10);

        $this->loginAs('encargado_encargado_central');
        $response = $this->get('/ajax/historial.php?hoy=1', 'encargado_encargado_central');

        $this->assertSame(200, $response->status);
        $data = $response->json();
        $this->assertSame('14.00', $data['total_dia']);
        $this->assertSame(2, $data['ventas_dia']);

        // Pedir la sucursal ajena responde 403
        $ajena = $this->get('/ajax/historial.php?hoy=1&sucursal_id=6', 'encargado_encargado_central');
        $this->assertSame(403, $ajena->status);
    }

    public function testHoyForAdminAggregatesAllBranches(): void
    {
        $this->createSale('caja_caja_central', 1, 1, 10);
        $this->createSale('caja_caja_lujan', 1, 1, 10);

        $this->loginAs('admin');
        $response = $this->get('/ajax/historial.php?hoy=1', 'admin');

        $this->assertSame(200, $response->status);
        $data = $response->json();
        $this->assertSame('14.00', $data['total_dia']);
        $this->assertSame(2, $data['ventas_dia']);
    }

    /**
     * SEC-7 / C9: una caja con filas heredadas en usuario_sucursales solo ve su sucursal principal.
     */
    public function testCajaIgnoresLegacyAssignedBranches(): void
    {
        $this->createSale('caja_caja_central', 1, 1, 10);
        $this->createSale('caja_caja_1plan3000', 1, 1, 10);
        $this->db()->exec('INSERT INTO usuario_sucursales (usuario_id, sucursal_id) VALUES (5, 6), (5, 7)');

        $this->loginAs('caja_caja_central');

        $response = $this->get('/ajax/historial.php', 'caja_caja_central');
        $this->assertSame(200, $response->status, $response->body);
        $ventas = $response->json()['data'];
        $this->assertCount(1, $ventas);
        $this->assertSame(1, (int) $ventas[0]['sucursal_id']);

        $hoy = $this->get('/ajax/historial.php?hoy=1', 'caja_caja_central')->json();
        $this->assertSame('7.00', $hoy['total_dia']);
        $this->assertSame(1, $hoy['ventas_dia']);

        // Las filas heredadas no le dan acceso: pedir la 6 responde 403
        $this->assertSame(403, $this->get('/ajax/historial.php?sucursal_id=6', 'caja_caja_central')->status);
        $this->assertSame(403, $this->get('/ajax/historial.php?hoy=1&sucursal_id=6', 'caja_caja_central')->status);

        $resumen = $this->get('/ajax/stock.php?resumen_sucursales=1', 'caja_caja_central');
        $this->assertSame(200, $resumen->status, $resumen->body);
        $this->assertSame([1], array_map(static fn (array $s): int => (int) $s['id'], $resumen->json()['sucursales']));

        $this->assertSame(403, $this->get('/ajax/stock.php?sucursal_id=6', 'caja_caja_central')->status);
        $stock = $this->get('/ajax/stock.php', 'caja_caja_central');
        $this->assertSame(200, $stock->status, $stock->body);
        foreach ($stock->json()['data'] as $row) {
            $this->assertSame(1, (int) $row['sucursal_id']);
        }
    }

    public function testHoyForCajaOnlyCountsOwnBranch(): void
    {
        $this->createSale('caja_caja_central', 1, 1, 10);
        $this->createSale('caja_caja_lujan', 1, 1, 10);

        $this->loginAs('caja_caja_central');
        $response = $this->get('/ajax/historial.php?hoy=1', 'caja_caja_central');

        $this->assertSame(200, $response->status);
        $data = $response->json();
        $this->assertSame('7.00', $data['total_dia']);
        $this->assertSame(1, $data['ventas_dia']);
    }

    public function testSaleIncludesProductDetail(): void
    {
        $this->createSale('caja_caja_central', 1, 2, 20);

        $this->loginAs('caja_caja_central');
        $response = $this->get('/ajax/historial.php', 'caja_caja_central');

        $venta = $response->json()['data'][0];
        $this->assertSame('14.00', $venta['total']);
        $this->assertCount(1, $venta['detalle']);
        $this->assertSame('Salteña Grande', $venta['detalle'][0]['producto_nombre']);
        $this->assertSame(2, (int) $venta['detalle'][0]['cantidad']);
        $this->assertSame('14.00', $venta['detalle'][0]['subtotal']);
    }

    public function testFiltersByUser(): void
    {
        $this->createSale('caja_caja_central', 1, 1, 10);
        $this->createSale('caja_caja_lujan', 1, 1, 10);

        $this->loginAs('encargado_encargado_central');
        $response = $this->get('/ajax/historial.php?usuario_id=6', 'encargado_encargado_central');

        $ventas = $response->json()['data'];
        $this->assertCount(1, $ventas);
        $this->assertSame(6, (int) $ventas[0]['usuario_id']);
    }

    /**
     * La caja solo ve las ventas de hoy: las de días anteriores no se acumulan,
     * y los filtros de fecha no le abren el histórico.
     */
    public function testCajaOnlySeesTodaysSales(): void
    {
        $deAyer = $this->createSale('caja_caja_central', 1, 1, 10);
        $deHoy = $this->createSale('caja_caja_central', 2, 1, 10);
        $this->moveSaleToYesterday($deAyer);
        $ayer = (string) $this->scalar('SELECT CURDATE() - INTERVAL 1 DAY');

        $this->loginAs('caja_caja_central');
        foreach (['', "?fecha_desde={$ayer}&fecha_hasta={$ayer}", '?fecha_desde=2000-01-01'] as $query) {
            $response = $this->get('/ajax/historial.php' . $query, 'caja_caja_central');
            $this->assertSame(200, $response->status, $response->body);
            $ids = array_map(static fn (array $v): int => (int) $v['id'], $response->json()['data']);
            $this->assertSame([$deHoy], $ids, $query);
        }
    }

    /**
     * El encargado ve las ventas de hoy de todas sus cajas, nunca las de días anteriores
     * ni las de cajas que no tiene a cargo.
     */
    public function testEncargadoOnlySeesTodaysSalesOfAssignedBranches(): void
    {
        $deAyer = $this->createSale('caja_caja_central', 1, 1, 10);
        $hoySuc1 = $this->createSale('caja_caja_central', 2, 1, 10);
        $hoySuc2 = $this->createSale('caja_caja_lujan', 1, 1, 10);
        $this->createSale('caja_caja_1plan3000', 1, 1, 10); // sucursal 6, no asignada
        $this->moveSaleToYesterday($deAyer);
        $ayer = (string) $this->scalar('SELECT CURDATE() - INTERVAL 1 DAY');

        $this->loginAs('encargado_encargado_central');
        foreach (['', "?fecha_desde={$ayer}&fecha_hasta={$ayer}"] as $query) {
            $response = $this->get('/ajax/historial.php' . $query, 'encargado_encargado_central');
            $this->assertSame(200, $response->status, $response->body);
            $ids = array_map(static fn (array $v): int => (int) $v['id'], $response->json()['data']);
            sort($ids);
            $this->assertSame([$hoySuc1, $hoySuc2], $ids, $query);
        }
    }

    /**
     * El admin conserva el histórico completo y los filtros de fecha; ?solo_hoy=1 limita a hoy.
     */
    public function testAdminKeepsFullHistoryAndDateFilters(): void
    {
        $deAyer = $this->createSale('caja_caja_central', 1, 1, 10);
        $deHoy = $this->createSale('caja_caja_central', 2, 1, 10);
        $this->moveSaleToYesterday($deAyer);
        $ayer = (string) $this->scalar('SELECT CURDATE() - INTERVAL 1 DAY');

        $this->loginAs('admin');
        $idsDe = function (string $query): array {
            $response = $this->get('/ajax/historial.php' . $query, 'admin');
            $this->assertSame(200, $response->status, $response->body);
            $ids = array_map(static fn (array $v): int => (int) $v['id'], $response->json()['data']);
            sort($ids);
            return $ids;
        };

        $this->assertSame([$deAyer, $deHoy], $idsDe(''));
        $this->assertSame([$deAyer], $idsDe("?fecha_desde={$ayer}&fecha_hasta={$ayer}"));
        $this->assertSame([$deHoy], $idsDe('?solo_hoy=1'));
        // Llamada exacta de la vista de caja del admin (su caja es SUC-000, sin ventas)
        $this->assertSame([], $idsDe('?solo_hoy=1&limit=200&sucursal_id=16'));
        $this->assertSame([$deHoy], $idsDe('?solo_hoy=1&limit=200&sucursal_id=1'));
    }

    public function testNegativeLimitDoesNotCrash(): void
    {
        $this->createSale('caja_caja_central', 1, 1, 10);

        $this->loginAs('caja_caja_central');
        $response = $this->get('/ajax/historial.php?limit=-5', 'caja_caja_central');

        $this->assertSame(200, $response->status, $response->body);
        $this->assertCount(1, $response->json()['data']);
    }

    public function testLimitIsHonored(): void
    {
        $this->createSale('caja_caja_central', 1, 1, 10);
        $this->createSale('caja_caja_central', 2, 1, 10);
        $this->createSale('caja_caja_central', 3, 1, 10);

        $this->loginAs('caja_caja_central');
        $response = $this->get('/ajax/historial.php?limit=1', 'caja_caja_central');

        $this->assertCount(1, $response->json()['data']);
    }

    public function testAdminSeesAllBranches(): void
    {
        $this->createSale('caja_caja_central', 1, 1, 10);
        $this->createSale('caja_caja_lujan', 1, 1, 10);

        $this->loginAs('admin');
        $response = $this->get('/ajax/historial.php', 'admin');

        $this->assertCount(2, $response->json()['data']);
    }

    private function moveSaleToYesterday(int $ventaId): void
    {
        $this->db()->prepare('UPDATE ventas SET fecha = fecha - INTERVAL 1 DAY WHERE id = ?')->execute([$ventaId]);
    }

    private function createSale(string $user, int $productoId, int $cantidad, float $cash): int
    {
        $this->loginAs($user);

        $turnoAbierto = (int) $this->scalar(
            "SELECT COUNT(*) FROM turnos_caja tc JOIN usuarios u ON u.id = tc.usuario_id WHERE u.username = ? AND tc.estado = 'abierto'",
            [$user]
        );
        if ($turnoAbierto === 0) {
            $this->openShift($user);
        }

        $response = $this->sell([['id' => $productoId, 'cantidad' => $cantidad]], $cash, $user);
        $this->assertSame(200, $response->status, $response->body);

        return (int) $response->json()['venta_id'];
    }
}
