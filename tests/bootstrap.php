<?php

declare(strict_types=1);

use Patuju\Tests\Support\Server;
use Patuju\Tests\Support\TestDatabase;

require_once dirname(__DIR__) . '/vendor/autoload.php';

define('PATUJU_ROOT', dirname(__DIR__));

$envFile = PATUJU_ROOT . '/tests/.env.testing';
if (!is_file($envFile)) {
    fwrite(STDERR, "No existe {$envFile}\n");
    exit(1);
}

$testEnv = parse_ini_file($envFile);
foreach ($testEnv as $key => $value) {
    putenv("{$key}={$value}");
    $_ENV[$key] = $value;
}
putenv('PATUJU_ENV_FILE=' . $envFile);
$_ENV['PATUJU_ENV_FILE'] = $envFile;

/**
 * Zona horaria de PHP equivalente al desfase actual de MySQL respecto a UTC.
 * Etc/GMT±N invierte el signo (UTC-4 = Etc/GMT+4); para desfases que no son
 * horas enteras (p. ej. +05:30) se usa la primera zona con ese desfase ahora.
 */
$zonaDesdeDesfase = static function (int $minutos): string {
    if ($minutos === 0) {
        return 'UTC';
    }
    if ($minutos % 60 === 0 && abs($minutos) <= 14 * 60) {
        $horas = intdiv($minutos, 60);
        return 'Etc/GMT' . ($horas > 0 ? '-' : '+') . abs($horas);
    }

    $ahora = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    foreach (DateTimeZone::listIdentifiers() as $id) {
        if ((new DateTimeZone($id))->getOffset($ahora) === $minutos * 60) {
            return $id;
        }
    }

    throw new RuntimeException("No hay una zona horaria de PHP con desfase de {$minutos} minutos respecto a UTC.");
};

try {
    // Guardia _test (TestDatabase::config()) y candado: una sola ejecución de la suite a la vez
    if (!TestDatabase::acquireSuiteLock()) {
        fwrite(STDERR, 'No se puede ejecutar la suite: otra ejecución de la suite está en curso sobre la BD '
            . TestDatabase::config()['name'] . " (candado GET_LOCK ocupado).\n");
        exit(1);
    }

    // PHP (tests y php -S) usa la misma hora local que MySQL: CURDATE()/NOW() y date()
    // coinciden a cualquier hora del día
    date_default_timezone_set($zonaDesdeDesfase(TestDatabase::mysqlUtcOffsetMinutes()));

    // Registrar la parada antes de arrancar: si start() falla a medias no queda un php -S huérfano
    register_shutdown_function(static function (): void {
        Server::stop();
    });
    Server::start();
} catch (Throwable $e) {
    fwrite(STDERR, 'Error al preparar la suite: ' . $e->getMessage() . "\n");
    exit(1);
}
