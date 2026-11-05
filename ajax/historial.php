<?php
/**
 * PATUJU POS - Endpoint: Historial y Auditoría de Ventas (Fase 2)
 *
 * GET        → Lista de ventas con auditoría y trazabilidad
 * GET ?hoy=1 → Total acumulado del día
 * Parámetros opcionales: ?sucursal_id=X & ?usuario_id=W & ?limit=50 (máx. 200)
 * Solo admin: ?fecha_desde=Y & ?fecha_hasta=Z & ?solo_hoy=1
 *
 * Caja y encargado solo ven las ventas del día actual de sus sucursales
 * (caja: la suya; encargado: todas las asignadas). Un sucursal_id manipulado
 * (0, 'abc' o una sucursal ajena) responde 403.
 */
require_once __DIR__ . '/../config/auth.php';
verificarSesionAjax();

$db         = getDB();
$rol        = $_SESSION['usuario_rol'];
$sucSesion  = (int) ($_SESSION['sucursal_id'] ?? 0);
$usuarioId  = (int) $_SESSION['usuario_id'];
// BUG-04 FIX: Content-Type lo emite jsonResponse() en database.php

try {
    // Filtro de sucursal común al total del día y al listado.
    // Con sucursal_id: solo esa (403 si no corresponde). Sin él: admin ve todas;
    // el resto, sus sucursales permitidas (caja: la suya; encargado: las asignadas).
    $sucSolicitada = sucursalSolicitadaPermitida($_GET['sucursal_id'] ?? null);
    $filtroSucSql = null;
    $filtroSucParams = [];
    if ($sucSolicitada !== null) {
        $filtroSucSql = "v.sucursal_id = ?";
        $filtroSucParams = [$sucSolicitada];
    } elseif ($rol !== 'admin') {
        $asignadas = obtenerSucursalesAsignadas();
        if (!empty($asignadas)) {
            $filtroSucSql = "v.sucursal_id IN (" . implode(',', array_fill(0, count($asignadas), '?')) . ")";
            $filtroSucParams = $asignadas;
        } else {
            $filtroSucSql = "1=0";
        }
    }

    // ── Si piden el total del día ──────────────────────────────────────────
    if (isset($_GET['hoy'])) {
        $sql = "
            SELECT 
                COALESCE(SUM(v.total), 0) as total_dia,
                COUNT(*) as ventas_dia
            FROM ventas v
            WHERE DATE(v.fecha) = CURDATE()
        ";
        $params = [];
        if ($filtroSucSql !== null) {
            $sql .= " AND {$filtroSucSql}";
            $params = $filtroSucParams;
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $resumen = $stmt->fetch();
        jsonResponse([
            'success'   => true,
            'total_dia' => number_format(floatval($resumen['total_dia']), 2),
            'ventas_dia'=> intval($resumen['ventas_dia'])
        ]);
    }

    // ── Historial y Auditoría con Trazabilidad ──────────────────────────────
    $where = ["1=1"];
    $params = [];

    // Filtro por sucursal
    if ($filtroSucSql !== null) {
        $where[] = $filtroSucSql;
        $params = array_merge($params, $filtroSucParams);
    }

    // Filtro por usuario/cajero
    if (!empty($_GET['usuario_id'])) {
        $where[] = "v.usuario_id = ?";
        $params[] = (int) $_GET['usuario_id'];
    }

    // Filtros por fecha: caja y encargado solo ven el día actual (no se acumula);
    // el admin consulta cualquier rango en Auditoría o pide ?solo_hoy=1.
    if ($rol !== 'admin' || !empty($_GET['solo_hoy'])) {
        $where[] = "DATE(v.fecha) = CURDATE()";
    }
    if ($rol === 'admin' && !empty($_GET['fecha_desde'])) {
        $where[] = "DATE(v.fecha) >= ?";
        $params[] = $_GET['fecha_desde'];
    }
    if ($rol === 'admin' && !empty($_GET['fecha_hasta'])) {
        $where[] = "DATE(v.fecha) <= ?";
        $params[] = $_GET['fecha_hasta'];
    }

    $limit = isset($_GET['limit']) ? max(1, min((int) $_GET['limit'], 200)) : 50;
    $whereSql = implode(" AND ", $where);

    $sql = "
        SELECT 
            v.id, v.sucursal_id, s.nombre AS sucursal_nombre,
            v.usuario_id, COALESCE(u.nombre_display, 'Cajero') AS cajero_nombre,
            v.total, v.items_count, v.metodo_pago, v.monto_recibido, v.cambio,
            v.turno_id, v.nota, v.fecha
        FROM ventas v
        JOIN sucursales s ON s.id = v.sucursal_id
        LEFT JOIN usuarios u ON u.id = v.usuario_id
        WHERE {$whereSql}
        ORDER BY v.fecha DESC
        LIMIT {$limit}
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $ventas = $stmt->fetchAll();

    // Obtener detalles de productos vendidos para cada venta
    $stmtDetalle = $db->prepare("
        SELECT producto_nombre, cantidad, precio_unitario, subtotal
        FROM detalle_ventas
        WHERE venta_id = ?
    ");

    foreach ($ventas as &$venta) {
        $stmtDetalle->execute([$venta['id']]);
        $venta['detalle'] = $stmtDetalle->fetchAll();
        $venta['total_raw'] = floatval($venta['total']);
        $venta['total'] = number_format(floatval($venta['total']), 2);
    }

    jsonResponse(['success' => true, 'data' => $ventas]);

} catch (Exception $e) {
    jsonResponse(['success' => false, 'error' => 'Error al cargar historial: ' . $e->getMessage()], 500);
}
