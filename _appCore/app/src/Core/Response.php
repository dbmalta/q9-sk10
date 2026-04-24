<?php

declare(strict_types=1);

namespace AppCore\Core;

/**
 * HTTP response value object.
 *
 * Use the static html()/json()/redirect()/file() constructors to build
 * a response. Emits `Cache-Control: no-store` by default on HTML responses
 * to avoid stale pages on POST → redirect → GET flows.
 */
class Response
{
    private int $statusCode;
    private array $headers = [];
    private string $body;
    private ?string $filePath = null;

    public function __construct(int $statusCode = 200, string $body = '')
    {
        $this->statusCode = $statusCode;
        $this->body = $body;
    }

    public static function html(string $content, int $statusCode = 200): self
    {
        $response = new self($statusCode, $content);
        $response->setHeader('Content-Type', 'text/html; charset=UTF-8');
        $response->setHeader('Cache-Control', 'no-store, must-revalidate');
        return $response;
    }

    public static function json(mixed $data, int $statusCode = 200): self
    {
        $response = new self($statusCode, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $response->setHeader('Content-Type', 'application/json; charset=UTF-8');
        return $response;
    }

    public static function file(string $filePath, string $filename, string $mimeType = 'application/octet-stream'): self
    {
        $response = new self(200);
        $response->filePath = $filePath;
        $response->setHeader('Content-Type', $mimeType);
        $response->setHeader('Content-Disposition', 'attachment; filename="' . addslashes($filename) . '"');
        $response->setHeader('Content-Length', (string) filesize($filePath));
        $response->setHeader('Cache-Control', 'no-cache, must-revalidate');
        return $response;
    }

    public static function redirect(string $url, int $statusCode = 302): self
    {
        $response = new self($statusCode);
        $response->setHeader('Location', $url);
        return $response;
    }

    public function isFileResponse(): bool
    {
        return $this->filePath !== null;
    }

    public function getFilePath(): ?string
    {
        return $this->filePath;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function setStatusCode(int $statusCode): self
    {
        $this->statusCode = $statusCode;
        return $this;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function setHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function setBody(string $body): self
    {
        $this->body = $body;
        return $this;
    }
}
