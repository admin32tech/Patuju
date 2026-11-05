<?php
/**
 * PATUJU POS — Endpoint: Sincronización de Dashboard Admin (Fase 2)
 *
 * GET ?action=validate → Devuelve el timestamp de la última venta registrada.
 *                        El frontend lo compara con su último valor local para
 *                        saber si debe refrescar los datos del dashboard.
 *
 * Respuesta: { status: 'ok', last_change: 'YYYY-MM-DD HH:MM:SS', timestamp: 1234567890 }
 */
require_once __DIR__ . '/../config/auth.php';
verificarSesionAjax();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['success' => false, 'error' => 'Método no permitido'], 405);
}

$rol       = $_SESSION['usuario_rol'];
$sucSesion = (int) ($_SESSION['sucursal_id'] ?? 0);

$db = getDB();

try {
    // Admin ve todos; encargado ve todas sus sucursales asignadas; caja solo la suya
    if ($rol !== 'admin') {
        $asignadas = obtenerSucursalesAsignadas();

        if (!empty($asignadas)) {
            $placeholders = implode(',', array_fill(0, count($asignadas), '?'));
            $stmtLastVenta = $db->prepare("
                SELECT MAX(fecha) AS last_venta
                FROM ventas
                WHERE sucursal_id IN ($placeholders)
            ");
            $stmtLastVenta->execute($asignadas);
        } else {
            $stmtLastVenta = $db->prepare("SELECT MAX(fecha) AS last_venta FROM ventas WHERE 1=0");
            $stmtLastVenta->execute();
        }
    } else {
        $stmtLastVenta = $db->prepare("SELECT MAX(fecha) AS last_venta FROM ventas");
        $stmtLastVenta->execute();
    }

    $row = $stmtLastVenta->fetch();
    $lastChange = $row['last_venta'] ?? null;

    // Timestamp Unix para comparación rápida en el frontend
    $tsUnix = $lastChange ? strtotime($lastChange) : 0;

    jsonResponse([
        'status'      => 'ok',
        'last_change' => $lastChange,
        'timestamp'   => $tsUnix,
    ]);

} catch (Exception $e) {
    jsonResponse(['success' => false, 'error' => 'Error al sincronizar'], 500);
}
