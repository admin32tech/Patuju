<?php

declare(strict_types=1);

namespace Patuju\Tests\Integration;

use Patuju\Tests\Support\TestCase;

final class TurnoCajaTest extends TestCase
{
    public function testGetReturnsNullWhenNoShiftIsOpen(): void
    {
        $this->loginAs('caja_caja_central');

        $response = $this->get('/ajax/turno_caja.php', 'caja_caja_central');

        $this->assertSame(200, $response->status);
        $data = $response->json();
        $this->assertTrue($data['success']);
        $this->assertNull($data['turno']);
    }

    public function testOpenShiftRejectsNegativeAmount(): void
    {
        $this->loginAs('caja_caja_central');

        $response = $this->post('/ajax/turno_caja.php', [
            'accion' => 'abrir',
            'monto_apertura' => -1,
        ], 'caja_caja_central');

        $this->assertSame(400, $response->status);
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM turnos_caja'));
    }

    public function testOpenShiftCreatesOpenShift(): void
    {
        $this->loginAs('caja_caja_central');

        $data = $this->openShift('caja_caja_central', 100);

        $turno = $this->row('SELECT * FROM turnos_caja WHERE id = ?', [$data['turno_id']]);
        $this->assertNotNull($turno);
        $this->assertSame(5, (int) $turno['usuario_id']);
        $this->assertSame(1, (int) $turno['sucursal_id']);
        $this->assertSame('100.00', $turno['monto_apertura']);
        $this->assertSame('abierto', $turno['estado']);
        $this->assertNull($turno['hora_cierre']);
    }

    public function testCannotOpenTwoShiftsForSameUserAndBranch(): void
    {
        $this->loginAs('caja_caja_central');
        $this->openShift();

        $response = $this->post('/ajax/turno_caja.php', [
            'accion' => 'abrir',
            'monto_apertura' => 50,
        ], 'caja_caja_central');

        $this->assertSame(409, $response->status);
        $this->assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM turnos_caja'));
    }

    public function testEgresoRequiresOpenShift(): void
    {
        $this->loginAs('caja_caja_lujan');

        $response = $this->post('/ajax/turno_caja.php', [
            'accion' => 'egreso',
            'monto' => 5,
            'motivo' => 'Prueba',
        ], 'caja_caja_lujan');

        $this->assertSame(404, $response->status);
    }

    public function testEgresoValidatesAmountAndReason(): void
    {
        $this->loginAs('caja_caja_central');
        $this->openShift();

        $zero = $this->post('/ajax/turno_caja.php', [
            'accion' => 'egreso',
            'monto' => 0,
            'motivo' => 'Prueba',
        ], 'caja_caja_central');
        $this->assertSame(400, $zero->status);

        $noReason = $this->post('/ajax/turno_caja.php', [
            'accion' => 'egreso',
            'monto' => 5,
            'motivo' => '',
        ], 'caja_caja_central');
        $this->assertSame(400, $noReason->status);
    }

    public function testEgresoUpdatesShiftTotals(): void
    {
        $this->loginAs('caja_caja_central');
        $this->openShift();

        $response = $this->post('/ajax/turno_caja.php', [
            'accion' => 'egreso',
            'monto' => 10,
            'motivo' => 'Compra de servilletas',
        ], 'caja_caja_central');

        $this->assertSame(200, $response->status, $response->body);
        $this->assertSame('10.00', $this->scalar('SELECT egresos_menores FROM turnos_caja LIMIT 1'));
        $this->assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM egresos_caja'));
    }

    public function testCorteXComputesExpectedCash(): void
    {
        $this->loginAs('caja_caja_central');
        $this->openShift('caja_caja_central', 100);
        $this->sell([['id' => 1, 'cantidad' => 1]], 10);
        $this->post('/ajax/turno_caja.php', [
            'accion' => 'egreso',
            'monto' => 10,
            'motivo' => 'Gasto',
        ], 'caja_caja_central');

        $response = $this->post('/ajax/turno_caja.php', ['accion' => 'corte_x'], 'caja_caja_central');

        $this->assertSame(200, $response->status, $response->body);
        $data = $response->json();
        $this->assertSame('corte_x', $data['tipo']);
        $this->assertSame(100.0, (float) $data['monto_apertura']);
        $this->assertSame(7.0, (float) $data['ventas_sistema']);
        $this->assertSame(1, (int) $data['num_ventas']);
        $this->assertSame(10.0, (float) $data['egresos']);
        $this->assertSame(97.0, (float) $data['efectivo_esperado']);
    }

    public function testCorteXRequiresOpenShift(): void
    {
        $this->loginAs('caja_caja_lujan');

        $response = $this->post('/ajax/turno_caja.php', ['accion' => 'corte_x'], 'caja_caja_lujan');

        $this->assertSame(404, $response->status);
    }

    public function testCloseShiftComputesBalancedCorteZ(): void
    {
        $this->loginAs('caja_caja_central');
        $this->openShift('caja_caja_central', 100);
        $this->sell([['id' => 1, 'cantidad' => 1]], 10);
        $this->post('/ajax/turno_caja.php', [
            'accion' => 'egreso',
            'monto' => 10,
            'motivo' => 'Gasto',
        ], 'caja_caja_central');

        $response = $this->post('/ajax/turno_caja.php', [
            'accion' => 'cerrar',
            'monto_cierre' => 97,
            'notas' => 'Cierre de prueba',
        ], 'caja_caja_central');

        $this->assertSame(200, $response->status, $response->body);
        $data = $response->json();
        $this->assertSame('corte_z', $data['tipo']);
        $this->assertSame(7.0, (float) $data['ventas_sistema']);
        $this->assertSame(97.0, (float) $data['efectivo_esperado']);
        $this->assertSame(0.0, (float) $data['diferencia']);
        $this->assertSame('cuadrado', $data['estado']);

        $turno = $this->row('SELECT * FROM turnos_caja LIMIT 1');
        $this->assertNotNull($turno);
        $this->assertSame('cerrado', $turno['estado']);
        $this->assertSame('97.00', $turno['monto_cierre']);
        $this->assertSame('7.00', $turno['ventas_sistema']);
        $this->assertSame('0.00', $turno['diferencia']);
        $this->assertNotNull($turno['hora_cierre']);
    }

    public function testCloseShiftReportsShortage(): void
    {
        $this->loginAs('caja_caja_central');
        $this->openShift('caja_caja_central', 100);
        $this->sell([['id' => 1, 'cantidad' => 1]], 10);

        $response = $this->post('/ajax/turno_caja.php', [
            'accion' => 'cerrar',
            'monto_cierre' => 90,
        ], 'caja_caja_central');

        $this->assertSame(200, $response->status, $response->body);
        $data = $response->json();
        $this->assertSame(-17.0, (float) $data['diferencia']);
        $this->assertSame('faltante', $data['estado']);
    }

    public function testCloseShiftRequiresAmount(): void
    {
        $this->loginAs('caja_caja_central');
        $this->openShift();

        $response = $this->post('/ajax/turno_caja.php', ['accion' => 'cerrar'], 'caja_caja_central');

        $this->assertSame(400, $response->status);
        $this->assertSame('abierto', $this->scalar('SELECT estado FROM turnos_caja LIMIT 1'));
    }

    public function testCannotSellAfterClosingShift(): void
    {
        $this->loginAs('caja_caja_central');
        $this->openShift();
        $this->post('/ajax/turno_caja.php', [
            'accion' => 'cerrar',
            'monto_cierre' => 100,
        ], 'caja_caja_central');

        $response = $this->sell([['id' => 1, 'cantidad' => 1]], 10);

        $this->assertSame(400, $response->status);
        $this->assertStringContainsString('turno de caja abierto', $response->json()['error']);
    }

    public function testAdminCanQueryAnyBranchShift(): void
    {
        $this->loginAs('caja_caja_central');
        $data = $this->openShift('caja_caja_central', 100);

        $this->loginAs('admin');
        $response = $this->get('/ajax/turno_caja.php?sucursal_id=1&usuario_id=5', 'admin');

        $this->assertSame(200, $response->status, $response->body);
        $turno = $response->json()['turno'];
        $this->assertNotNull($turno);
        $this->assertSame((int) $data['turno_id'], (int) $turno['id']);
    }

    public function testAuditoriaRequiresAdminOrEncargado(): void
    {
        $this->loginAs('caja_caja_central');

        $this->assertSame(403, $this->get('/ajax/turno_caja.php?auditoria=1', 'caja_caja_central')->status);
    }

    public function testEncargadoAuditoriaIncludesAllAssignedBranches(): void
    {
        $this->loginAs('caja_caja_central');
        $this->openShift('caja_caja_central');

        $this->loginAs('caja_caja_lujan');
        $this->openShift('caja_caja_lujan');

        // Turno en una sucursal NO asignada (6): no debe aparecer en la auditoría
        $this->loginAs('caja_caja_1plan3000');
        $this->openShift('caja_caja_1plan3000');

        $this->loginAs('encargado_encargado_central');
        $response = $this->get('/ajax/turno_caja.php?auditoria=1', 'encargado_encargado_central');

        $this->assertSame(200, $response->status, $response->body);
        $turnos = $response->json()['turnos'];
        $sucursales = array_values(array_unique(array_map(
            static fn (array $t): int => (int) $t['sucursal_id'],
            $turnos
        )));
        sort($sucursales);

        $this->assertSame([1, 2], $sucursales);
    }

    /**
     * URL manipulada en la auditoría del encargado: sucursal ajena o valor inválido → 403.
     */
    public function testEncargadoAuditoriaRejectsForeignOrTamperedBranch(): void
    {
        $this->loginAs('caja_caja_lujan');
        $this->openShift('caja_caja_lujan');
        $this->loginAs('caja_caja_1plan3000');
        $this->openShift('caja_caja_1plan3000');

        $this->loginAs('encargado_encargado_central');
        foreach (['6', '16', '0', 'abc', '-1'] as $valor) {
            $response = $this->get('/ajax/turno_caja.php?auditoria=1&sucursal_id=' . $valor, 'encargado_encargado_central');
            $this->assertSame(403, $response->status, $valor);
            $this->assertArrayNotHasKey('turnos', $response->json());
        }

        // Una sucursal asignada sigue funcionando y filtra solo esa
        $propia = $this->get('/ajax/turno_caja.php?auditoria=1&sucursal_id=2', 'encargado_encargado_central');
        $this->assertSame(200, $propia->status, $propia->body);
        $sucursales = array_values(array_unique(array_map(
            static fn (array $t): int => (int) $t['sucursal_id'],
            $propia->json()['turnos']
        )));
        $this->assertSame([2], $sucursales);
    }

    /**
     * Caja y encargado solo consultan su propio turno activo: pedir otro → 403.
     */
    public function testCajaCannotQueryAnotherShift(): void
    {
        $this->loginAs('caja_caja_lujan');
        $this->openShift('caja_caja_lujan');

        $this->loginAs('caja_caja_central');
        $this->openShift('caja_caja_central');

        foreach (['?sucursal_id=2', '?usuario_id=6', '?sucursal_id=2&usuario_id=6', '?sucursal_id=abc', '?sucursal_id[]=1'] as $query) {
            $response = $this->get('/ajax/turno_caja.php' . $query, 'caja_caja_central');
            $this->assertSame(403, $response->status, $query);
            $this->assertArrayNotHasKey('turno', $response->json());
        }

        // Sin parámetros, o con los suyos, ve su propio turno
        foreach (['', '?sucursal_id=1', '?sucursal_id=1&usuario_id=5'] as $query) {
            $response = $this->get('/ajax/turno_caja.php' . $query, 'caja_caja_central');
            $this->assertSame(200, $response->status, $query . ' ' . $response->body);
            $this->assertSame(1, (int) $response->json()['turno']['sucursal_id'], $query);
        }
    }

    public function testUnknownActionReturnsBadRequest(): void
    {
        $this->loginAs('caja_caja_central');

        $response = $this->post('/ajax/turno_caja.php', ['accion' => 'inexistente'], 'caja_caja_central');

        $this->assertSame(400, $response->status);
    }
}
