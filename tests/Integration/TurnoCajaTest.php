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
     * La auditoría de turnos del encargado se recorta a 30 días; el admin conserva el histórico.
     */
    public function testEncargadoAuditoriaIsLimitedToThirtyDays(): void
    {
        $this->loginAs('caja_caja_central');
        $this->openShift('caja_caja_central');
        $reciente = (int) $this->scalar('SELECT MAX(id) FROM turnos_caja');

        $this->loginAs('caja_caja_lujan');
        $this->openShift('caja_caja_lujan');
        $antiguo = (int) $this->scalar('SELECT MAX(id) FROM turnos_caja');
        $this->db()->prepare('UPDATE turnos_caja SET hora_apertura = hora_apertura - INTERVAL 45 DAY WHERE id = ?')->execute([$antiguo]);

        $idsDe = function (string $user, string $query): array {
            $this->loginAs($user);
            $response = $this->get('/ajax/turno_caja.php?auditoria=1' . $query, $user);
            $this->assertSame(200, $response->status, $response->body);
            $ids = array_map(static fn (array $t): int => (int) $t['id'], $response->json()['turnos']);
            sort($ids);
            return $ids;
        };

        $enc = 'encargado_encargado_central';
        $this->assertSame([$reciente], $idsDe($enc, ''));
        $this->assertSame([$reciente], $idsDe($enc, '&fecha_desde=2000-01-01&fecha_hasta=2099-12-31'));
        $this->assertSame([$reciente], $idsDe($enc, '&estado=abierto'));
        // Un estado inválido se ignora y no rompe la consulta
        $this->assertSame([$reciente], $idsDe($enc, '&estado=x%27%20OR%201=1'));

        $this->assertSame([$reciente, $antiguo], $idsDe('admin', '&fecha_desde=2000-01-01'));
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

    /**
     * Cierre supervisado: el encargado ve los turnos abiertos de sus cajas con su Corte X.
     */
    public function testEncargadoListsOpenShiftsOfAssignedBranches(): void
    {
        $this->loginAs('caja_caja_central');
        $this->openShift('caja_caja_central', 100);
        $this->assertSame(200, $this->sell([['id' => 1, 'cantidad' => 1]], 10, 'caja_caja_central')->status);

        $this->loginAs('caja_caja_lujan');
        $this->openShift('caja_caja_lujan', 50);

        $this->loginAs('caja_caja_1plan3000'); // sucursal 6, no asignada al encargado central
        $this->openShift('caja_caja_1plan3000');

        $this->loginAs('encargado_encargado_central');
        $todos = $this->get('/ajax/turno_caja.php?abiertos=1', 'encargado_encargado_central');
        $this->assertSame(200, $todos->status, $todos->body);
        $sucursales = array_map(static fn (array $t): int => (int) $t['sucursal_id'], $todos->json()['turnos']);
        sort($sucursales);
        $this->assertSame([1, 2], $sucursales);

        $central = $this->get('/ajax/turno_caja.php?abiertos=1&sucursal_id=1', 'encargado_encargado_central');
        $this->assertSame(200, $central->status, $central->body);
        $turno = $central->json()['turnos'][0];
        $this->assertCount(1, $central->json()['turnos']);
        $this->assertSame(7.0, (float) $turno['ventas_sistema']);
        $this->assertSame(1, (int) $turno['num_ventas']);
        $this->assertSame(107.0, (float) $turno['efectivo_esperado']);

        // Sucursal ajena o valor manipulado → 403; la caja no puede listar turnos ajenos
        foreach (['6', '0', 'abc'] as $valor) {
            $this->assertSame(403, $this->get('/ajax/turno_caja.php?abiertos=1&sucursal_id=' . $valor, 'encargado_encargado_central')->status, $valor);
        }
        $this->assertSame(403, $this->get('/ajax/turno_caja.php?abiertos=1', 'caja_caja_central')->status);
    }

    public function testEncargadoClosesShiftOfAssignedBranch(): void
    {
        $this->loginAs('caja_caja_central');
        $turnoId = (int) $this->openShift('caja_caja_central', 100)['turno_id'];
        $this->assertSame(200, $this->sell([['id' => 1, 'cantidad' => 1]], 10, 'caja_caja_central')->status);

        $this->loginAs('encargado_encargado_central');
        $response = $this->post('/ajax/turno_caja.php', [
            'accion' => 'cerrar_supervisado',
            'turno_id' => $turnoId,
            'monto_cierre' => 105,
            'notas' => 'El cajero no cerró',
        ], 'encargado_encargado_central');

        $this->assertSame(200, $response->status, $response->body);
        $data = $response->json();
        $this->assertSame(107.0, (float) $data['efectivo_esperado']);
        $this->assertSame(-2.0, (float) $data['diferencia']);
        $this->assertSame('faltante', $data['estado']);

        $fila = $this->row('SELECT * FROM turnos_caja WHERE id = ?', [$turnoId]);
        $this->assertSame('cerrado', $fila['estado']);
        $this->assertNotNull($fila['hora_cierre']);
        $this->assertSame('105.00', $fila['monto_cierre']);
        $this->assertStringContainsString('Cerrado por Encargado Central (encargado)', (string) $fila['notas']);
        $this->assertStringContainsString('El cajero no cerró', (string) $fila['notas']);

        // Cerrarlo otra vez → 409; el cajero ya no tiene turno ni puede vender
        $otraVez = $this->post('/ajax/turno_caja.php', [
            'accion' => 'cerrar_supervisado', 'turno_id' => $turnoId, 'monto_cierre' => 105,
        ], 'encargado_encargado_central');
        $this->assertSame(409, $otraVez->status);
        $this->assertNull($this->get('/ajax/turno_caja.php', 'caja_caja_central')->json()['turno']);
        $this->assertNotSame(200, $this->sell([['id' => 1, 'cantidad' => 1]], 10, 'caja_caja_central')->status);
    }

    public function testCerrarSupervisadoRejectsForeignBranchAndBadInput(): void
    {
        $this->loginAs('caja_caja_1plan3000'); // sucursal 6
        $ajeno = (int) $this->openShift('caja_caja_1plan3000')['turno_id'];

        $this->loginAs('caja_caja_central');
        $propio = (int) $this->openShift('caja_caja_central')['turno_id'];

        $this->loginAs('encargado_encargado_central');
        $cerrar = fn (array $body, string $user = 'encargado_encargado_central') =>
            $this->post('/ajax/turno_caja.php', ['accion' => 'cerrar_supervisado'] + $body, $user);

        $this->assertSame(403, $cerrar(['turno_id' => $ajeno, 'monto_cierre' => 100])->status);
        $this->assertSame('abierto', $this->scalar('SELECT estado FROM turnos_caja WHERE id = ?', [$ajeno]));

        $this->assertSame(400, $cerrar(['turno_id' => 0, 'monto_cierre' => 100])->status);
        $this->assertSame(400, $cerrar(['turno_id' => $propio])->status);
        $this->assertSame(400, $cerrar(['turno_id' => $propio, 'monto_cierre' => 'abc'])->status);
        $this->assertSame(400, $cerrar(['turno_id' => $propio, 'monto_cierre' => -5])->status);
        $this->assertSame(404, $cerrar(['turno_id' => 999999, 'monto_cierre' => 100])->status);

        // Una caja no puede cerrar turnos de otros, ni siquiera de su sucursal
        $this->assertSame(403, $cerrar(['turno_id' => $propio, 'monto_cierre' => 100], 'caja_caja_central')->status);
        $this->assertSame('abierto', $this->scalar('SELECT estado FROM turnos_caja WHERE id = ?', [$propio]));
    }

    /**
     * El caso que motivó el cierre supervisado: una caja reasignada a otra sucursal con
     * el turno abierto ya no puede cerrarlo ella misma; el encargado (o el admin) sí.
     */
    public function testReassignedCajaShiftCanBeClosedBySupervisor(): void
    {
        $this->loginAs('caja_caja_central');
        $turnoId = (int) $this->openShift('caja_caja_central', 80)['turno_id'];

        $this->loginAs('admin');
        $editar = $this->post('/ajax/usuarios.php', [
            'accion' => 'editar', 'id' => 5, 'nombre_display' => 'Caja Central',
            'rol' => 'caja', 'sucursal_id' => 2, 'activo' => 1,
        ], 'admin');
        $this->assertSame(200, $editar->status, $editar->body);

        // Desde su caja (ahora sucursal 2) ya no lo ve ni lo puede cerrar
        $this->assertNull($this->get('/ajax/turno_caja.php', 'caja_caja_central')->json()['turno']);
        $propio = $this->post('/ajax/turno_caja.php', ['accion' => 'cerrar', 'monto_cierre' => 80], 'caja_caja_central');
        $this->assertSame(404, $propio->status);

        $this->loginAs('encargado_encargado_central');
        $supervisado = $this->post('/ajax/turno_caja.php', [
            'accion' => 'cerrar_supervisado', 'turno_id' => $turnoId, 'monto_cierre' => 80,
        ], 'encargado_encargado_central');
        $this->assertSame(200, $supervisado->status, $supervisado->body);
        $this->assertSame('cuadrado', $supervisado->json()['estado']);
        $this->assertSame('cerrado', $this->scalar('SELECT estado FROM turnos_caja WHERE id = ?', [$turnoId]));
    }

    public function testAdminCanCloseAnyShift(): void
    {
        $this->loginAs('caja_caja_1plan3000');
        $turnoId = (int) $this->openShift('caja_caja_1plan3000', 20)['turno_id'];

        $this->loginAs('admin');
        $this->assertSame(200, $this->get('/ajax/turno_caja.php?abiertos=1&sucursal_id=6', 'admin')->status);
        $response = $this->post('/ajax/turno_caja.php', [
            'accion' => 'cerrar_supervisado', 'turno_id' => $turnoId, 'monto_cierre' => 20,
        ], 'admin');
        $this->assertSame(200, $response->status, $response->body);
        $this->assertStringContainsString('(admin)', (string) $this->scalar('SELECT notas FROM turnos_caja WHERE id = ?', [$turnoId]));
    }

    public function testUnknownActionReturnsBadRequest(): void
    {
        $this->loginAs('caja_caja_central');

        $response = $this->post('/ajax/turno_caja.php', ['accion' => 'inexistente'], 'caja_caja_central');

        $this->assertSame(400, $response->status);
    }
}
