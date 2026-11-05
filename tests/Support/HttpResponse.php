<?php

declare(strict_types=1);

namespace Patuju\Tests\Support;

use RuntimeException;

final class HttpResponse
{
    /**
     * @param array<string, list<string>> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
        public readonly string $url = ''
    ) {
    }

    public function header(string $name): ?string
    {
        $name = strtolower($name);
        return $this->headers[$name][0] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function json(): array
    {
        $data = json_decode($this->body, true);
        if (!is_array($data)) {
            throw new RuntimeException(
                "Respuesta no JSON (HTTP {$this->status}) en {$this->url}:\n" . substr($this->body, 0, 2000)
            );
        }
        return $data;
    }

    public function isJson(): bool
    {
        json_decode($this->body, true);
        return json_last_error() === JSON_ERROR_NONE;
    }
}
