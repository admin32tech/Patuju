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
 * Verify active session and required role
 * Redirects to login.php if not authenticated
 * @param string|null $rolRequerido - 'caja', 'encargado', 'admin', or null for any
 */
function verificarSesion(?string $rolRequerido = null): void {
    if (!isset($_SESSION['usuario_id'])) {
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
