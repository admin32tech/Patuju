<?php

declare(strict_types=1);

namespace Patuju\Tests\Support;

use PDO;
use PHPUnit\Framework\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /** @var array<string, string> */
    private array $cookieFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        TestDatabase::reset();
        $this->cookieFiles = [];
    }

    protected function tearDown(): void
    {
        foreach ($this->cookieFiles as $file) {
            @unlink($file);
        }
        $this->cookieFiles = [];
        parent::tearDown();
    }

    protected function get(string $path, ?string $user = null): HttpResponse
    {
        return $this->http('GET', $path, null, $user);
    }

    /**
     * @param array<string, mixed> $body
     */
    protected function post(string $path, array $body, ?string $user = null): HttpResponse
    {
        return $this->http('POST', $path, $body, $user);
    }

    /**
     * @param array<string, mixed> $body
     */
    protected function put(string $path, array $body, ?string $user = null): HttpResponse
    {
        return $this->http('PUT', $path, $body, $user);
    }

    protected function delete(string $path, ?string $user = null): HttpResponse
    {
        return $this->http('DELETE', $path, null, $user);
    }

    /**
     * @param array<string, mixed>|null $body
     * @param list<string> $headers
     */
    protected function http(string $method, string $path, ?array $body = null, ?string $user = null, array $headers = []): HttpResponse
    {
        $ch = curl_init(Server::baseUrl() . $path);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 5,
        ];

        if ($user !== null) {
            $jar = $this->cookieFile($user);
            $options[CURLOPT_COOKIEJAR] = $jar;
            $options[CURLOPT_COOKIEFILE] = $jar;
        }

        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
            $headers[] = 'Content-Type: application/json';
        }

        $options[CURLOPT_HTTPHEADER] = array_merge(['Accept: application/json'], $headers);
        curl_setopt_array($ch, $options);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("cURL error en {$method} {$path}: {$error}");
        }

        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $rawHeaders = substr($raw, 0, $headerSize);
        $rawBody = substr($raw, $headerSize);

        return new HttpResponse($status, $this->parseHeaders($rawHeaders), $rawBody, Server::baseUrl() . $path);
    }

    /**
     * @return array<string, mixed>
     */
    protected function loginAs(string $username, string $password = 'patuju2024'): array
    {
        $response = $this->post('/ajax/login.php', [
            'username' => $username,
            'password' => $password,
        ], $username);

        $this->assertSame(200, $response->status, $response->body);
        $data = $response->json();
        $this->assertTrue($data['success'] ?? false, $response->body);

        return $data;
    }

    protected function logout(string $user): void
    {
        $this->get('/logout.php', $user);
    }

    protected function sessionId(string $user): ?string
    {
        $file = $this->cookieFiles[$user] ?? null;
        if ($file === null || !is_file($file)) {
            return null;
        }

        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (str_starts_with($line, '#HttpOnly_')) {
                $line = substr($line, strlen('#HttpOnly_'));
            } elseif (str_starts_with($line, '#')) {
                continue;
            }

            $parts = explode("\t", $line);
            if (count($parts) >= 7 && $parts[5] === 'PHPSESSID') {
                return $parts[6];
            }
        }

        return null;
    }

    protected function db(): PDO
    {
        return TestDatabase::pdo();
    }

    protected function scalar(string $sql, array $params = []): mixed
    {
        $stmt = $this->db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function rows(string $sql, array $params = []): array
    {
        $stmt = $this->db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function row(string $sql, array $params = []): ?array
    {
        $stmt = $this->db()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    protected function stock(int $sucursalId, int $productoId): int
    {
        return (int) $this->scalar(
            'SELECT cantidad_disponible FROM stock_sucursal WHERE sucursal_id = ? AND producto_id = ?',
            [$sucursalId, $productoId]
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function openShift(string $user = 'caja_caja_central', float $amount = 100.0): array
    {
        $response = $this->post('/ajax/turno_caja.php', [
            'accion' => 'abrir',
            'monto_apertura' => $amount,
        ], $user);

        $this->assertSame(200, $response->status, $response->body);
        $data = $response->json();
        $this->assertTrue($data['success'] ?? false, $response->body);

        return $data;
    }

    /**
     * @param list<array{id:int,cantidad:int}> $items
     */
    protected function sell(array $items, float $cash, string $user = 'caja_caja_central', string $method = 'efectivo'): HttpResponse
    {
        return $this->post('/ajax/registrar_venta.php', [
            'items' => $items,
            'monto_recibido' => $cash,
            'metodo_pago' => $method,
        ], $user);
    }

    private function cookieFile(string $user): string
    {
        if (!isset($this->cookieFiles[$user])) {
            $file = tempnam(sys_get_temp_dir(), 'patuju-cookie-');
            if ($file === false) {
                throw new RuntimeException('No se pudo crear el archivo de cookies de prueba.');
            }
            $this->cookieFiles[$user] = $file;
        }

        return $this->cookieFiles[$user];
    }

    /**
     * @return array<string, list<string>>
     */
    private function parseHeaders(string $raw): array
    {
        $headers = [];
        $blocks = preg_split("/\r?\n\r?\n/", trim($raw)) ?: [];

        foreach ($blocks as $block) {
            $lines = preg_split("/\r?\n/", $block) ?: [];
            array_shift($lines);

            foreach ($lines as $line) {
                if (!str_contains($line, ':')) {
                    continue;
                }
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))][] = trim($value);
            }
        }

        return $headers;
    }
}
