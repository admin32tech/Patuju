<?php
/**
 * PATUJU POS - Endpoint: Obtener productos
 * GET  → Lista todos los productos activos agrupados por categoría
 */
require_once __DIR__ . '/../config/auth.php';
verificarSesionAjax();
// BUG-04 FIX: El header Content-Type ya lo establece jsonResponse() en database.php.
// No se repite aquí para evitar "headers already sent" con output_buffering desactivado.

try {
    $db = getDB();

    // Obtener categorías activas
    $categorias = $db->query("
        SELECT id, nombre, icono 
        FROM categorias 
        WHERE activo = 1 
        ORDER BY orden ASC
    ")->fetchAll();

    $sucursal_id = (int) ($_SESSION['sucursal_id'] ?? 1);

    // Obtener productos activos Y habilitados en la sucursal con su stock y precio específico
    $stmtProductos = $db->prepare("
        SELECT p.id, p.nombre, 
               COALESCE(ss.precio_sucursal, p.precio) AS precio,
               p.precio AS precio_base,
               ss.precio_sucursal,
               p.categoria_id, p.tipo_rotacion, p.imagen, p.descripcion,
               COALESCE(ss.cantidad_disponible, 0) AS cantidad_disponible,
               COALESCE(ss.alerta_minima, 10) AS alerta_minima
        FROM productos p
        JOIN stock_sucursal ss ON ss.producto_id = p.id
        WHERE p.activo = 1 AND ss.sucursal_id = ? AND COALESCE(ss.disponible_venta, 1) = 1
        ORDER BY p.categoria_id ASC, p.nombre ASC
    ");
    $stmtProductos->execute([$sucursal_id]);
    $productos = $stmtProductos->fetchAll();

    // Agrupar productos por categoría
    $resultado = [];
    foreach ($categorias as $cat) {
        $cat['productos'] = array_values(array_filter($productos, function($p) use ($cat) {
            return $p['categoria_id'] == $cat['id'];
        }));
        if (count($cat['productos']) > 0) {
            $resultado[] = $cat;
        }
    }

    jsonResponse(['success' => true, 'data' => $resultado]);

} catch (Exception $e) {
    jsonResponse(['success' => false, 'error' => 'Error al cargar productos'], 500);
}
