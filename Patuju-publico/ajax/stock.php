<?php
/**
 * PATUJU POS — Gestión de Stock y Precios por Sucursal (Fase 2)
 *
 * GET                           → Lista stock y precios de la sucursal
 * GET  ?sucursal_id=X           → Lista stock de esa sucursal (admin ve cualquiera)
 * GET  ?movimientos=1           → Lista el historial de movimientos de inventario
 * POST {accion:'ingreso_lote', items:[...], motivo} → Ingreso masivo de mercadería
 * POST {accion:'ajuste', producto_id, cantidad, precio_sucursal, alerta_minima, motivo} → Ajuste individual
 */
require_once __DIR__ . '/../config/auth.php';
verificarSesionAjax();

$db         = getDB();
$rol        = $_SESSION['usuario_rol'];
$sucSesion  = (int) ($_SESSION['sucursal_id'] ?? 0);
$usuarioId  = (int) $_SESSION['usuario_id'];

// ── GET: consultar stock o movimientos ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Determinar sucursal a consultar
    if ($rol === 'admin' || $rol === 'encargado') {
        $sucId = isset($_GET['sucursal_id']) && $_GET['sucursal_id'] !== '' ? (int) $_GET['sucursal_id'] : ($sucSesion ?: null);
    } else {
        $sucId = $sucSesion;
    }

    // ── Resumen de Sucursales (Vista Principal de Encargado) ───────────────
    if (isset($_GET['resumen_sucursales'])) {
        $whereSuc = "";
        $paramsSuc = [];
        if ($rol !== 'admin' && $sucSesion > 0 && empty($_GET['todas'])) {
            $whereSuc = "AND s.id = ?";
            $paramsSuc[] = $sucSesion;
        }

        $sqlResumen = "
            SELECT 
                s.id,
                s.codigo,
                s.nombre,
                s.direccion,
                s.ciudad,
                s.departamento,
                COALESCE(u_enc.encargado_nombre, 'Sin asignar') AS encargado_nombre,
                COALESCE(stk.total_productos, 0) AS total_productos,
                COALESCE(stk.stock_total_unidades, 0) AS stock_total_unidades,
                COALESCE(stk.stock_optimo_count, 0) AS stock_optimo_count,
                COALESCE(stk.stock_bajo_count, 0) AS stock_bajo_count,
                COALESCE(stk.stock_agotado_count, 0) AS stock_agotado_count,
                COALESCE(t.turno_estado, 'cerrado') AS turno_estado,
                t.turno_cajero,
                COALESCE(v_hoy.total_ventas, 0.00) AS ventas_hoy,
                COALESCE(v_hoy.count_ventas, 0) AS num_ventas_hoy
            FROM sucursales s
            LEFT JOIN (
                SELECT sucursal_id, GROUP_CONCAT(DISTINCT nombre_display SEPARATOR ', ') AS encargado_nombre
                FROM usuarios
                WHERE rol = 'encargado' AND activo = 1
                GROUP BY sucursal_id
            ) u_enc ON u_enc.sucursal_id = s.id
            LEFT JOIN (
                SELECT 
                    ss.sucursal_id,
                    COUNT(DISTINCT ss.producto_id) AS total_productos,
                    COALESCE(SUM(ss.cantidad_disponible), 0) AS stock_total_unidades,
                    COALESCE(SUM(CASE WHEN ss.cantidad_disponible > ss.alerta_minima THEN 1 ELSE 0 END), 0) AS stock_optimo_count,
                    COALESCE(SUM(CASE WHEN ss.cantidad_disponible > 0 AND ss.cantidad_disponible <= ss.alerta_minima THEN 1 ELSE 0 END), 0) AS stock_bajo_count,
                    COALESCE(SUM(CASE WHEN ss.cantidad_disponible = 0 THEN 1 ELSE 0 END), 0) AS stock_agotado_count
                FROM stock_sucursal ss
                INNER JOIN productos p ON p.id = ss.producto_id AND p.activo = 1
                GROUP BY ss.sucursal_id
            ) stk ON stk.sucursal_id = s.id
            LEFT JOIN (
                SELECT 
                    tc.sucursal_id,
                    'abierto' AS turno_estado,
                    GROUP_CONCAT(DISTINCT u.nombre_display SEPARATOR ', ') AS turno_cajero
                FROM turnos_caja tc
                JOIN usuarios u ON u.id = tc.usuario_id
                WHERE tc.estado = 'abierto'
                GROUP BY tc.sucursal_id
            ) t ON t.sucursal_id = s.id
            LEFT JOIN (
                SELECT sucursal_id, SUM(total) AS total_ventas, COUNT(*) AS count_ventas 
                FROM ventas 
                WHERE DATE(fecha) = CURDATE() 
                GROUP BY sucursal_id
            ) v_hoy ON v_hoy.sucursal_id = s.id
            WHERE s.activo = 1 {$whereSuc}
            GROUP BY s.id
            ORDER BY s.id ASC
        ";

        $stmtResumen = $db->prepare($sqlResumen);
        $stmtResumen->execute($paramsSuc);
        jsonResponse([
            'success' => true,
            'sucursal_sesion_id' => $sucSesion,
            'fecha_actual' => date('d/m/Y'),
            'sucursales' => $stmtResumen->fetchAll()
        ]);
    }

    // Historial de movimientos
    if (isset($_GET['movimientos'])) {
        if ($sucId) {
            $stmtMov = $db->prepare("
                SELECT sm.*, p.nombre AS producto_nombre, u.nombre_display AS usuario_nombre, s.nombre AS sucursal_nombre
                FROM stock_movimientos sm
                JOIN productos p ON p.id = sm.producto_id
                JOIN usuarios u ON u.id = sm.usuario_id
                JOIN sucursales s ON s.id = sm.sucursal_id
                WHERE sm.sucursal_id = ?
                ORDER BY sm.created_at DESC
                LIMIT 50
            ");
            $stmtMov->execute([$sucId]);
        } else {
            $stmtMov = $db->prepare("
                SELECT sm.*, p.nombre AS producto_nombre, u.nombre_display AS usuario_nombre, s.nombre AS sucursal_nombre
                FROM stock_movimientos sm
                JOIN productos p ON p.id = sm.producto_id
                JOIN usuarios u ON u.id = sm.usuario_id
                JOIN sucursales s ON s.id = sm.sucursal_id
                ORDER BY sm.created_at DESC
                LIMIT 50
            ");
            $stmtMov->execute();
        }
        jsonResponse(['success' => true, 'movimientos' => $stmtMov->fetchAll()]);
    }

    // Listado de stock de productos (Fase 2: Catálogo completo visible por sucursal)
    if (!$sucId) {
        // Admin sin filtro → todas las sucursales
        $stmt = $db->prepare("
            SELECT su.id AS sucursal_id, su.nombre AS sucursal_nombre,
                   p.id AS producto_id,  p.nombre  AS producto_nombre, p.imagen,
                   p.tipo_rotacion,
                   p.precio AS precio_base,
                   ss.precio_sucursal,
                   COALESCE(ss.precio_sucursal, p.precio) AS precio_efectivo,
                   COALESCE(ss.cantidad_disponible, 0) AS cantidad_disponible,
                   COALESCE(ss.alerta_minima, 10) AS alerta_minima,
                   COALESCE(ss.stock_predeterminado, 0) AS stock_predeterminado,
                   COALESCE(ss.disponible_venta, 1) AS disponible_venta,
                   ss.updated_at,
                   c.nombre AS categoria_nombre
            FROM   sucursales su
            INNER JOIN productos p ON p.activo = 1
            LEFT JOIN stock_sucursal ss ON ss.sucursal_id = su.id AND ss.producto_id = p.id
            LEFT JOIN categorias c ON c.id = p.categoria_id
            WHERE  su.activo = 1
            ORDER  BY su.nombre, c.orden, p.nombre
        ");
        $stmt->execute();
    } else {
        $stmt = $db->prepare("
            SELECT su.id AS sucursal_id, su.nombre AS sucursal_nombre,
                   p.id AS producto_id,  p.nombre  AS producto_nombre, p.imagen,
                   p.tipo_rotacion,
                   p.precio AS precio_base,
                   ss.precio_sucursal,
                   COALESCE(ss.precio_sucursal, p.precio) AS precio_efectivo,
                   COALESCE(ss.cantidad_disponible, 0) AS cantidad_disponible,
                   COALESCE(ss.alerta_minima, 10) AS alerta_minima,
                   COALESCE(ss.stock_predeterminado, 0) AS stock_predeterminado,
                   COALESCE(ss.disponible_venta, 1) AS disponible_venta,
                   ss.updated_at,
                   c.nombre AS categoria_nombre
            FROM   sucursales su
            INNER JOIN productos p ON p.activo = 1
            LEFT JOIN stock_sucursal ss ON ss.sucursal_id = su.id AND ss.producto_id = p.id
            LEFT JOIN categorias c ON c.id = p.categoria_id
            WHERE  su.id = ?
            ORDER  BY c.orden, p.nombre
        ");
        $stmt->execute([$sucId]);
    }

    jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
}

// ── POST: actualizar o ingresar stock ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Solo encargado o admin pueden modificar stock o precios
    if ($rol !== 'admin' && $rol !== 'encargado') {
        jsonResponse(['success' => false, 'error' => 'No tienes permisos de encargado ni administrador'], 403);
    }

    $input  = json_decode(file_get_contents('php://input'), true);
    $accion = $input['accion'] ?? 'ajuste';

    // Determinar sucursal destino
    if ($rol === 'admin' && !empty($input['sucursal_id'])) {
        $sucId = (int) $input['sucursal_id'];
    } elseif ($sucSesion) {
        $sucId = $sucSesion;
    } else {
        jsonResponse(['success' => false, 'error' => 'Sin sucursal asociada'], 403);
    }

    // ── ACCIÓN: INGRESO DE LOTE (Sumar stock por recepción / horneada) ─────
    if ($accion === 'ingreso_lote') {
        $items  = $input['items'] ?? [];
        $motivo = trim($input['motivo'] ?? 'Ingreso de mercadería / Horneada');

        if (empty($items) || !is_array($items)) {
            jsonResponse(['success' => false, 'error' => 'Lista de items vacía'], 400);
        }

        try {
            $db->beginTransaction();

            $stmtSelect = $db->prepare("SELECT cantidad_disponible FROM stock_sucursal WHERE sucursal_id = ? AND producto_id = ? FOR UPDATE");
            $stmtUpsert = $db->prepare("
                INSERT INTO stock_sucursal (sucursal_id, producto_id, cantidad_disponible, alerta_minima)
                VALUES (?, ?, ?, 10)
                ON DUPLICATE KEY UPDATE
                    cantidad_disponible = cantidad_disponible + VALUES(cantidad_disponible)
            ");
            $stmtMov = $db->prepare("
                INSERT INTO stock_movimientos (sucursal_id, producto_id, usuario_id, tipo_movimiento, cantidad, stock_anterior, stock_posterior, motivo)
                VALUES (?, ?, ?, 'ingreso_lote', ?, ?, ?, ?)
            ");

            $totalIngresado = 0;
            foreach ($items as $item) {
                $prodId   = (int) ($item['producto_id'] ?? 0);
                $cantidad = (int) ($item['cantidad'] ?? 0);

                if ($prodId <= 0 || $cantidad <= 0) {
                    continue;
                }

                // Obtener stock actual
                $stmtSelect->execute([$sucId, $prodId]);
                $rowStock = $stmtSelect->fetch();
                $stockAnterior = $rowStock ? (int) $rowStock['cantidad_disponible'] : 0;
                $stockPosterior = $stockAnterior + $cantidad;

                // Actualizar o insertar
                $stmtUpsert->execute([$sucId, $prodId, $cantidad]);

                // Registrar movimiento
                $stmtMov->execute([$sucId, $prodId, $usuarioId, $cantidad, $stockAnterior, $stockPosterior, $motivo]);
                $totalIngresado += $cantidad;
            }

            $db->commit();
            jsonResponse([
                'success' => true,
                'message' => "Se registraron {$totalIngresado} unidades en el inventario exitosamente."
            ]);
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            jsonResponse(['success' => false, 'error' => 'Error al registrar lote: ' . $e->getMessage()], 500);
        }
    }

    // ── ACCIÓN: CARGAR STOCK DIARIO PREDETERMINADO (Horneada matutina) ─────
    if ($accion === 'cargar_predeterminado_diario') {
        $itemsRecibidos = $input['items'] ?? null;
        $motivo = trim($input['motivo'] ?? 'Apertura de jornada: Horneada matutina');

        try {
            $db->beginTransaction();

            // Si no se pasaron items manuales, buscar todos los productos de rotación diaria con su plantilla
            if (empty($itemsRecibidos)) {
                $stmtDiarios = $db->prepare("
                    SELECT p.id AS producto_id, p.nombre, COALESCE(ss.stock_predeterminado, 0) AS predeterminado
                    FROM productos p
                    LEFT JOIN stock_sucursal ss ON ss.producto_id = p.id AND ss.sucursal_id = ?
                    WHERE p.activo = 1 AND p.tipo_rotacion = 'diaria'
                ");
                $stmtDiarios->execute([$sucId]);
                $itemsRecibidos = [];
                foreach ($stmtDiarios->fetchAll() as $rowD) {
                    $cant = (int)$rowD['predeterminado'];
                    if ($cant > 0) {
                        $itemsRecibidos[] = ['producto_id' => (int)$rowD['producto_id'], 'cantidad' => $cant];
                    }
                }
            }

            if (empty($itemsRecibidos)) {
                $db->rollBack();
                jsonResponse(['success' => false, 'error' => 'No hay productos diarios con cantidades configuradas para cargar.'], 400);
            }

            $stmtSelect = $db->prepare("SELECT cantidad_disponible FROM stock_sucursal WHERE sucursal_id = ? AND producto_id = ? FOR UPDATE");
            $stmtUpsert = $db->prepare("
                INSERT INTO stock_sucursal (sucursal_id, producto_id, cantidad_disponible, alerta_minima, stock_predeterminado, disponible_venta)
                VALUES (?, ?, ?, 10, ?, 1)
                ON DUPLICATE KEY UPDATE
                    cantidad_disponible = cantidad_disponible + VALUES(cantidad_disponible),
                    disponible_venta = 1
            ");
            $stmtMov = $db->prepare("
                INSERT INTO stock_movimientos (sucursal_id, producto_id, usuario_id, tipo_movimiento, cantidad, stock_anterior, stock_posterior, motivo)
                VALUES (?, ?, ?, 'ingreso_lote', ?, ?, ?, ?)
            ");

            $totalIngresado = 0;
            foreach ($itemsRecibidos as $it) {
                $prodId = (int)($it['producto_id'] ?? 0);
                $cant = (int)($it['cantidad'] ?? 0);
                if ($prodId <= 0 || $cant <= 0) continue;

                $stmtSelect->execute([$sucId, $prodId]);
                $rowStock = $stmtSelect->fetch();
                $stockAnterior = $rowStock ? (int)$rowStock['cantidad_disponible'] : 0;
                $stockPosterior = $stockAnterior + $cant;

                $stmtUpsert->execute([$sucId, $prodId, $cant, $cant]);
                $stmtMov->execute([$sucId, $prodId, $usuarioId, $cant, $stockAnterior, $stockPosterior, $motivo]);
                $totalIngresado += $cant;
            }

            $db->commit();
            jsonResponse([
                'success' => true,
                'message' => "Se cargaron {$totalIngresado} unidades para la rotación diaria exitosamente."
            ]);
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            jsonResponse(['success' => false, 'error' => 'Error al cargar stock diario: ' . $e->getMessage()], 500);
        }
    }

    // ── ACCIÓN: CAMBIAR DISPONIBILIDAD EN CAJA (CRUD Delete/Pause local) ─────
    if ($accion === 'cambiar_disponibilidad') {
        $productoId = (int)($input['producto_id'] ?? 0);
        $disponible = isset($input['disponible_venta']) ? ((int)$input['disponible_venta'] ? 1 : 0) : 1;

        if ($productoId <= 0) {
            jsonResponse(['success' => false, 'error' => 'producto_id es requerido'], 400);
        }

        try {
            $stmtDisp = $db->prepare("
                INSERT INTO stock_sucursal (sucursal_id, producto_id, cantidad_disponible, alerta_minima, disponible_venta)
                VALUES (?, ?, 0, 10, ?)
                ON DUPLICATE KEY UPDATE disponible_venta = VALUES(disponible_venta)
            ");
            $stmtDisp->execute([$sucId, $productoId, $disponible]);

            $estadoTexto = $disponible ? 'habilitado para venta en caja' : 'pausado en caja';
            jsonResponse([
                'success' => true,
                'disponible_venta' => $disponible,
                'message' => "Producto {$estadoTexto} exitosamente."
            ]);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'error' => 'Error al actualizar disponibilidad: ' . $e->getMessage()], 500);
        }
    }

    // ── ACCIÓN: CIERRE DE MERMA DIARIA (Para salteñas al terminar la jornada) ─
    if ($accion === 'cierre_merma_diaria') {
        $motivo = trim($input['motivo'] ?? 'Cierre de jornada / Merma de salteñas sobrantes');

        try {
            $db->beginTransaction();

            $stmtDiarios = $db->prepare("
                SELECT ss.producto_id, ss.cantidad_disponible
                FROM stock_sucursal ss
                INNER JOIN productos p ON p.id = ss.producto_id
                WHERE ss.sucursal_id = ? AND p.tipo_rotacion = 'diaria' AND ss.cantidad_disponible > 0
                FOR UPDATE
            ");
            $stmtDiarios->execute([$sucId]);
            $remanentes = $stmtDiarios->fetchAll();

            $stmtUpdateCero = $db->prepare("UPDATE stock_sucursal SET cantidad_disponible = 0 WHERE sucursal_id = ? AND producto_id = ?");
            $stmtMovMerma   = $db->prepare("
                INSERT INTO stock_movimientos (sucursal_id, producto_id, usuario_id, tipo_movimiento, cantidad, stock_anterior, stock_posterior, motivo)
                VALUES (?, ?, ?, 'merma', ?, ?, 0, ?)
            ");

            $totalMermas = 0;
            foreach ($remanentes as $rem) {
                $pId = (int)$rem['producto_id'];
                $cantSobro = (int)$rem['cantidad_disponible'];
                $stmtUpdateCero->execute([$sucId, $pId]);
                $stmtMovMerma->execute([$sucId, $pId, $usuarioId, -$cantSobro, $cantSobro, $motivo]);
                $totalMermas += $cantSobro;
            }

            $db->commit();
            jsonResponse([
                'success' => true,
                'message' => "Cierre diario completado. Se registraron {$totalMermas} unidades sobrantes como merma."
            ]);
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            jsonResponse(['success' => false, 'error' => 'Error al registrar merma diaria: ' . $e->getMessage()], 500);
        }
    }

    // ── ACCIÓN: AJUSTE INDIVIDUAL (Stock exacto, Precio local, Alerta mínima, Predeterminado) ──
    if ($accion === 'ajuste') {
        $productoId     = isset($input['producto_id']) ? (int) $input['producto_id'] : 0;
        $cantidad       = isset($input['cantidad']) && $input['cantidad'] !== '' ? (int) $input['cantidad'] : null;
        $precioSucursal = isset($input['precio_sucursal']) && $input['precio_sucursal'] !== '' ? round(floatval($input['precio_sucursal']), 2) : null;
        $alertaMinima   = isset($input['alerta_minima']) && $input['alerta_minima'] !== '' ? (int) $input['alerta_minima'] : null;
        $stockPred      = isset($input['stock_predeterminado']) && $input['stock_predeterminado'] !== '' ? max(0, (int)$input['stock_predeterminado']) : null;
        $disponible     = isset($input['disponible_venta']) ? ((int)$input['disponible_venta'] ? 1 : 0) : null;
        $motivo         = trim($input['motivo'] ?? 'Ajuste manual de inventario / precios');

        if (!$productoId) {
            jsonResponse(['success' => false, 'error' => 'producto_id es requerido'], 400);
        }

        if ($cantidad !== null && $cantidad < 0) {
            jsonResponse(['success' => false, 'error' => 'La cantidad no puede ser negativa'], 400);
        }
        if ($precioSucursal !== null && $precioSucursal <= 0) {
            jsonResponse(['success' => false, 'error' => 'El precio debe ser mayor a 0'], 400);
        }

        try {
            $db->beginTransaction();

            $stmtSelect = $db->prepare("SELECT cantidad_disponible, precio_sucursal, alerta_minima, stock_predeterminado, disponible_venta FROM stock_sucursal WHERE sucursal_id = ? AND producto_id = ? FOR UPDATE");
            $stmtSelect->execute([$sucId, $productoId]);
            $current = $stmtSelect->fetch();

            $stockAnterior = $current ? (int) $current['cantidad_disponible'] : 0;
            $stockNuevo    = ($cantidad !== null) ? $cantidad : $stockAnterior;
            $alertaFinal   = ($alertaMinima !== null) ? $alertaMinima : ($current ? (int)$current['alerta_minima'] : 10);
            $predFinal     = ($stockPred !== null) ? $stockPred : ($current ? (int)$current['stock_predeterminado'] : 0);
            $dispFinal     = ($disponible !== null) ? $disponible : ($current ? (int)$current['disponible_venta'] : 1);
            $precioFinal   = array_key_exists('precio_sucursal', $input) ? $precioSucursal : ($current ? $current['precio_sucursal'] : null);

            $stmtUpsert = $db->prepare("
                INSERT INTO stock_sucursal (sucursal_id, producto_id, cantidad_disponible, alerta_minima, precio_sucursal, stock_predeterminado, disponible_venta)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    cantidad_disponible  = VALUES(cantidad_disponible),
                    alerta_minima        = VALUES(alerta_minima),
                    precio_sucursal      = VALUES(precio_sucursal),
                    stock_predeterminado = VALUES(stock_predeterminado),
                    disponible_venta     = VALUES(disponible_venta)
            ");
            $stmtUpsert->execute([$sucId, $productoId, $stockNuevo, $alertaFinal, $precioFinal, $predFinal, $dispFinal]);

            // Registrar movimiento de stock si la cantidad cambió
            if ($stockNuevo !== $stockAnterior) {
                $diferencia = $stockNuevo - $stockAnterior;
                $stmtMov = $db->prepare("
                    INSERT INTO stock_movimientos (sucursal_id, producto_id, usuario_id, tipo_movimiento, cantidad, stock_anterior, stock_posterior, motivo)
                    VALUES (?, ?, ?, 'ajuste_manual', ?, ?, ?, ?)
                ");
                $stmtMov->execute([$sucId, $productoId, $usuarioId, $diferencia, $stockAnterior, $stockNuevo, $motivo]);
            }

            $db->commit();
            jsonResponse(['success' => true, 'message' => 'Parámetros del producto actualizados correctamente']);
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            jsonResponse(['success' => false, 'error' => 'Error al actualizar: ' . $e->getMessage()], 500);
        }
    }

    jsonResponse(['success' => false, 'error' => "Acción '{$accion}' no reconocida"], 400);
}

jsonResponse(['success' => false, 'error' => 'Método no permitido'], 405);
