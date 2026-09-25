<?php
/**
 * PATUJU POS - Endpoint: Registrar Venta (Fase 2 + Trazabilidad)
 * POST → Recibe JSON con items del carrito, valida turno, descuenta stock atómicamente,
 *        calcula el vuelto en el servidor y registra el movimiento de inventario.
 */
require_once __DIR__ . '/../config/auth.php';
verificarSesionAjax('caja');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Método no permitido'], 405);
}

// Leer JSON del body
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || empty($input['items']) || !is_array($input['items'])) {
    jsonResponse(['success' => false, 'error' => 'No hay productos en la venta'], 400);
}

$items = $input['items'];
$nota  = isset($input['nota']) ? trim($input['nota']) : '';

// Método de pago y validaciones
$metodo_pago = $input['metodo_pago'] ?? 'efectivo';
if (!in_array($metodo_pago, ['efectivo', 'qr', 'tarjeta'], true)) {
    $metodo_pago = 'efectivo';
}

$sucursal_id = (int) ($_SESSION['sucursal_id'] ?? 1);
$usuario_id  = (int) ($_SESSION['usuario_id'] ?? 0);

try {
    $db = getDB();
    $db->beginTransaction();

    // 1. Verificar turno abierto para este cajero en su sucursal
    $stmtTurno = $db->prepare("
        SELECT id FROM turnos_caja 
        WHERE usuario_id = ? AND sucursal_id = ? AND estado = 'abierto' 
        LIMIT 1 FOR UPDATE
    ");
    $stmtTurno->execute([$usuario_id, $sucursal_id]);
    $turno = $stmtTurno->fetch();
    
    if (!$turno) {
        throw new Exception("No tienes un turno de caja abierto. Abre caja antes de registrar ventas.");
    }
    $turno_id = (int) $turno['id'];

    // 2. Calcular total general y verificar disponibilidad de stock con bloqueo FOR UPDATE
    $total = 0.0;
    $itemsCount = 0;
    $itemsProcesados = [];

    $stmtCheckStock = $db->prepare("
        SELECT ss.cantidad_disponible, 
               COALESCE(ss.precio_sucursal, p.precio) AS precio_real,
               p.nombre
        FROM stock_sucursal ss
        JOIN productos p ON p.id = ss.producto_id
        WHERE ss.sucursal_id = ? AND ss.producto_id = ? AND p.activo = 1
        FOR UPDATE
    ");

    foreach ($items as $item) {
        $prod_id  = (int) ($item['id'] ?? 0);
        $cantidad = (int) ($item['cantidad'] ?? 0);

        if ($prod_id <= 0 || $cantidad <= 0) {
            continue;
        }

        $stmtCheckStock->execute([$sucursal_id, $prod_id]);
        $stock = $stmtCheckStock->fetch();

        if (!$stock) {
            throw new Exception("El producto ID #{$prod_id} no está habilitado para esta sucursal.");
        }

        $stockDisponible = (int) $stock['cantidad_disponible'];
        $nombreProducto  = $stock['nombre'];
        $precioUnitario  = (float) $stock['precio_real'];

        if ($stockDisponible < $cantidad) {
            throw new Exception("Stock insuficiente para '{$nombreProducto}'. Disponible: {$stockDisponible}, Solicitado: {$cantidad}");
        }

        $subtotal = round($precioUnitario * $cantidad, 2);
        $total += $subtotal;
        $itemsCount += $cantidad;

        $itemsProcesados[] = [
            'id'              => $prod_id,
            'nombre'          => $nombreProducto,
            'cantidad'        => $cantidad,
            'precio_unitario' => $precioUnitario,
            'subtotal'        => $subtotal,
            'stock_anterior'  => $stockDisponible,
            'stock_posterior' => $stockDisponible - $cantidad
        ];
    }

    if (empty($itemsProcesados)) {
        throw new Exception("No se encontraron productos válidos para procesar.");
    }

    $total = round($total, 2);

    // 3. Validar montos y calcular vuelto en el servidor (no confiar en el cliente)
    if ($metodo_pago === 'efectivo') {
        $monto_recibido = isset($input['monto_recibido']) ? round(floatval($input['monto_recibido']), 2) : 0.0;
        if ($monto_recibido < $total) {
            throw new Exception("Monto recibido (Bs. " . number_format($monto_recibido, 2) . ") insuficiente para cubrir el total (Bs. " . number_format($total, 2) . ").");
        }
        $cambio = round($monto_recibido - $total, 2);
    } else {
        // QR o Tarjeta
        $monto_recibido = $total;
        $cambio = 0.00;
    }

    // 4. Descontar stock e insertar movimiento de trazabilidad
    $stmtUpdateStock = $db->prepare("
        UPDATE stock_sucursal 
        SET cantidad_disponible = cantidad_disponible - ? 
        WHERE sucursal_id = ? AND producto_id = ?
    ");

    $stmtMov = $db->prepare("
        INSERT INTO stock_movimientos (sucursal_id, producto_id, usuario_id, tipo_movimiento, cantidad, stock_anterior, stock_posterior, motivo)
        VALUES (?, ?, ?, 'venta', ?, ?, ?, ?)
    ");

    foreach ($itemsProcesados as $ip) {
        $stmtUpdateStock->execute([$ip['cantidad'], $sucursal_id, $ip['id']]);
    }

    // 5. Insertar cabecera de venta
    $stmtVenta = $db->prepare("
        INSERT INTO ventas (total, items_count, sucursal_id, usuario_id, metodo_pago, monto_recibido, cambio, turno_id, nota) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmtVenta->execute([
        $total, 
        $itemsCount, 
        $sucursal_id, 
        $usuario_id, 
        $metodo_pago, 
        $monto_recibido, 
        $cambio, 
        $turno_id, 
        $nota
    ]);
    $ventaId = (int) $db->lastInsertId();

    // 6. Insertar detalle de venta y registrar movimientos enlazados a la venta
    $stmtDetalle = $db->prepare("
        INSERT INTO detalle_ventas (venta_id, producto_id, producto_nombre, cantidad, precio_unitario, subtotal)
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    foreach ($itemsProcesados as $ip) {
        $stmtDetalle->execute([
            $ventaId,
            $ip['id'],
            $ip['nombre'],
            $ip['cantidad'],
            $ip['precio_unitario'],
            $ip['subtotal']
        ]);

        // Registro de auditoría de stock
        $stmtMov->execute([
            $sucursal_id,
            $ip['id'],
            $usuario_id,
            -$ip['cantidad'],
            $ip['stock_anterior'],
            $ip['stock_posterior'],
            "Venta #{$ventaId}"
        ]);
    }

    $db->commit();

    jsonResponse([
        'success'        => true,
        'venta_id'       => $ventaId,
        'total'          => number_format($total, 2),
        'items'          => $itemsCount,
        'metodo_pago'    => $metodo_pago,
        'monto_recibido' => number_format($monto_recibido, 2),
        'cambio'         => number_format($cambio, 2),
        'mensaje'        => "Venta #$ventaId registrada — Bs. " . number_format($total, 2)
    ]);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    jsonResponse(['success' => false, 'error' => $e->getMessage()], 400);
}
