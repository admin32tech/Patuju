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

/**
 * Valida y normaliza los datos de un producto antes de insertarlo o actualizarlo.
 *
 * @param array<string, mixed>|null $input
 * @return array{nombre:string,precio:float,categoria_id:int,tipo_rotacion:string}
 */
function validarDatosProducto(PDO $db, ?array $input): array
{
    if (!is_array($input)) {
        jsonResponse(['success' => false, 'error' => 'Datos inválidos'], 400);
    }

    $nombre       = trim((string) ($input['nombre'] ?? ''));
    $precioRaw    = $input['precio'] ?? null;
    $categoriaId  = (int) ($input['categoria_id'] ?? 0);
    $tipoRotacion = $input['tipo_rotacion'] ?? 'diaria';

    if ($nombre === '' || $precioRaw === null || $precioRaw === '' || $categoriaId <= 0) {
        jsonResponse(['success' => false, 'error' => 'Faltan campos obligatorios'], 400);
    }

    if (!is_numeric($precioRaw) || (float) $precioRaw <= 0) {
        jsonResponse(['success' => false, 'error' => 'El precio debe ser un número mayor a 0'], 400);
    }

    if (!in_array($tipoRotacion, ['diaria', 'lenta'], true)) {
        jsonResponse(['success' => false, 'error' => "El tipo de rotación debe ser 'diaria' o 'lenta'"], 400);
    }

    $stmtCategoria = $db->prepare('SELECT id FROM categorias WHERE id = ? AND activo = 1');
    $stmtCategoria->execute([$categoriaId]);
    if (!$stmtCategoria->fetchColumn()) {
        jsonResponse(['success' => false, 'error' => 'La categoría no existe o está inactiva'], 400);
    }

    return [
        'nombre'        => $nombre,
        'precio'        => round((float) $precioRaw, 2),
        'categoria_id'  => $categoriaId,
        'tipo_rotacion' => $tipoRotacion,
    ];
}

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
            $datos = validarDatosProducto($db, $input);

            $stockInicialRaw = $input['stock_inicial'] ?? 0;
            $alertaMinimaRaw = $input['alerta_minima'] ?? 10;
            if (!is_numeric($stockInicialRaw) || (int) $stockInicialRaw < 0) {
                jsonResponse(['success' => false, 'error' => 'El stock inicial debe ser un número mayor o igual a 0'], 400);
            }
            if (!is_numeric($alertaMinimaRaw) || (int) $alertaMinimaRaw < 0) {
                jsonResponse(['success' => false, 'error' => 'La alerta mínima debe ser un número mayor o igual a 0'], 400);
            }

            $db->beginTransaction();

            $stmt = $db->prepare("
                INSERT INTO productos (nombre, precio, categoria_id, tipo_rotacion, imagen, descripcion)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $datos['nombre'],
                $datos['precio'],
                $datos['categoria_id'],
                $datos['tipo_rotacion'],
                $input['imagen'] ?? 'default.png',
                $input['descripcion'] ?? ''
            ]);
            $nuevoId = (int) $db->lastInsertId();

            // BUG-STOCK FIX: Sembrar stock_sucursal en todas las sucursales activas
            $stockInicial = (int) $stockInicialRaw;
            $alertaMinima = (int) $alertaMinimaRaw;
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
            $id = (int) ($_GET['id'] ?? 0);
            if ($id <= 0) jsonResponse(['success' => false, 'error' => 'ID requerido'], 400);

            $input = json_decode(file_get_contents('php://input'), true);
            $datos = validarDatosProducto($db, $input);

            $stmtExiste = $db->prepare('SELECT id FROM productos WHERE id = ?');
            $stmtExiste->execute([$id]);
            if (!$stmtExiste->fetchColumn()) {
                jsonResponse(['success' => false, 'error' => 'Producto no encontrado'], 404);
            }

            $stmt = $db->prepare("
                UPDATE productos 
                SET nombre = ?, precio = ?, categoria_id = ?, tipo_rotacion = ?, imagen = ?, descripcion = ?, activo = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $datos['nombre'],
                $datos['precio'],
                $datos['categoria_id'],
                $datos['tipo_rotacion'],
                $input['imagen'] ?? 'default.png',
                $input['descripcion'] ?? '',
                intval($input['activo'] ?? 1),
                $id
            ]);

            jsonResponse(['success' => true, 'mensaje' => 'Producto actualizado']);
            break;

        // ── ELIMINAR (soft delete) ───────────────
        case 'DELETE':
            $id = (int) ($_GET['id'] ?? 0);
            if ($id <= 0) jsonResponse(['success' => false, 'error' => 'ID requerido'], 400);

            $stmtExiste = $db->prepare('SELECT id FROM productos WHERE id = ?');
            $stmtExiste->execute([$id]);
            if (!$stmtExiste->fetchColumn()) {
                jsonResponse(['success' => false, 'error' => 'Producto no encontrado'], 404);
            }

            $stmt = $db->prepare("UPDATE productos SET activo = 0 WHERE id = ?");
            $stmt->execute([$id]);

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
