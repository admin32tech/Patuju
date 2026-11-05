<?php

declare(strict_types=1);

namespace Patuju\Tests\Support;

use PDO;
use RuntimeException;

/**
 * Crea y reinicia la base de datos aislada de la suite (patuju_pos_test).
 * Nunca toca la base de datos de desarrollo.
 *
 * Única fuente de configuración de BD para la suite: tests/.env.testing con
 * PATUJU_DB_* del entorno por encima (misma precedencia que config/database.php).
 * El servidor embebido y init_usuarios.php reciben exactamente estos valores.
 */
final class TestDatabase
{
    /** Solo se aceptan nombres de BD que terminen en _test (protege la BD de desarrollo). */
    private const NOMBRE_SEGURO = '/^[A-Za-z0-9_]+_test$/';

    private static ?PDO $pdo = null;

    /** Conexión que retiene el candado de la suite durante toda la ejecución. */
    private static ?PDO $lockPdo = null;

    /** @var array<string, string>|null */
    private static ?array $config = null;

    /**
     * @return array{host:string,name:string,user:string,pass:string,charset:string}
     */
    public static function config(): array
    {
        if (self::$config === null) {
            $file = PATUJU_ROOT . '/tests/.env.testing';
            $env = parse_ini_file($file);
            if ($env === false) {
                throw new RuntimeException("No se pudo leer {$file}");
            }

            // PATUJU_DB_* tiene prioridad sobre el archivo (igual que config/database.php)
            $valor = static function (string $clave, string $porDefecto) use ($env): string {
                $desdeEntorno = getenv('PATUJU_' . $clave);
                if ($desdeEntorno !== false && $desdeEntorno !== '') {
                    return $desdeEntorno;
                }
                return (string) ($env[$clave] ?? $porDefecto);
            };

            $config = [
                'host' => $valor('DB_HOST', 'localhost'),
                'name' => $valor('DB_NAME', 'patuju_pos_test'),
                'user' => $valor('DB_USER', 'root'),
                'pass' => $valor('DB_PASS', ''),
                'charset' => $valor('DB_CHARSET', 'utf8mb4'),
            ];

            // Guardia: la suite hace DROP DATABASE; nunca sobre una BD que no sea de pruebas
            if (!preg_match(self::NOMBRE_SEGURO, $config['name'])) {
                throw new RuntimeException(
                    "La BD de pruebas '{$config['name']}' no termina en _test: la suite se niega a usarla "
                    . '(revisa tests/.env.testing y PATUJU_DB_NAME).'
                );
            }

            self::$config = $config;
        }

        return self::$config;
    }

    /**
     * Variables PATUJU_DB_* que deben recibir los procesos hijos (php -S, init_usuarios.php).
     *
     * @return array<string, string>
     */
    public static function processEnv(): array
    {
        $c = self::config();

        return [
            'PATUJU_ENV_FILE' => PATUJU_ROOT . '/tests/.env.testing',
            'PATUJU_DB_HOST' => $c['host'],
            'PATUJU_DB_NAME' => $c['name'],
            'PATUJU_DB_USER' => $c['user'],
            'PATUJU_DB_PASS' => $c['pass'],
            'PATUJU_DB_CHARSET' => $c['charset'],
        ];
    }

    /**
     * Conexión al servidor MySQL sin seleccionar BD (la de pruebas puede no existir aún).
     */
    public static function serverPdo(): PDO
    {
        $c = self::config();

        return new PDO(
            "mysql:host={$c['host']};charset={$c['charset']}",
            $c['user'],
            $c['pass'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    }

    /**
     * Candado de la suite: GET_LOCK('<bd>_suite', 0) en una conexión que vive toda la ejecución.
     * Devuelve false si otra ejecución ya lo tiene (dos suites a la vez se pisarían la BD).
     */
    public static function acquireSuiteLock(): bool
    {
        if (self::$lockPdo !== null) {
            return true;
        }

        $pdo = self::serverPdo();
        $stmt = $pdo->prepare('SELECT GET_LOCK(?, 0)');
        $stmt->execute([self::config()['name'] . '_suite']);
        if ((int) $stmt->fetchColumn() !== 1) {
            return false;
        }

        self::$lockPdo = $pdo;
        return true;
    }

    /**
     * Desfase de MySQL respecto a UTC, en minutos (NOW() - UTC_TIMESTAMP()).
     */
    public static function mysqlUtcOffsetMinutes(): int
    {
        return (int) self::serverPdo()
            ->query('SELECT TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), NOW())')
            ->fetchColumn();
    }

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $c = self::config();
            self::$pdo = new PDO(
                "mysql:host={$c['host']};dbname={$c['name']};charset={$c['charset']}",
                $c['user'],
                $c['pass'],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        }

        return self::$pdo;
    }

    /**
     * Borra y recrea la BD de pruebas desde schema.sql + init_usuarios.php.
     */
    public static function reset(): void
    {
        self::$pdo = null;
        // config() aplica la guardia _test antes de cualquier DROP
        $c = self::config();

        $root = self::serverPdo();
        $root->exec("DROP DATABASE IF EXISTS `{$c['name']}`");
        $root->exec("CREATE DATABASE `{$c['name']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $root = null;

        $pdo = new PDO(
            "mysql:host={$c['host']};dbname={$c['name']};charset={$c['charset']}",
            $c['user'],
            $c['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $schema = file_get_contents(PATUJU_ROOT . '/database/schema.sql');
        if ($schema === false) {
            throw new RuntimeException('No se pudo leer database/schema.sql');
        }
        $schema = str_replace('patuju_pos', $c['name'], $schema);
        $pdo->exec($schema);
        $pdo = null;

        self::seedUsers();
    }

    private static function seedUsers(): void
    {
        $env = array_merge(getenv(), self::processEnv());

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            [PHP_BINARY, PATUJU_ROOT . '/database/init_usuarios.php'],
            $descriptors,
            $pipes,
            PATUJU_ROOT,
            $env
        );
        if (!is_resource($process)) {
            throw new RuntimeException('No se pudo ejecutar database/init_usuarios.php');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $code = proc_close($process);
        if ($code !== 0) {
            throw new RuntimeException("init_usuarios.php falló ({$code}):\n{$stdout}\n{$stderr}");
        }
    }
}
