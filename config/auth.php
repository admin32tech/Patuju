<?php
// ── Cookies de sesión seguras (Fase 1) ─────────────────────────────────────
// httponly  : la cookie no es accesible via JavaScript (mitiga XSS)
// samesite  : Strict evita que la cookie se envíe en peticiones cross-site (mitiga CSRF)
// secure    : se activa automáticamente si la conexión es HTTPS
$secureFlag = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_set_cookie_params([
    'lifetime' => 0,          // cookie de sesión (expira al cerrar navegador)
    'path'     => '/',
    'domain'   => '',
    'secure'   => $secureFlag,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();
require_once __DIR__ . '/database.php';

/**
 * Elimina la sesión actual (variables, cookie y datos en servidor).
 */
function destruirSesion(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'],
            'domain'   => $p['domain'],
            'secure'   => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => $p['samesite'] ?? 'Strict',
        ]);
    }
    session_destroy();
}

/**
 * Relee de BD el estado del usuario en sesión (una sola consulta por petición).
 * - Usuario inexistente o desactivado: destruye la sesión y devuelve false.
 * - Rol: se actualiza $_SESSION['usuario_rol'] si cambió.
 * - Roles distintos de admin: sincroniza sucursal_id y sucursal_nombre con la BD
 *   (mismo criterio que ajax/login.php). La sucursal del admin la gestiona
 *   asegurarSucursalAdmin(), por eso no se pisa.
 */
function refrescarSesionDesdeBD(): bool {
    static $valida = null;
    if ($valida !== null) {
        return $valida;
    }

    $stmt = getDB()->prepare("
        SELECT u.rol, u.activo, u.sucursal_id, u.nombre_display, s.nombre AS sucursal_nombre
        FROM usuarios u
        LEFT JOIN sucursales s ON s.id = u.sucursal_id
        WHERE u.id = ?
    ");
    $stmt->execute([(int) $_SESSION['usuario_id']]);
    $u = $stmt->fetch();

    if (!$u || (int) $u['activo'] !== 1) {
        destruirSesion();
        return $valida = false;
    }

    $_SESSION['usuario_rol']    = $u['rol'];
    $_SESSION['usuario_nombre'] = $u['nombre_display'];
    if ($u['rol'] !== 'admin') {
        $_SESSION['sucursal_id']     = $u['sucursal_id'] !== null ? (int) $u['sucursal_id'] : null;
        $_SESSION['sucursal_nombre'] = $u['sucursal_nombre'] ?? 'Administración Central';
    }

    return $valida = true;
}

/**
 * Verify active session and required role
 * Redirects to login.php if not authenticated
 * @param string|null $rolRequerido - 'caja', 'encargado', 'admin', or null for any
 */
function verificarSesion(?string $rolRequerido = null): void {
    if (!isset($_SESSION['usuario_id'])) {
        header('Location: login.php');
        exit;
    }
    // Usuario desactivado o eliminado desde que inició sesión
    if (!refrescarSesionDesdeBD()) {
        header('Location: login.php');
        exit;
    }
    if ($rolRequerido && $_SESSION['usuario_rol'] !== $rolRequerido) {
        // Admin tiene acceso total
        if ($_SESSION['usuario_rol'] === 'admin') {
            return;
        }
        // Encargado puede acceder a las vistas de caja de su sucursal
        if ($rolRequerido === 'caja' && $_SESSION['usuario_rol'] === 'encargado') {
            return;
        }
        header('Location: login.php?error=sin_permisos');
        exit;
    }
}

/**
 * Verify session for AJAX endpoints (returns JSON instead of redirect)
 */
function verificarSesionAjax(?string $rolRequerido = null): void {
    if (!isset($_SESSION['usuario_id'])) {
        jsonResponse(['success' => false, 'error' => 'Sesión expirada'], 401);
    }
    // Usuario desactivado o eliminado desde que inició sesión
    if (!refrescarSesionDesdeBD()) {
        jsonResponse(['success' => false, 'error' => 'Sesión expirada'], 401);
    }
    if ($rolRequerido && $_SESSION['usuario_rol'] !== $rolRequerido) {
        // Admin tiene acceso total
        if ($_SESSION['usuario_rol'] === 'admin') {
            return;
        }
        // Encargado puede acceder a endpoints de caja
        if ($rolRequerido === 'caja' && $_SESSION['usuario_rol'] === 'encargado') {
            return;
        }
        jsonResponse(['success' => false, 'error' => 'Sin permisos'], 403);
    }
}

/**
 * Garantiza que el admin tenga sucursal en la sesión (la sucursal interna SUC-000).
 * Cubre sesiones iniciadas antes de la migración. Devuelve false si no hay
 * sucursal posible (no es admin, o falta ejecutar actualizar_bd.php).
 */
function asegurarSucursalAdmin(): bool {
    if (!empty($_SESSION['sucursal_id'])) {
        return true;
    }
    if (($_SESSION['usuario_rol'] ?? '') !== 'admin') {
        return false;
    }
    try {
        $row = getDB()->query(
            "SELECT id, nombre FROM sucursales WHERE es_interna = 1 AND activo = 1 ORDER BY id LIMIT 1"
        )->fetch();
    } catch (Throwable $e) {
        return false; // columna es_interna inexistente: BD sin migrar
    }
    if (!$row) {
        return false;
    }
    $_SESSION['sucursal_id']     = (int) $row['id'];
    $_SESSION['sucursal_nombre'] = $row['nombre'];
    return true;
}

/**
 * Get current user's sucursal data
 */
function obtenerSucursalActual(): ?array {
    if (!isset($_SESSION['sucursal_id']) || !$_SESSION['sucursal_id']) {
        return null;
    }
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM sucursales WHERE id = ?');
    $stmt->execute([$_SESSION['sucursal_id']]);
    return $stmt->fetch() ?: null;
}

/**
 * Indica si existe la tabla N:M usuario_sucursales (BD sin migrar = false).
 * Se consulta una sola vez por petición.
 */
function existeTablaUsuarioSucursales(): bool {
    static $existe = null;
    if ($existe === null) {
        $existe = getDB()->query("SHOW TABLES LIKE 'usuario_sucursales'")->fetchColumn() !== false;
    }
    return $existe;
}

/**
 * Retorna los IDs de las sucursales asignadas a un usuario.
 * - Admin: todas las sucursales activas del negocio
 * - Encargado: las vinculadas en usuario_sucursales + sucursal_id principal
 * - Caja: únicamente su sucursal_id principal
 */
function obtenerSucursalesAsignadas(int $usuarioId = 0): array {
    $db = getDB();
    if ($usuarioId === 0) {
        $usuarioId = (int) ($_SESSION['usuario_id'] ?? 0);
    }
    if (!$usuarioId) {
        return [];
    }

    $rol = $_SESSION['usuario_rol'] ?? '';
    if (!$rol || $usuarioId !== (int)($_SESSION['usuario_id'] ?? 0)) {
        $uStmt = $db->prepare("SELECT rol, sucursal_id FROM usuarios WHERE id = ?");
        $uStmt->execute([$usuarioId]);
        $uData = $uStmt->fetch();
        if (!$uData) return [];
        $rol = $uData['rol'];
        $sucPrincipal = (int) ($uData['sucursal_id'] ?? 0);
    } else {
        $sucPrincipal = (int) ($_SESSION['sucursal_id'] ?? 0);
    }

    if ($rol === 'admin') {
        $stmt = $db->query("SELECT id FROM sucursales WHERE activo = 1 AND es_interna = 0 ORDER BY id ASC");
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    $ids = [];
    if ($sucPrincipal > 0) {
        $ids[] = $sucPrincipal;
    }

    // Solo el encargado suma las sucursales vinculadas (caja = solo su principal)
    if ($rol === 'encargado' && existeTablaUsuarioSucursales()) {
        $stmtUS = $db->prepare("
            SELECT us.sucursal_id
            FROM usuario_sucursales us
            JOIN sucursales s ON s.id = us.sucursal_id
            WHERE us.usuario_id = ? AND s.activo = 1
            ORDER BY us.sucursal_id ASC
        ");
        $stmtUS->execute([$usuarioId]);
        $vinculadas = array_map('intval', $stmtUS->fetchAll(PDO::FETCH_COLUMN));
        $ids = array_values(array_unique(array_merge($ids, $vinculadas)));
    }

    return $ids;
}

/**
 * Control de permisos granular por capacidad
 */
function puede(string $accion, ...$args): bool {
    $rol = $_SESSION['usuario_rol'] ?? '';
    if (!$rol) return false;

    // Admin tiene acceso total
    if ($rol === 'admin') return true;

    switch ($accion) {
        case 'gestionar_usuarios':
        case 'ver_auditoria':
        case 'exportar_reportes':
            return false; // Exclusivo de admin

        case 'ver_sucursal':
        case 'editar_stock':
        case 'ingreso_lote':
        case 'ajuste_stock':
        case 'cerrar_turno': // Corte Z de las cajas a su cargo
            $sucId = (int) ($args[0] ?? 0);
            if ($rol === 'encargado') {
                $asignadas = obtenerSucursalesAsignadas();
                return in_array($sucId, $asignadas, true);
            }
            if ($rol === 'caja' && $accion === 'ver_sucursal') {
                return $sucId === (int)($_SESSION['sucursal_id'] ?? 0);
            }
            return false;

        case 'vender_en_caja':
            return $rol === 'caja' || $rol === 'encargado';

        default:
            return false;
    }
}

/**
 * Valida un sucursal_id que llega del cliente (URL o cuerpo JSON).
 * - Devuelve null si no se envió: el endpoint aplica su filtro por defecto.
 * - Devuelve el id si es un entero positivo y el usuario tiene permiso ($accion).
 * - Con cualquier otro valor (0, 'abc', -1, una sucursal ajena) corta con 403:
 *   manipular la URL nunca amplía lo que el usuario puede ver o tocar.
 */
function sucursalSolicitadaPermitida($valor, string $accion = 'ver_sucursal'): ?int {
    if ($valor === null || $valor === '') {
        return null;
    }
    $texto = is_int($valor) ? (string) $valor : (is_string($valor) ? trim($valor) : '');
    if (!ctype_digit($texto) || (int) $texto <= 0 || !puede($accion, (int) $texto)) {
        jsonResponse(['success' => false, 'error' => 'No tienes permisos sobre esta sucursal'], 403);
    }
    return (int) $texto;
}
