<?php
/**
 * PATUJU POS - Endpoint: Historial y Auditoría de Ventas (Fase 2)
 *
 * GET        → Lista de ventas con auditoría y trazabilidad
 * GET ?hoy=1 → Total acumulado del día
 * Parámetros opcionales (admin): ?sucursal_id=X & ?fecha_desde=Y & ?fecha_hasta=Z & ?usuario_id=W & ?limit=50
 */
require_once __DIR__ . '/../config/auth.php';
verificarSesionAjax();

$db         = getDB();
$rol        = $_SESSION['usuario_rol'];
$sucSesion  = (int) ($_SESSION['sucursal_id'] ?? 0);
$usuarioId  = (int) $_SESSION['usuario_id'];
// BUG-04 FIX: Content-Type lo emite jsonResponse() en database.php
try {
    // ── Si piden el total del día ──────────────────────────────────────────
    if (isset($_GET['hoy'])) {
        $sql = "
            SELECT 
                COALESCE(SUM(total), 0) as total_dia,
                COUNT(*) as ventas_dia
            FROM ventas 
            WHERE DATE(fecha) = CURDATE()
        ";
        $params = [];
        if (($rol === 'admin' || $rol === 'encargado') && !empty($_GET['sucursal_id'])) {
            $sql .= " AND sucursal_id = ?";
            $params[] = (int) $_GET['sucursal_id'];
        } elseif ($sucSesion) {
            $sql .= " AND sucursal_id = ?";
            $params[] = $sucSesion;
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
    if (($rol === 'admin' || $rol === 'encargado') && !empty($_GET['sucursal_id'])) {
        $where[] = "v.sucursal_id = ?";
        $params[] = (int) $_GET['sucursal_id'];
    } elseif ($sucSesion) {
        $where[] = "v.sucursal_id = ?";
        $params[] = $sucSesion;
    }

    // Filtro por usuario/cajero
    if (!empty($_GET['usuario_id'])) {
        $where[] = "v.usuario_id = ?";
        $params[] = (int) $_GET['usuario_id'];
    }

    // Filtros por fecha
    if (!empty($_GET['fecha_desde'])) {
        $where[] = "DATE(v.fecha) >= ?";
        $params[] = $_GET['fecha_desde'];
    }
    if (!empty($_GET['fecha_hasta'])) {
        $where[] = "DATE(v.fecha) <= ?";
        $params[] = $_GET['fecha_hasta'];
    }

    $limit = isset($_GET['limit']) ? min((int) $_GET['limit'], 200) : 50;
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
