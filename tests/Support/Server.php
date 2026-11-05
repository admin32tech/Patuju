<?php

declare(strict_types=1);

namespace Patuju\Tests\Support;

use RuntimeException;
use Throwable;

/**
 * Levanta un servidor PHP embebido apuntando a la base de datos de pruebas.
 * Si PATUJU_TEST_BASE_URL está definido junto con PATUJU_TEST_ALLOW_EXTERNAL=1,
 * reutiliza un servidor externo (CI). Ese servidor debe usar la misma BD que
 * TestDatabase::config() y la misma zona horaria que MySQL.
 */
final class Server
{
    private static ?string $baseUrl = null;

    /** @var resource|null */
    private static $process = null;

    private static ?string $logFile = null;

    public static function start(): void
    {
        if (self::$baseUrl !== null) {
            return;
        }

        $external = getenv('PATUJU_TEST_BASE_URL');
        if ($external !== false && $external !== '') {
            // La suite borra y recrea la BD en cada test: no se apunta a un servidor
            // ajeno por accidente (p. ej. una variable heredada del entorno).
            if (getenv('PATUJU_TEST_ALLOW_EXTERNAL') !== '1') {
                throw new RuntimeException(
                    "PATUJU_TEST_BASE_URL está definido ({$external}) pero falta PATUJU_TEST_ALLOW_EXTERNAL=1. "
                    . 'Define ambas variables para usar un servidor externo, o quita PATUJU_TEST_BASE_URL '
                    . 'para que la suite levante su propio servidor.'
                );
            }
            self::$baseUrl = rtrim($external, '/');
            return;
        }

        $port = self::freePort();
        self::$baseUrl = "http://127.0.0.1:{$port}";
        self::$logFile = sys_get_temp_dir() . '/patuju-test-server-' . getmypid() . '.log';
        @unlink(self::$logFile);

        // Mismos PATUJU_DB_* que usa la suite (una sola fuente: TestDatabase::config())
        $env = array_merge(getenv(), TestDatabase::processEnv());

        $command = [
            PHP_BINARY,
            '-d', 'display_errors=1',
            '-d', 'error_reporting=E_ALL',
            // Misma zona horaria que el proceso de tests (alineada con MySQL en bootstrap.php)
            '-d', 'date.timezone=' . date_default_timezone_get(),
            '-S', "127.0.0.1:{$port}",
            '-t', PATUJU_ROOT,
        ];

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', self::$logFile, 'a'],
            2 => ['file', self::$logFile, 'a'],
        ];

        try {
            self::$process = proc_open($command, $descriptors, $pipes, PATUJU_ROOT, $env);
            if (!is_resource(self::$process)) {
                self::$process = null;
                throw new RuntimeException('No se pudo iniciar el servidor PHP de pruebas.');
            }
            if (isset($pipes[0]) && is_resource($pipes[0])) {
                fclose($pipes[0]);
            }

            self::waitUntilReady();
        } catch (Throwable $e) {
            // No dejar un php -S huérfano si no llegó a responder
            self::stop();
            self::$baseUrl = null;
            throw $e;
        }
    }

    public static function stop(): void
    {
        if (!is_resource(self::$process)) {
            return;
        }

        proc_terminate(self::$process);
        $deadline = microtime(true) + 3.0;
        while (microtime(true) < $deadline) {
            $status = proc_get_status(self::$process);
            if (!$status['running']) {
                break;
            }
            usleep(50_000);
        }

        $status = proc_get_status(self::$process);
        if ($status['running']) {
            proc_terminate(self::$process, 9);
        }
        proc_close(self::$process);
        self::$process = null;
    }

    public static function baseUrl(): string
    {
        if (self::$baseUrl === null) {
            throw new RuntimeException('El servidor de pruebas no ha sido iniciado.');
        }
        return self::$baseUrl;
    }

    private static function freePort(): int
    {
        $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            throw new RuntimeException("No se pudo reservar un puerto libre: {$errstr} ({$errno})");
        }
        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr((string) $name, (int) strrpos((string) $name, ':') + 1);
    }

    private static function waitUntilReady(): void
    {
        $deadline = microtime(true) + 15.0;

        do {
            $ch = curl_init(self::baseUrl() . '/login.php');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 2,
                CURLOPT_CONNECTTIMEOUT => 1,
            ]);
            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            if ($body !== false && $status === 200) {
                return;
            }
            usleep(100_000);
        } while (microtime(true) < $deadline);

        $log = self::$logFile !== null && is_file(self::$logFile)
            ? file_get_contents(self::$logFile)
            : '(sin log)';

        throw new RuntimeException("El servidor de pruebas no respondió a tiempo.\n{$log}");
    }
}
