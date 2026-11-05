<?php
/**
 * PATUJU POS — Turno de Caja / Arqueo (Fase 2)
 *
 * GET                        → Turno activo actual del usuario+sucursal
 * POST {accion:'abrir', monto_apertura}         → Abrir turno
 * POST {accion:'egreso', monto, motivo}          → Registrar egreso menor
 * POST {accion:'corte_x'}                        → Corte X (lectura sin cerrar)
 * POST {accion:'cerrar', monto_cierre, notas}    → Corte Z (cierre definitivo)
 * GET  ?abiertos=1[&sucursal_id=X]              → Turnos abiertos de las cajas a cargo (encargado/admin)
 * POST {accion:'cerrar_supervisado', turno_id, monto_cierre, notas} → Corte Z de una caja a cargo (encargado/admin)
 */
require_once __DIR__ . '/../config/auth.php';
verificarSesionAjax();

$db         = getDB();
$usuarioId  = (int) $_SESSION['usuario_id'];
$sucursalId = (int) ($_SESSION['sucursal_id'] ?? 0);
$rol        = $_SESSION['usuario_rol'];

// ── GET: consultar turno activo o auditoría de turnos ─────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    // ── Auditoría de turnos cerrados (Cortes Z) ───────────────────────────
    if (isset($_GET['auditoria'])) {
        if ($rol !== 'admin' && $rol !== 'encargado') {
            jsonResponse(['success' => false, 'error' => 'Sin permisos para ver auditoría de turnos'], 403);
        }

        $where = ["1=1"];
        $params = [];

        if ($rol === 'admin') {
            if (!empty($_GET['sucursal_id'])) {
                $where[] = "t.sucursal_id = ?";
                $params[] = (int) $_GET['sucursal_id'];
            }
        } else {
            // Encargado ve todas sus sucursales asignadas (N:M);
            // un sucursal_id manipulado (0, 'abc', ajena) responde 403
            $filtroSuc = sucursalSolicitadaPermitida($_GET['sucursal_id'] ?? null);
            $asignadas = obtenerSucursalesAsignadas();

            if ($filtroSuc !== null) {
                $where[] = "t.sucursal_id = ?";
                $params[] = $filtroSuc;
            } elseif (!empty($asignadas)) {
                $placeholders = implode(',', array_fill(0, count($asignadas), '?'));
                $where[] = "t.sucursal_id IN ($placeholders)";
                $params = array_merge($params, $asignadas);
            } else {
                $where[] = "1=0";
            }
        }

        if (!empty($_GET['estado'])) {
            $where[] = "t.estado = ?";
            $params[] = $_GET['estado'];
        }

        if (!empty($_GET['fecha_desde'])) {
            $where[] = "DATE(t.hora_apertura) >= ?";
            $params[] = $_GET['fecha_desde'];
        }
        if (!empty($_GET['fecha_hasta'])) {
            $where[] = "DATE(t.hora_apertura) <= ?";
            $params[] = $_GET['fecha_hasta'];
        }

        $whereSql = implode(" AND ", $where);
        $stmtAud = $db->prepare("
            SELECT t.*, u.nombre_display AS cajero_nombre, s.nombre AS sucursal_nombre
            FROM   turnos_caja t
            JOIN   usuarios    u ON u.id = t.usuario_id
            JOIN   sucursales  s ON s.id = t.sucursal_id
            WHERE  {$whereSql}
            ORDER  BY t.hora_apertura DESC
            LIMIT  100
        ");
        $stmtAud->execute($params);
        $turnos = $stmtAud->fetchAll();

        // Cargar egresos resumidos
        $stmtEg = $db->prepare("SELECT monto, motivo, creado_at FROM egresos_caja WHERE turno_id = ? ORDER BY creado_at");
        foreach ($turnos as &$tur) {
            $stmtEg->execute([$tur['id']]);
            $tur['egresos_detalle'] = $stmtEg->fetchAll();
            $tur['monto_apertura']  = floatval($tur['monto_apertura']);
            $tur['monto_cierre']    = $tur['monto_cierre'] !== null ? floatval($tur['monto_cierre']) : null;
            $tur['ventas_sistema']  = $tur['ventas_sistema'] !== null ? floatval($tur['ventas_sistema']) : null;
            $tur['diferencia']      = $tur['diferencia'] !== null ? floatval($tur['diferencia']) : null;
            $tur['egresos_menores'] = floatval($tur['egresos_menores']);
        }

        jsonResponse(['success' => true, 'turnos' => $turnos]);
    }

    // ── Turnos abiertos de las cajas a cargo (encargado/admin) ────────────
    // Base del cierre supervisado: el encargado ve el Corte X de cada turno abierto
    // de sus sucursales (p. ej. un cajero que no cerró o que fue reasignado).
    if (isset($_GET['abiertos'])) {
        if ($rol !== 'admin' && $rol !== 'encargado') {
            jsonResponse(['success' => false, 'error' => 'Sin permisos para gestionar turnos'], 403);
        }

        $where  = ["t.estado = 'abierto'"];
        $params = [];
        // sucursal_id manipulado (0, 'abc', ajena) → 403
        $filtroSuc = sucursalSolicitadaPermitida($_GET['sucursal_id'] ?? null, 'cerrar_turno');
        if ($filtroSuc !== null) {
            $where[]  = "t.sucursal_id = ?";
            $params[] = $filtroSuc;
        } elseif ($rol !== 'admin') {
            $asignadas = obtenerSucursalesAsignadas();
            if (!empty($asignadas)) {
                $where[] = "t.sucursal_id IN (" . implode(',', array_fill(0, count($asignadas), '?')) . ")";
                $params  = array_merge($params, $asignadas);
            } else {
                $where[] = "1=0";
            }
        }

        $stmtAbiertos = $db->prepare("
            SELECT t.*, u.nombre_display AS cajero_nombre, s.nombre AS sucursal_nombre
            FROM   turnos_caja t
            JOIN   usuarios    u ON u.id = t.usuario_id
            JOIN   sucursales  s ON s.id = t.sucursal_id
            WHERE  " . implode(' AND ', $where) . "
            ORDER  BY t.hora_apertura ASC
        ");
        $stmtAbiertos->execute($params);
        $turnos = $stmtAbiertos->fetchAll();

        foreach ($turnos as &$tur) {
            $resumen = calcularVentasTurno($db, (int) $tur['id'], $tur['hora_apertura'], (int) $tur['sucursal_id'], (int) $tur['usuario_id']);
            $tur['monto_apertura']    = floatval($tur['monto_apertura']);
            $tur['egresos_menores']   = floatval($tur['egresos_menores']);
            $tur['ventas_sistema']    = $resumen['total'];
            $tur['num_ventas']        = $resumen['count'];
            $tur['efectivo_esperado'] = round($tur['monto_apertura'] + $resumen['total'] - $tur['egresos_menores'], 2);
        }
        unset($tur);

        jsonResponse(['success' => true, 'turnos' => $turnos]);
    }

    // ── Turno activo actual ───────────────────────────────────────────────
    // Admin puede consultar cualquier turno con ?sucursal_id=X&usuario_id=Y
    if ($rol === 'admin') {
        $sucQ  = isset($_GET['sucursal_id']) ? (int) $_GET['sucursal_id'] : $sucursalId;
        $userQ = isset($_GET['usuario_id'])  ? (int) $_GET['usuario_id']  : $usuarioId;
    } else {
        // Caja y encargado solo consultan su propio turno: pedir otro responde 403
        // (is_string descarta arrays como sucursal_id[]=1, que también cuentan como manipulación)
        $pideOtraSuc  = isset($_GET['sucursal_id']) && (!is_string($_GET['sucursal_id']) || $_GET['sucursal_id'] !== (string) $sucursalId);
        $pideOtroUser = isset($_GET['usuario_id'])  && (!is_string($_GET['usuario_id'])  || $_GET['usuario_id']  !== (string) $usuarioId);
        if ($pideOtraSuc || $pideOtroUser) {
            jsonResponse(['success' => false, 'error' => 'Solo puedes consultar tu propio turno'], 403);
        }
        $sucQ  = $sucursalId;
        $userQ = $usuarioId;
    }

    $stmt = $db->prepare("
        SELECT t.*, u.nombre_display AS cajero_nombre, s.nombre AS sucursal_nombre
        FROM   turnos_caja t
        JOIN   usuarios    u ON u.id = t.usuario_id
        JOIN   sucursales  s ON s.id = t.sucursal_id
        WHERE  t.usuario_id  = ?
          AND  t.sucursal_id = ?
          AND  t.estado      = 'abierto'
        LIMIT 1
    ");
    $stmt->execute([$userQ, $sucQ]);
    $turno = $stmt->fetch();

    if (!$turno) {
        jsonResponse(['success' => true, 'turno' => null]);
    }

    // Egresos del turno
    $stmtEg = $db->prepare("SELECT monto, motivo, creado_at FROM egresos_caja WHERE turno_id = ? ORDER BY creado_at");
    $stmtEg->execute([$turno['id']]);
    $turno['egresos'] = $stmtEg->fetchAll();

    jsonResponse(['success' => true, 'turno' => $turno]);
}

// ── POST: acciones de turno ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input  = json_decode(file_get_contents('php://input'), true);
    $accion = $input['accion'] ?? '';

    // ── ABRIR TURNO ───────────────────────────────────────────────────────
    if ($accion === 'abrir') {
        if (!$sucursalId) {
            jsonResponse(['success' => false, 'error' => 'Sin sucursal asociada'], 403);
        }

        $montoApertura = isset($input['monto_apertura']) ? round(floatval($input['monto_apertura']), 2) : 0.00;
        if ($montoApertura < 0) {
            jsonResponse(['success' => false, 'error' => 'El monto de apertura no puede ser negativo'], 400);
        }

        // Verificar que no haya turno abierto para este usuario+sucursal
        $stmtCheck = $db->prepare("
            SELECT id FROM turnos_caja
            WHERE usuario_id = ? AND sucursal_id = ? AND estado = 'abierto'
            LIMIT 1
        ");
        $stmtCheck->execute([$usuarioId, $sucursalId]);
        if ($stmtCheck->fetch()) {
            jsonResponse(['success' => false, 'error' => 'Ya tienes un turno abierto. Debes cerrar el anterior primero'], 409);
        }

        $stmt = $db->prepare("
            INSERT INTO turnos_caja (usuario_id, sucursal_id, monto_apertura, estado)
            VALUES (?, ?, ?, 'abierto')
        ");
        $stmt->execute([$usuarioId, $sucursalId, $montoApertura]);

        jsonResponse([
            'success'  => true,
            'turno_id' => (int) $db->lastInsertId(),
            'message'  => 'Turno abierto correctamente',
        ]);
    }

    // ── REGISTRAR EGRESO ──────────────────────────────────────────────────
    if ($accion === 'egreso') {
        $monto  = isset($input['monto'])  ? round(floatval($input['monto']), 2) : 0;
        $motivo = trim($input['motivo'] ?? '');

        if ($monto <= 0) {
            jsonResponse(['success' => false, 'error' => 'El monto del egreso debe ser mayor a cero'], 400);
        }
        if (empty($motivo)) {
            jsonResponse(['success' => false, 'error' => 'Debes indicar el motivo del egreso'], 400);
        }

        // Obtener turno abierto
        $turno = obtenerTurnoAbierto($db, $usuarioId, $sucursalId);
        if (!$turno) {
            jsonResponse(['success' => false, 'error' => 'No tienes un turno abierto'], 404);
        }

        $db->beginTransaction();
        $db->prepare("INSERT INTO egresos_caja (turno_id, monto, motivo) VALUES (?, ?, ?)")
           ->execute([$turno['id'], $monto, $motivo]);
        $db->prepare("UPDATE turnos_caja SET egresos_menores = egresos_menores + ? WHERE id = ?")
           ->execute([$monto, $turno['id']]);
        $db->commit();

        jsonResponse(['success' => true, 'message' => "Egreso de Bs. {$monto} registrado"]);
    }

    // ── CORTE X (lectura parcial sin cerrar) ──────────────────────────────
    if ($accion === 'corte_x') {
        $turno = obtenerTurnoAbierto($db, $usuarioId, $sucursalId);
        if (!$turno) {
            jsonResponse(['success' => false, 'error' => 'No tienes un turno abierto'], 404);
        }
        $resumen = calcularVentasTurno($db, $turno['id'], $turno['hora_apertura'], $sucursalId, $usuarioId);
        jsonResponse([
            'success'         => true,
            'tipo'            => 'corte_x',
            'turno_id'        => $turno['id'],
            'hora_apertura'   => $turno['hora_apertura'],
            'monto_apertura'  => floatval($turno['monto_apertura']),
            'ventas_sistema'  => $resumen['total'],
            'num_ventas'      => $resumen['count'],
            'egresos'         => floatval($turno['egresos_menores']),
            'efectivo_esperado' => floatval($turno['monto_apertura']) + $resumen['total'] - floatval($turno['egresos_menores']),
        ]);
    }

    // ── CORTE Z / CIERRE DEFINITIVO ───────────────────────────────────────
    if ($accion === 'cerrar') {
        $montoCierre = isset($input['monto_cierre']) ? round(floatval($input['monto_cierre']), 2) : null;
        $notas       = trim($input['notas'] ?? '');

        if ($montoCierre === null || $montoCierre < 0) {
            jsonResponse(['success' => false, 'error' => 'Debes ingresar el monto físico contado'], 400);
        }

        $turno = obtenerTurnoAbierto($db, $usuarioId, $sucursalId);
        if (!$turno) {
            jsonResponse(['success' => false, 'error' => 'No tienes un turno abierto'], 404);
        }

        $resumen        = calcularVentasTurno($db, $turno['id'], $turno['hora_apertura'], $sucursalId, $usuarioId);
        $ventasSistema  = $resumen['total'];
        $egresos        = floatval($turno['egresos_menores']);
        $efectivoEsperado = floatval($turno['monto_apertura']) + $ventasSistema - $egresos;
        $diferencia     = round($montoCierre - $efectivoEsperado, 2);

        $db->prepare("
            UPDATE turnos_caja
            SET monto_cierre   = ?,
                ventas_sistema = ?,
                diferencia     = ?,
                notas          = ?,
                hora_cierre    = NOW(),
                estado         = 'cerrado'
            WHERE id = ?
        ")->execute([$montoCierre, $ventasSistema, $diferencia, $notas, $turno['id']]);

        jsonResponse([
            'success'           => true,
            'tipo'              => 'corte_z',
            'turno_id'          => $turno['id'],
            'hora_apertura'     => $turno['hora_apertura'],
            'monto_apertura'    => floatval($turno['monto_apertura']),
            'ventas_sistema'    => $ventasSistema,
            'num_ventas'        => $resumen['count'],
            'egresos'           => $egresos,
            'efectivo_esperado' => $efectivoEsperado,
            'monto_cierre'      => $montoCierre,
            'diferencia'        => $diferencia,
            'estado'            => $diferencia == 0 ? 'cuadrado' : ($diferencia > 0 ? 'sobrante' : 'faltante'),
        ]);
    }

    // ── CORTE Z SUPERVISADO: el encargado (o admin) cierra el turno de una de sus cajas ──
    // Cubre al cajero que no cerró su turno o que fue reasignado a otra sucursal con el
    // turno abierto (desde su caja ya no podría cerrarlo).
    if ($accion === 'cerrar_supervisado') {
        if ($rol !== 'admin' && $rol !== 'encargado') {
            jsonResponse(['success' => false, 'error' => 'Solo un encargado o el administrador puede cerrar turnos de otras cajas'], 403);
        }

        $turnoId     = (int) ($input['turno_id'] ?? 0);
        $montoRaw    = $input['monto_cierre'] ?? null;
        $montoCierre = is_numeric($montoRaw) ? round((float) $montoRaw, 2) : null;
        $notas       = trim((string) ($input['notas'] ?? ''));

        if ($turnoId <= 0) {
            jsonResponse(['success' => false, 'error' => 'Turno no válido'], 400);
        }
        if ($montoCierre === null || $montoCierre < 0) {
            jsonResponse(['success' => false, 'error' => 'Debes ingresar el monto físico contado'], 400);
        }

        $stmtTurno = $db->prepare("SELECT * FROM turnos_caja WHERE id = ?");
        $stmtTurno->execute([$turnoId]);
        $turno = $stmtTurno->fetch();
        if (!$turno) {
            jsonResponse(['success' => false, 'error' => 'Turno no encontrado'], 404);
        }
        if (!puede('cerrar_turno', (int) $turno['sucursal_id'])) {
            jsonResponse(['success' => false, 'error' => 'No puedes cerrar turnos de esta sucursal'], 403);
        }
        if ($turno['estado'] !== 'abierto') {
            jsonResponse(['success' => false, 'error' => 'El turno ya está cerrado'], 409);
        }

        $resumen          = calcularVentasTurno($db, (int) $turno['id'], $turno['hora_apertura'], (int) $turno['sucursal_id'], (int) $turno['usuario_id']);
        $ventasSistema    = $resumen['total'];
        $egresos          = floatval($turno['egresos_menores']);
        $efectivoEsperado = round(floatval($turno['monto_apertura']) + $ventasSistema - $egresos, 2);
        $diferencia       = round($montoCierre - $efectivoEsperado, 2);

        // Queda registrado quién cerró el turno en lugar del cajero
        $cerradoPor = (string) ($_SESSION['usuario_nombre'] ?? 'Encargado');
        $notaFinal  = trim('[Cerrado por ' . $cerradoPor . ' (' . $rol . ')] ' . $notas);

        // estado = 'abierto' en el WHERE: si el cajero lo cerró un instante antes, no se pisa
        $stmtCierre = $db->prepare("
            UPDATE turnos_caja
            SET monto_cierre   = ?,
                ventas_sistema = ?,
                diferencia     = ?,
                notas          = ?,
                hora_cierre    = NOW(),
                estado         = 'cerrado'
            WHERE id = ? AND estado = 'abierto'
        ");
        $stmtCierre->execute([$montoCierre, $ventasSistema, $diferencia, $notaFinal, $turno['id']]);
        if ($stmtCierre->rowCount() !== 1) {
            jsonResponse(['success' => false, 'error' => 'El turno ya está cerrado'], 409);
        }

        jsonResponse([
            'success'           => true,
            'tipo'              => 'corte_z',
            'turno_id'          => (int) $turno['id'],
            'cerrado_por'       => $cerradoPor,
            'hora_apertura'     => $turno['hora_apertura'],
            'monto_apertura'    => floatval($turno['monto_apertura']),
            'ventas_sistema'    => $ventasSistema,
            'num_ventas'        => $resumen['count'],
            'egresos'           => $egresos,
            'efectivo_esperado' => $efectivoEsperado,
            'monto_cierre'      => $montoCierre,
            'diferencia'        => $diferencia,
            'estado'            => $diferencia == 0 ? 'cuadrado' : ($diferencia > 0 ? 'sobrante' : 'faltante'),
        ]);
    }

    jsonResponse(['success' => false, 'error' => "Acción '{$accion}' no reconocida"], 400);
}

jsonResponse(['success' => false, 'error' => 'Método no permitido'], 405);

// ── Helpers ───────────────────────────────────────────────────────────────

function obtenerTurnoAbierto(PDO $db, int $usuarioId, int $sucursalId): ?array {
    $stmt = $db->prepare("
        SELECT * FROM turnos_caja
        WHERE usuario_id = ? AND sucursal_id = ? AND estado = 'abierto'
        LIMIT 1
    ");
    $stmt->execute([$usuarioId, $sucursalId]);
    return $stmt->fetch() ?: null;
}

function calcularVentasTurno(PDO $db, int $turnoId, string $horaApertura, int $sucursalId, int $usuarioId): array {
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(total), 0) AS total, COUNT(*) AS count
        FROM   ventas
        WHERE  turno_id = ? OR (turno_id IS NULL AND sucursal_id = ? AND usuario_id = ? AND fecha >= ?)
    ");
    $stmt->execute([$turnoId, $sucursalId, $usuarioId, $horaApertura]);
    $row = $stmt->fetch();
    return [
        'total' => round(floatval($row['total']), 2),
        'count' => (int) $row['count'],
    ];
}
