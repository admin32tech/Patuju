<?php
/**
 * PATUJU POS — Endpoint: Analítica Gerencial (Fase 3)
 * Provee datos agregados para el Dashboard de Chart.js y exportación de reportes CSV.
 *
 * GET                           → JSON completo de métricas y series para el dashboard
 * GET ?export=csv&tipo=X        → Descarga de reporte en formato CSV (compatible con Excel)
 */
require_once __DIR__ . '/../config/auth.php';
verificarSesionAjax('admin');

$db = getDB();

// ── MODO EXPORTACIÓN DE REPORTES (CSV) ────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $tipo = $_GET['tipo'] ?? 'ventas_sucursal';
    $fechaDesde = !empty($_GET['desde']) ? $_GET['desde'] : date('Y-m-01');
    $fechaHasta = !empty($_GET['hasta']) ? $_GET['hasta'] : date('Y-m-d');

    $filename = "reporte_patuju_{$tipo}_" . date('Ymd_His') . ".csv";
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    // UTF-8 BOM para que Microsoft Excel en español reconozca caracteres y acentos sin error
    echo "\xEF\xBB\xBF";
    $output = fopen('php://output', 'w');

    if ($tipo === 'ventas_sucursal') {
        fputcsv($output, ['Sucursal', 'Cantidad Ventas', 'Unidades Vendidas', 'Total Facturado (Bs.)'], ';');
        $stmt = $db->prepare("
            SELECT s.nombre,
                   COUNT(v.id) AS total_transacciones,
                   COALESCE(SUM(v.items_count), 0) AS total_unidades,
                   COALESCE(SUM(v.total), 0) AS total_monto
            FROM sucursales s
            LEFT JOIN ventas v ON v.sucursal_id = s.id 
                 AND DATE(v.fecha) BETWEEN ? AND ?
            WHERE s.activo = 1
            GROUP BY s.id, s.nombre
            ORDER BY total_monto DESC
        ");
        $stmt->execute([$fechaDesde, $fechaHasta]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, [
                $row['nombre'],
                $row['total_transacciones'],
                $row['total_unidades'],
                number_format((float)$row['total_monto'], 2, ',', '.')
            ], ';');
        }
    } elseif ($tipo === 'productos') {
        fputcsv($output, ['Producto', 'Categoría', 'Precio Base (Bs.)', 'Unidades Vendidas', 'Total Recaudado (Bs.)'], ';');
        $stmt = $db->prepare("
            SELECT p.nombre, c.nombre AS categoria, p.precio AS precio_base,
                   COALESCE(SUM(dv.cantidad), 0) AS unidades_vendidas,
                   COALESCE(SUM(dv.subtotal), 0) AS total_recaudado
            FROM productos p
            JOIN categorias c ON c.id = p.categoria_id
            LEFT JOIN detalle_ventas dv ON dv.producto_id = p.id
            LEFT JOIN ventas v ON v.id = dv.venta_id AND DATE(v.fecha) BETWEEN ? AND ?
            WHERE p.activo = 1
            GROUP BY p.id, p.nombre, c.nombre, p.precio
            ORDER BY unidades_vendidas DESC
        ");
        $stmt->execute([$fechaDesde, $fechaHasta]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, [
                $row['nombre'],
                $row['categoria'],
                number_format((float)$row['precio_base'], 2, ',', '.'),
                $row['unidades_vendidas'],
                number_format((float)$row['total_recaudado'], 2, ',', '.')
            ], ';');
        }
    } elseif ($tipo === 'diario') {
        fputcsv($output, ['Fecha', 'Sucursal', 'Transacciones', 'Unidades', 'Total (Bs.)'], ';');
        $stmt = $db->prepare("
            SELECT DATE(v.fecha) as fecha, s.nombre as sucursal,
                   COUNT(v.id) as transacciones,
                   SUM(v.items_count) as unidades,
                   SUM(v.total) as total
            FROM ventas v
            JOIN sucursales s ON s.id = v.sucursal_id
            WHERE DATE(v.fecha) BETWEEN ? AND ?
            GROUP BY DATE(v.fecha), s.id, s.nombre
            ORDER BY DATE(v.fecha) DESC, total DESC
        ");
        $stmt->execute([$fechaDesde, $fechaHasta]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, [
                $row['fecha'],
                $row['sucursal'],
                $row['transacciones'],
                $row['unidades'],
                number_format((float)$row['total'], 2, ',', '.')
            ], ';');
        }
    }
    fclose($output);
    exit;
}

// ── MODO JSON (DASHBOARD ANALÍTICA CHART.JS) ──────────────────────────────
header('Content-Type: application/json; charset=utf-8');

try {
    // 1. KPIs Generales de Hoy y del Mes
    $stmtKpisHoy = $db->query("
        SELECT 
            COALESCE(SUM(total), 0) AS ventas_hoy,
            COUNT(id) AS transacciones_hoy,
            COALESCE(SUM(items_count), 0) AS unidades_hoy
        FROM ventas
        WHERE DATE(fecha) = CURDATE()
    ");
    $kpisHoy = $stmtKpisHoy->fetch(PDO::FETCH_ASSOC);

    $ventasHoyTotal = (float) $kpisHoy['ventas_hoy'];
    $transaccionesHoy = (int) $kpisHoy['transacciones_hoy'];
    $unidadesHoy = (int) $kpisHoy['unidades_hoy'];
    $ticketPromedioHoy = $transaccionesHoy > 0 ? round($ventasHoyTotal / $transaccionesHoy, 2) : 0.0;

    $stmtKpisMes = $db->query("
        SELECT 
            COALESCE(SUM(total), 0) AS ventas_mes,
            COUNT(id) AS transacciones_mes,
            COALESCE(SUM(items_count), 0) AS unidades_mes
        FROM ventas
        WHERE YEAR(fecha) = YEAR(CURDATE()) AND MONTH(fecha) = MONTH(CURDATE())
    ");
    $kpisMes = $stmtKpisMes->fetch(PDO::FETCH_ASSOC);

    // 2. Ventas del Día por Sucursal (Incremental y Comparativo)
    $stmtSucursales = $db->query("
        SELECT 
            s.id, s.nombre,
            COALESCE(v_hoy.unidades, 0) AS unidades_hoy,
            COALESCE(v_hoy.total_bs, 0) AS total_hoy,
            COALESCE(v_hoy.transacciones, 0) AS transacciones_hoy,
            CASE WHEN v_hoy.transacciones > 0 THEN 1 ELSE 0 END AS activo_hoy
        FROM sucursales s
        LEFT JOIN (
            SELECT sucursal_id, 
                   COUNT(id) AS transacciones,
                   SUM(items_count) AS unidades,
                   SUM(total) AS total_bs
            FROM ventas
            WHERE DATE(fecha) = CURDATE()
            GROUP BY sucursal_id
        ) v_hoy ON v_hoy.sucursal_id = s.id
        WHERE s.activo = 1
        ORDER BY unidades_hoy DESC, s.id ASC
    ");
    $ventasPorSucursal = $stmtSucursales->fetchAll(PDO::FETCH_ASSOC);

    // 3. Ventas por Hora del Día (Horarios Pico Hoy)
    $stmtHoras = $db->query("
        SELECT 
            HOUR(fecha) AS hora,
            COUNT(id) AS transacciones,
            COALESCE(SUM(items_count), 0) AS unidades,
            COALESCE(SUM(total), 0) AS total_bs
        FROM ventas
        WHERE DATE(fecha) = CURDATE()
        GROUP BY HOUR(fecha)
        ORDER BY hora ASC
    ");
    $ventasHorasRaw = $stmtHoras->fetchAll(PDO::FETCH_ASSOC);

    // Normalizar de 06:00 a 20:00 para gráfico continuo
    $horasTimeline = [];
    $horasIndex = [];
    foreach ($ventasHorasRaw as $h) {
        $horasIndex[(int)$h['hora']] = $h;
    }

    for ($hr = 6; $hr <= 19; $hr++) {
        $label = sprintf("%02d:00", $hr);
        $found = $horasIndex[$hr] ?? null;
        $horasTimeline[] = [
            'hora_label'    => $label,
            'hora'          => $hr,
            'unidades'      => $found ? (int)$found['unidades'] : 0,
            'total_bs'      => $found ? (float)$found['total_bs'] : 0.0,
            'transacciones' => $found ? (int)$found['transacciones'] : 0
        ];
    }

    // 4. Top 10 Productos Más Vendidos del Mes
    $stmtTopProd = $db->query("
        SELECT 
            p.id, p.nombre, p.imagen, c.nombre AS categoria,
            COALESCE(SUM(dv.cantidad), 0) AS unidades_vendidas,
            COALESCE(SUM(dv.subtotal), 0) AS total_recaudado
        FROM productos p
        JOIN categorias c ON c.id = p.categoria_id
        JOIN detalle_ventas dv ON dv.producto_id = p.id
        JOIN ventas v ON v.id = dv.venta_id AND MONTH(v.fecha) = MONTH(CURDATE()) AND YEAR(v.fecha) = YEAR(CURDATE())
        WHERE p.activo = 1
        GROUP BY p.id, p.nombre, p.imagen, c.nombre
        ORDER BY unidades_vendidas DESC
        LIMIT 10
    ");
    $topProductos = $stmtTopProd->fetchAll(PDO::FETCH_ASSOC);

    // 5. Tendencia de Ventas de los Últimos 30 Días
    $stmtTendencia = $db->query("
        SELECT 
            DATE(fecha) AS dia,
            COUNT(id) AS transacciones,
            COALESCE(SUM(items_count), 0) AS unidades,
            COALESCE(SUM(total), 0) AS total_bs
        FROM ventas
        WHERE fecha >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
        GROUP BY DATE(fecha)
        ORDER BY dia ASC
    ");
    $tendenciaRaw = $stmtTendencia->fetchAll(PDO::FETCH_ASSOC);

    // Mapear los 30 días continuos
    $tendencia30Dias = [];
    $tendenciaIndex = [];
    foreach ($tendenciaRaw as $t) {
        $tendenciaIndex[$t['dia']] = $t;
    }

    for ($i = 29; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-{$i} days"));
        $found = $tendenciaIndex[$d] ?? null;
        $tendencia30Dias[] = [
            'fecha'         => $d,
            'dia_label'     => date('d/m', strtotime($d)),
            'total_bs'      => $found ? round((float)$found['total_bs'], 2) : 0.0,
            'unidades'      => $found ? (int)$found['unidades'] : 0,
            'transacciones' => $found ? (int)$found['transacciones'] : 0
        ];
    }

    // 6. Ventas por Mes (Últimos 12 meses)
    $stmtMeses = $db->query("
        SELECT 
            DATE_FORMAT(fecha, '%Y-%m') AS mes_key,
            DATE_FORMAT(fecha, '%b %Y')  AS mes_label,
            COUNT(id)                    AS transacciones,
            COALESCE(SUM(items_count), 0) AS unidades,
            COALESCE(SUM(total), 0)      AS total_bs
        FROM ventas
        WHERE fecha >= DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 11 MONTH)
        GROUP BY mes_key, mes_label
        ORDER BY mes_key ASC
    ");
    $ventasPorMes = $stmtMeses->fetchAll(PDO::FETCH_ASSOC);

    // 7. Distribución por Métodos de Pago
    $stmtMetodos = $db->query("
        SELECT 
            metodo_pago,
            COUNT(id) AS transacciones,
            COALESCE(SUM(total), 0) AS total_bs
        FROM ventas
        WHERE MONTH(fecha) = MONTH(CURDATE()) AND YEAR(fecha) = YEAR(CURDATE())
        GROUP BY metodo_pago
    ");
    $metodosPago = $stmtMetodos->fetchAll(PDO::FETCH_ASSOC);

    // Estructura conforme al estándar estricto de Fase_3.md
    jsonResponse([
        'ok'           => true,
        'generated_at' => date('c'),
        'data_version' => time(),
        'data'         => [
            'kpis' => [
                'ventas_hoy'          => $ventasHoyTotal,
                'transacciones_hoy'   => $transaccionesHoy,
                'unidades_hoy'        => $unidadesHoy,
                'ticket_promedio_hoy' => $ticketPromedioHoy,
                'ventas_mes'          => (float)$kpisMes['ventas_mes'],
                'transacciones_mes'   => (int)$kpisMes['transacciones_mes'],
                'unidades_mes'        => (int)$kpisMes['unidades_mes'],
            ],
            'ventas_por_sucursal' => $ventasPorSucursal,
            'ventas_por_hora'     => $horasTimeline,
            'top_productos'       => $topProductos,
            'tendencia_30_dias'   => $tendencia30Dias,
            'ventas_por_mes'      => $ventasPorMes,
            'metodos_pago'        => $metodosPago
        ]
    ]);

} catch (Exception $e) {
    jsonResponse([
        'ok'    => false,
        'error' => 'Error al calcular analítica: ' . $e->getMessage()
    ], 500);
}
