<?php
/**
 * PATUJU POS - Endpoint: CRUD de Productos
 * GET              → Lista todos los productos (incluidos inactivos)
 * POST             → Crear producto
 * PUT  ?id=X       → Actualizar producto
 * DELETE ?id=X     → Desactivar producto (soft delete)
 */
require_once __DIR__ . '/../config/auth.php';
verificarSesionAjax();

$method = $_SERVER['REQUEST_METHOD'];

// Para crear, editar o eliminar productos se requiere rol admin
// BUG-02 FIX: Se eliminó acceso sin autenticación. Ahora toda la ruta requiere sesión válida.
if ($method !== 'GET' && ($_SESSION['usuario_rol'] ?? '') !== 'admin') {
    jsonResponse(['success' => false, 'error' => 'Acceso denegado. Se requieren permisos de administrador.'], 403);
}

$db = getDB();

try {
    switch ($method) {

        // ── LISTAR ───────────────────────────────
        case 'GET':
            $productos = $db->query("
                SELECT p.*, c.nombre as categoria_nombre
                FROM productos p
                JOIN categorias c ON p.categoria_id = c.id
                ORDER BY p.categoria_id ASC, p.nombre ASC
            ")->fetchAll();

            $categorias = $db->query("
                SELECT id, nombre FROM categorias WHERE activo = 1 ORDER BY orden
            ")->fetchAll();

            jsonResponse(['success' => true, 'productos' => $productos, 'categorias' => $categorias]);
            break;

        // ── CREAR ────────────────────────────────
        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true);

            if (empty($input['nombre']) || !isset($input['precio']) || empty($input['categoria_id'])) {
                jsonResponse(['success' => false, 'error' => 'Faltan campos obligatorios'], 400);
            }

            $db->beginTransaction();

            $stmt = $db->prepare("
                INSERT INTO productos (nombre, precio, categoria_id, imagen, descripcion)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                trim($input['nombre']),
                floatval($input['precio']),
                intval($input['categoria_id']),
                $input['imagen'] ?? 'default.png',
                $input['descripcion'] ?? ''
            ]);
            $nuevoId = (int) $db->lastInsertId();

            // BUG-STOCK FIX: Sembrar stock_sucursal en todas las sucursales activas
            $stockInicial = isset($input['stock_inicial']) && $input['stock_inicial'] !== '' ? max(0, (int)$input['stock_inicial']) : 0;
            $alertaMinima = isset($input['alerta_minima']) && $input['alerta_minima'] !== '' ? max(0, (int)$input['alerta_minima']) : 10;
            $usuarioId    = (int) ($_SESSION['usuario_id'] ?? 1);

            $sucursales = $db->query("SELECT id FROM sucursales WHERE activo = 1")->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($sucursales)) {
                $stmtStock = $db->prepare("
                    INSERT INTO stock_sucursal (sucursal_id, producto_id, cantidad_disponible, alerta_minima)
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        cantidad_disponible = VALUES(cantidad_disponible),
                        alerta_minima       = VALUES(alerta_minima)
                ");
                $stmtMov = $db->prepare("
                    INSERT INTO stock_movimientos (sucursal_id, producto_id, usuario_id, tipo_movimiento, cantidad, stock_anterior, stock_posterior, motivo)
                    VALUES (?, ?, ?, 'ingreso_lote', ?, 0, ?, 'Stock inicial al crear producto')
                ");

                foreach ($sucursales as $sucId) {
                    $stmtStock->execute([$sucId, $nuevoId, $stockInicial, $alertaMinima]);
                    if ($stockInicial > 0) {
                        $stmtMov->execute([$sucId, $nuevoId, $usuarioId, $stockInicial, $stockInicial]);
                    }
                }
            }

            $db->commit();

            jsonResponse(['success' => true, 'id' => $nuevoId, 'mensaje' => 'Producto creado con stock inicial']);
            break;

        // ── ACTUALIZAR ───────────────────────────
        case 'PUT':
            $id = $_GET['id'] ?? null;
            if (!$id) jsonResponse(['success' => false, 'error' => 'ID requerido'], 400);

            $input = json_decode(file_get_contents('php://input'), true);

            $stmt = $db->prepare("
                UPDATE productos 
                SET nombre = ?, precio = ?, categoria_id = ?, imagen = ?, descripcion = ?, activo = ?
                WHERE id = ?
            ");
            $stmt->execute([
                trim($input['nombre']),
                floatval($input['precio']),
                intval($input['categoria_id']),
                $input['imagen'] ?? 'default.png',
                $input['descripcion'] ?? '',
                intval($input['activo'] ?? 1),
                intval($id)
            ]);

            jsonResponse(['success' => true, 'mensaje' => 'Producto actualizado']);
            break;

        // ── ELIMINAR (soft delete) ───────────────
        case 'DELETE':
            $id = $_GET['id'] ?? null;
            if (!$id) jsonResponse(['success' => false, 'error' => 'ID requerido'], 400);

            $stmt = $db->prepare("UPDATE productos SET activo = 0 WHERE id = ?");
            $stmt->execute([intval($id)]);

            jsonResponse(['success' => true, 'mensaje' => 'Producto desactivado']);
            break;

        default:
            jsonResponse(['success' => false, 'error' => 'Método no soportado'], 405);
    }

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    jsonResponse(['success' => false, 'error' => 'Error en operación CRUD: ' . $e->getMessage()], 500);
}
