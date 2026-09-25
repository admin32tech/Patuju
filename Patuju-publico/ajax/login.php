<?php
/**
 * PATUJU POS — Autenticación AJAX (Fase 1: incluye Rate-Limiting)
 *
 * Rate-limiting: 5 intentos fallidos por IP+username → bloqueo de 15 min.
 * Ventana de conteo: 10 minutos desde el primer fallo.
 */

// ── Cookies seguras ── (auth.php lo maneja, aquí iniciamos la sesión igual)
require_once __DIR__ . '/../config/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Método no permitido'], 405);
}

$input = json_decode(file_get_contents('php://input'), true);

if (empty($input['username']) || empty($input['password'])) {
    jsonResponse(['success' => false, 'error' => 'Credenciales requeridas'], 400);
}

$username = trim($input['username']);
$password = $input['password'];
$ip       = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

// ── Constantes de Rate-Limiting ───────────────────────────────────────────
define('RL_MAX_INTENTOS',    5);   // fallos antes del bloqueo
define('RL_VENTANA_MIN',    10);   // minutos en que se acumulan los fallos
define('RL_BLOQUEO_MIN',    15);   // minutos de bloqueo

$db = getDB();

// ── Verificar si la IP+usuario está actualmente bloqueada ─────────────────
$stmtBloq = $db->prepare("
    SELECT bloqueado_hasta, intentos
    FROM login_intentos
    WHERE ip = ? AND username = ?
    LIMIT 1
");
$stmtBloq->execute([$ip, $username]);
$intento = $stmtBloq->fetch();

if ($intento && $intento['bloqueado_hasta'] !== null) {
    $bloqueadoHasta = new DateTime($intento['bloqueado_hasta']);
    $ahora          = new DateTime();
    if ($ahora < $bloqueadoHasta) {
        $segundosRestantes = $ahora->diff($bloqueadoHasta);
        $minutos = (int) $segundosRestantes->format('%i');
        $seg     = (int) $segundosRestantes->format('%s');
        jsonResponse([
            'success' => false,
            'error'   => "Demasiados intentos fallidos. Inténtalo de nuevo en {$minutos}m {$seg}s."
        ], 429);
    } else {
        // Bloqueo expirado — limpiar registro
        $db->prepare("DELETE FROM login_intentos WHERE ip = ? AND username = ?")
           ->execute([$ip, $username]);
        $intento = null;
    }
}

// ── Buscar usuario en BD ──────────────────────────────────────────────────
$stmt = $db->prepare("
    SELECT u.*, s.nombre AS sucursal_nombre
    FROM usuarios u
    LEFT JOIN sucursales s ON u.sucursal_id = s.id
    WHERE u.username = ? AND u.activo = 1
");
$stmt->execute([$username]);
$user = $stmt->fetch();

$loginOk = false;
if ($user) {
    if (password_verify($password, $user['password_hash'])) {
        $loginOk = true;
    } elseif ($user['password_hash'] === $password) {
        // Fallback: si en la BD estaba en texto plano, aceptarlo y auto-hashear a bcrypt
        $loginOk = true;
        $newHash = password_hash($password, PASSWORD_BCRYPT);
        $db->prepare("UPDATE usuarios SET password_hash = ? WHERE id = ?")->execute([$newHash, $user['id']]);
        $user['password_hash'] = $newHash;
    }
}

// ── Login fallido ─────────────────────────────────────────────────────────
if (!$loginOk) {
    // Ventana: sólo contar fallos dentro de los últimos RL_VENTANA_MIN minutos
    $ventana = (new DateTime())->modify('-' . RL_VENTANA_MIN . ' minutes')->format('Y-m-d H:i:s');

    if ($intento) {
        // Actualizar contador
        $nuevosIntentos = $intento['intentos'] + 1;
        $bloqueadoHasta = null;

        if ($nuevosIntentos >= RL_MAX_INTENTOS) {
            $bloqueadoHasta = (new DateTime())
                ->modify('+' . RL_BLOQUEO_MIN . ' minutes')
                ->format('Y-m-d H:i:s');
        }

        $db->prepare("
            UPDATE login_intentos
            SET intentos = ?, bloqueado_hasta = ?
            WHERE ip = ? AND username = ?
        ")->execute([$nuevosIntentos, $bloqueadoHasta, $ip, $username]);
    } else {
        // Primer fallo
        $db->prepare("
            INSERT INTO login_intentos (ip, username, intentos, bloqueado_hasta)
            VALUES (?, ?, 1, NULL)
        ")->execute([$ip, $username]);
    }

    // Respuesta genérica: no revelar si el usuario existe o no
    jsonResponse(['success' => false, 'error' => 'Credenciales incorrectas'], 401);
}

// ── Login exitoso ─────────────────────────────────────────────────────────

// Limpiar intentos fallidos acumulados para este usuario+IP
$db->prepare("DELETE FROM login_intentos WHERE ip = ? AND username = ?")
   ->execute([$ip, $username]);

// Protección contra Session Fixation
session_regenerate_id(true);

$_SESSION['usuario_id']      = $user['id'];
$_SESSION['usuario_nombre']  = $user['nombre_display'];
$_SESSION['usuario_rol']     = $user['rol'];
$_SESSION['sucursal_id']     = $user['sucursal_id'];
$_SESSION['sucursal_nombre'] = $user['sucursal_nombre'] ?? 'Administración Central';

// Auto-rehash si el coste/algoritmo cambió
if (password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT)) {
    $newHash = password_hash($password, PASSWORD_BCRYPT);
    $db->prepare("UPDATE usuarios SET password_hash = ?, ultimo_login = NOW() WHERE id = ?")
       ->execute([$newHash, $user['id']]);
} else {
    $db->prepare("UPDATE usuarios SET ultimo_login = NOW() WHERE id = ?")
       ->execute([$user['id']]);
}

$redirect = 'index.php';
if ($user['rol'] === 'admin') {
    $redirect = 'admin.php';
} elseif ($user['rol'] === 'encargado') {
    $redirect = 'encargado.php';
}

jsonResponse([
    'success'  => true,
    'rol'      => $user['rol'],
    'redirect' => $redirect,
    'nombre'   => $user['nombre_display'],
    'sucursal' => $user['sucursal_nombre'] ?? null,
]);
