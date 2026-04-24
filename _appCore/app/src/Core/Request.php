<?php

declare(strict_types=1);

namespace AppCore\Core;

/**
 * HTTP request wrapper. Normalises access to method, URI, params, headers,
 * and body. Detects HTMX and AJAX requests.
 */
class Request
{
    private string $method;
    private string $uri;
    private array $queryParams;
    private array $postParams;
    private array $serverParams;
    private array $headers;

    public function __construct(
        string $method,
        string $uri,
        array $queryParams = [],
        array $postParams = [],
        array $serverParams = [],
        array $headers = []
    ) {
        $this->method = strtoupper($method);
        $this->uri = $this->normaliseUri($uri);
        $this->queryParams = $queryParams;
        $this->postParams = $postParams;
        $this->serverParams = $serverParams;
        $this->headers = $headers;
    }

    public static function fromGlobals(): self
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with((string) $key, 'HTTP_')) {
                $headerName = str_replace('_', '-', substr((string) $key, 5));
                $headers[$headerName] = $value;
            }
        }

        return new self(
            $_SERVER['REQUEST_METHOD'] ?? 'GET',
            $_SERVER['REQUEST_URI'] ?? '/',
            $_GET,
            $_POST,
            $_SERVER,
            $headers
        );
    }

    private function normaliseUri(string $uri): string
    {
        $path = parse_url($uri, PHP_URL_PATH) ?? '/';
        $path = '/' . trim((string) $path, '/');
        return $path === '/' ? '/' : $path;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getUri(): string
    {
        return $this->uri;
    }

    public function getParam(string $key, mixed $default = null): mixed
    {
        return $this->postParams[$key] ?? $this->queryParams[$key] ?? $default;
    }

    public function getAllParams(): array
    {
        return array_merge($this->queryParams, $this->postParams);
    }

    public function getBody(): string
    {
        return file_get_contents('php://input') ?: '';
    }

    public function getJsonBody(): ?array
    {
        $body = $this->getBody();
        if ($body === '') {
            return null;
        }
        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : null;
    }

    public function getHeader(string $name): ?string
    {
        $key = strtoupper($name);
        return $this->headers[$key] ?? null;
    }

    public function isHtmx(): bool
    {
        return isset($this->headers['HX-REQUEST']);
    }

    public function isAjax(): bool
    {
        return $this->isHtmx()
            || (isset($this->headers['X-REQUESTED-WITH'])
                && strtolower((string) $this->headers['X-REQUESTED-WITH']) === 'xmlhttprequest');
    }

    public function isStateChanging(): bool
    {
        return in_array($this->method, ['POST', 'PUT', 'DELETE', 'PATCH'], true);
    }

    public function getServerParam(string $key, mixed $default = null): mixed
    {
        return $this->serverParams[$key] ?? $default;
    }

    public function getClientIp(): string
    {
        return $this->serverParams['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    public function getUserAgent(): string
    {
        return $this->serverParams['HTTP_USER_AGENT'] ?? '';
    }
}
