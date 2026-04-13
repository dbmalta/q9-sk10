<?php

declare(strict_types=1);

namespace App\Core;

/**
 * HTTP Response.
 *
 * Encapsulates a status code, headers, and body for the response
 * that will be sent back to the client.
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

    /**
     * Create an HTML response.
     */
    public static function html(string $content, int $statusCode = 200): self
    {
        $response = new self($statusCode, $content);
        $response->setHeader('Content-Type', 'text/html; charset=UTF-8');
        return $response;
    }

    /**
     * Create a JSON response.
     */
    public static function json(mixed $data, int $statusCode = 200): self
    {
        $response = new self($statusCode, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $response->setHeader('Content-Type', 'application/json; charset=UTF-8');
        return $response;
    }

    /**
     * Create a file download response.
     *
     * @param string $filePath Absolute path to the file on disk
     * @param string $filename Download filename sent to the browser
     * @param string $mimeType MIME type for Content-Type header
     */
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

    /**
     * Check if this response is a file download.
     */
    public function isFileResponse(): bool
    {
        return $this->filePath !== null;
    }

    /**
     * Get the file path for file download responses.
     */
    public function getFilePath(): ?string
    {
        return $this->filePath;
    }

    /**
     * Create a redirect response.
     */
    public static function redirect(string $url, int $statusCode = 302): self
    {
        $response = new self($statusCode);
        $response->setHeader('Location', $url);
        return $response;
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
