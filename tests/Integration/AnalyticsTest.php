<?php

declare(strict_types=1);

namespace Patuju\Tests\Integration;

use Patuju\Tests\Support\TestCase;

final class AnalyticsTest extends TestCase
{
    public function testRequiresAuthentication(): void
    {
        $this->assertSame(401, $this->get('/ajax/analytics.php')->status);
    }

    public function testRequiresAuthorizedRole(): void
    {
        $this->loginAs('caja_caja_central');
        $this->assertSame(403, $this->get('/ajax/analytics.php', 'caja_caja_central')->status);

        $this->loginAs('encargado_encargado_central');
        $this->assertSame(200, $this->get('/ajax/analytics.php', 'encargado_encargado_central')->status);
    }

    public function testDashboardHasExpectedStructure(): void
    {
        $this->loginAs('admin');

        $response = $this->get('/ajax/analytics.php', 'admin');

        $this->assertSame(200, $response->status, $response->body);
        $data = $response->json();
        $this->assertTrue($data['ok']);
        $this->assertIsInt($data['data_version']);

        $dashboard = $data['data'];
        $this->assertArrayHasKey('kpis', $dashboard);
        $this->assertCount(15, $dashboard['ventas_por_sucursal']);
        $this->assertCount(14, $dashboard['ventas_por_hora']);
        $this->assertCount(30, $dashboard['tendencia_30_dias']);
        $this->assertSame('06:00', $dashboard['ventas_por_hora'][0]['hora_label']);
        $this->assertSame('19:00', $dashboard['ventas_por_hora'][13]['hora_label']);

        foreach ($dashboard['ventas_por_sucursal'] as $sucursal) {
            $this->assertNotSame(16, (int) $sucursal['id']);
        }
    }

    public function testKpisReflectTodaySales(): void
    {
        $this->loginAs('caja_caja_central');
        $this->openShift();
        $response = $this->sell([
            ['id' => 1, 'cantidad' => 2],
            ['id' => 6, 'cantidad' => 1],
        ], 30);
        $this->assertSame(200, $response->status, $response->body);

        $this->loginAs('admin');
        $data = $this->get('/ajax/analytics.php', 'admin')->json()['data'];

        $this->assertSame(19.0, (float) $data['kpis']['ventas_hoy']);
        $this->assertSame(1, (int) $data['kpis']['transacciones_hoy']);
        $this->assertSame(3, (int) $data['kpis']['unidades_hoy']);
        $this->assertSame(19.0, (float) $data['kpis']['ticket_promedio_hoy']);
        $this->assertSame(19.0, (float) $data['kpis']['ventas_mes']);
        $this->assertSame(1, (int) $data['kpis']['transacciones_mes']);

        $central = null;
        foreach ($data['ventas_por_sucursal'] as $sucursal) {
            if ((int) $sucursal['id'] === 1) {
                $central = $sucursal;
                break;
            }
        }
        $this->assertNotNull($central);
        $this->assertSame(19.0, (float) $central['total_hoy']);
        $this->assertSame(3, (int) $central['unidades_hoy']);
        $this->assertSame(1, (int) $central['transacciones_hoy']);
        $this->assertSame(1, (int) $central['activo_hoy']);

        $this->assertCount(2, $data['top_productos']);
        $this->assertSame('Salteña Grande', $data['top_productos'][0]['nombre']);
        $this->assertSame(2, (int) $data['top_productos'][0]['unidades_vendidas']);
        $this->assertSame('efectivo', $data['metodos_pago'][0]['metodo_pago']);
    }

    public function testInternalBranchSalesAreExcluded(): void
    {
        $this->loginAs('admin');
        $this->openShift('admin', 100);
        $response = $this->sell([['id' => 1, 'cantidad' => 1]], 10, 'admin');
        $this->assertSame(200, $response->status, $response->body);

        $data = $this->get('/ajax/analytics.php', 'admin')->json()['data'];

        $this->assertSame(0.0, (float) $data['kpis']['ventas_hoy']);
        $this->assertSame(0, (int) $data['kpis']['transacciones_hoy']);
        $this->assertCount(0, $data['metodos_pago']);
        $this->assertCount(0, $data['top_productos']);
    }

    public function testEncargadoAnalyticsOnlyIncludesAssignedBranches(): void
    {
        $this->loginAs('caja_caja_central');
        $this->openShift('caja_caja_central');
        $this->sell([['id' => 1, 'cantidad' => 2]], 100, 'caja_caja_central');

        $this->loginAs('caja_caja_lujan');
        $this->openShift('caja_caja_lujan');
        $this->sell([['id' => 1, 'cantidad' => 1]], 100, 'caja_caja_lujan');

        // Sucursal 6 no está asignada al encargado de Central.
        $this->loginAs('caja_caja_1plan3000');
        $this->openShift('caja_caja_1plan3000');
        $this->sell([['id' => 1, 'cantidad' => 5]], 100, 'caja_caja_1plan3000');

        $this->loginAs('encargado_encargado_central');
        $data = $this->get('/ajax/analytics.php', 'encargado_encargado_central')->json()['data'];

        $this->assertSame(21.0, (float) $data['kpis']['ventas_hoy']);
        $this->assertSame(2, (int) $data['kpis']['transacciones_hoy']);
        $this->assertSame(3, (int) $data['kpis']['unidades_hoy']);

        $ids = array_map(static fn (array $s): int => (int) $s['id'], $data['ventas_por_sucursal']);
        sort($ids);
        $this->assertSame([1, 2, 3, 4, 5], $ids);

        $unidadesTop = array_sum(array_map(
            static fn (array $p): int => (int) $p['unidades_vendidas'],
            $data['top_productos']
        ));
        $this->assertSame(3, $unidadesTop);
    }

    public function testEncargadoAnalyticsRejectsForeignBranch(): void
    {
        $this->loginAs('caja_caja_1plan3000');
        $this->openShift('caja_caja_1plan3000');
        $this->sell([['id' => 1, 'cantidad' => 5]], 100, 'caja_caja_1plan3000');

        $this->loginAs('encargado_encargado_central');
        foreach (['6', '0', 'abc', '-1'] as $valor) {
            $response = $this->get('/ajax/analytics.php?sucursal_id=' . $valor, 'encargado_encargado_central');
            $this->assertSame(403, $response->status, $valor);
        }

        $propia = $this->get('/ajax/analytics.php?sucursal_id=2', 'encargado_encargado_central');
        $this->assertSame(200, $propia->status, $propia->body);
        $data = $propia->json()['data'];
        $ids = array_map(static fn (array $s): int => (int) $s['id'], $data['ventas_por_sucursal']);
        $this->assertSame([2], $ids);
        $this->assertSame(0.0, (float) $data['kpis']['ventas_hoy']);
    }

    public function testEncargadoCannotExportCsv(): void
    {
        $this->loginAs('encargado_encargado_central');

        $this->assertSame(
            403,
            $this->get('/ajax/analytics.php?export=csv&tipo=ventas_sucursal', 'encargado_encargado_central')->status
        );
    }

    public function testAdminCsvExportRespectsBranchFilter(): void
    {
        $this->loginAs('caja_caja_central');
        $this->openShift();
        $this->sell([['id' => 1, 'cantidad' => 2]], 30);

        $this->loginAs('caja_caja_lujan');
        $this->openShift('caja_caja_lujan');
        $this->sell([['id' => 1, 'cantidad' => 1]], 100, 'caja_caja_lujan');

        $this->loginAs('admin');
        $response = $this->get('/ajax/analytics.php?export=csv&tipo=ventas_sucursal&sucursal_id=2', 'admin');

        $this->assertSame(200, $response->status);
        $rows = $this->csvRows($response->body);
        $this->assertSame(['Patuju Lujan', '1', '1', '7,00'], $this->findCsvRow($rows, 'Patuju Lujan'));
        $this->assertNull($this->findCsvRow($rows, 'Patuju Central'));
    }

    public function testCsvExportVentasSucursal(): void
    {
        $this->loginAs('caja_caja_central');
        $this->openShift();
        $this->sell([
            ['id' => 1, 'cantidad' => 2],
            ['id' => 6, 'cantidad' => 1],
        ], 30);

        $this->loginAs('admin');
        $response = $this->get('/ajax/analytics.php?export=csv&tipo=ventas_sucursal', 'admin');

        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('text/csv', (string) $response->header('content-type'));
        $this->assertStringContainsString('attachment; filename="reporte_patuju_ventas_sucursal_', (string) $response->header('content-disposition'));
        $this->assertStringStartsWith("\xEF\xBB\xBF", $response->body);

        $rows = $this->csvRows($response->body);
        $this->assertSame(['Sucursal', 'Cantidad Ventas', 'Unidades Vendidas', 'Total Facturado (Bs.)'], $rows[0]);
        $this->assertSame(['Patuju Central', '1', '3', '19,00'], $this->findCsvRow($rows, 'Patuju Central'));
    }

    public function testCsvExportProductos(): void
    {
        $this->loginAs('caja_caja_central');
        $this->openShift();
        $this->sell([['id' => 1, 'cantidad' => 2]], 20);

        $this->loginAs('admin');
        $response = $this->get('/ajax/analytics.php?export=csv&tipo=productos', 'admin');

        $this->assertSame(200, $response->status);

        $rows = $this->csvRows($response->body);
        $this->assertSame(['Producto', 'Categoría', 'Precio Base (Bs.)', 'Unidades Vendidas', 'Total Recaudado (Bs.)'], $rows[0]);
        $this->assertSame(['Salteña Grande', 'Salteñas', '7,00', '2', '14,00'], $this->findCsvRow($rows, 'Salteña Grande'));
    }

    public function testCsvExportDiario(): void
    {
        $this->loginAs('caja_caja_central');
        $this->openShift();
        $this->sell([
            ['id' => 1, 'cantidad' => 2],
            ['id' => 6, 'cantidad' => 1],
        ], 30);

        $this->loginAs('admin');
        $response = $this->get('/ajax/analytics.php?export=csv&tipo=diario', 'admin');

        $this->assertSame(200, $response->status);

        $rows = $this->csvRows($response->body);
        $this->assertSame(['Fecha', 'Sucursal', 'Transacciones', 'Unidades', 'Total (Bs.)'], $rows[0]);
        $this->assertSame([date('Y-m-d'), 'Patuju Central', '1', '3', '19,00'], $this->findCsvRow($rows, date('Y-m-d')));
    }

    public function testCsvExportDefaultsToVentasSucursal(): void
    {
        $this->loginAs('admin');

        $response = $this->get('/ajax/analytics.php?export=csv', 'admin');

        $this->assertSame(200, $response->status);
        $rows = $this->csvRows($response->body);
        $this->assertSame('Sucursal', $rows[0][0]);
        $this->assertSame('Cantidad Ventas', $rows[0][1]);
    }

    /**
     * @return list<list<string>>
     */
    private function csvRows(string $body): array
    {
        $body = preg_replace('/^\xEF\xBB\xBF/', '', $body) ?? $body;
        $rows = [];
        foreach (preg_split("/\r?\n/", trim($body)) ?: [] as $line) {
            if ($line === '') {
                continue;
            }
            $rows[] = str_getcsv($line, ';', '"', '');
        }

        return $rows;
    }

    /**
     * @param list<list<string>> $rows
     * @return list<string>|null
     */
    private function findCsvRow(array $rows, string $firstValue): ?array
    {
        foreach ($rows as $row) {
            if (($row[0] ?? null) === $firstValue) {
                return $row;
            }
        }

        return null;
    }
}
