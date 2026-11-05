<?php
/**
 * PATUJU POS - Configuración de Base de Datos
 * Las credenciales se cargan desde el archivo .env (NO versionado).
 * Copiar .env.example → .env y ajustar los valores.
 */

// Cargar variables de entorno desde .env (un nivel arriba de config/).
// PATUJU_ENV_FILE permite apuntar a un archivo alterno (p. ej. la suite de tests).
$envFile = getenv('PATUJU_ENV_FILE') ?: __DIR__ . '/../.env';
$env = file_exists($envFile) ? parse_ini_file($envFile) : [];

// PATUJU_DB_* tiene prioridad sobre el archivo, útil para CI y contenedores.
define('DB_HOST',    getenv('PATUJU_DB_HOST') ?: ($env['DB_HOST'] ?? 'localhost'));
define('DB_NAME',    getenv('PATUJU_DB_NAME') ?: ($env['DB_NAME'] ?? 'patuju_pos'));
define('DB_USER',    getenv('PATUJU_DB_USER') ?: ($env['DB_USER'] ?? 'root'));
define('DB_PASS',    getenv('PATUJU_DB_PASS') ?: ($env['DB_PASS'] ?? ''));
define('DB_CHARSET', getenv('PATUJU_DB_CHARSET') ?: ($env['DB_CHARSET'] ?? 'utf8mb4'));

/**
 * Obtener conexión PDO
 * @return PDO
 */
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Error de conexión a la base de datos']);
            exit;
        }
    }
    return $pdo;
}

/**
 * Responder con JSON
 */
function jsonResponse(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
