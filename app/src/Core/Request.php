<?php

declare(strict_types=1);

namespace App\Core;

/**
 * HTTP Request wrapper.
 *
 * Provides clean access to the current request's method, URI,
 * parameters, headers, and body. Detects HTMX and AJAX requests.
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

    /**
     * Create a Request from PHP superglobals.
     */
    public static function fromGlobals(): self
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headerName = str_replace('_', '-', substr($key, 5));
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

    /**
     * Normalise the URI by stripping the query string and collapsing slashes.
     */
    private function normaliseUri(string $uri): string
    {
        $path = parse_url($uri, PHP_URL_PATH) ?? '/';
        $path = '/' . trim($path, '/');
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

    /**
     * Get a request parameter from POST, then GET, then a default.
     */
    public function getParam(string $key, mixed $default = null): mixed
    {
        return $this->postParams[$key] ?? $this->queryParams[$key] ?? $default;
    }

    /**
     * Get all parameters (POST merged over GET).
     */
    public function getAllParams(): array
    {
        return array_merge($this->queryParams, $this->postParams);
    }

    /**
     * Get the raw request body.
     */
    public function getBody(): string
    {
        return file_get_contents('php://input') ?: '';
    }

    /**
     * Get a parsed JSON body.
     */
    public function getJsonBody(): ?array
    {
        $body = $this->getBody();
        if ($body === '') {
            return null;
        }
        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Get a specific header value.
     */
    public function getHeader(string $name): ?string
    {
        $normalised = strtoupper(str_replace('-', '-', $name));
        return $this->headers[$normalised] ?? null;
    }

    /**
     * Check if this is an HTMX request.
     */
    public function isHtmx(): bool
    {
        return isset($this->headers['HX-REQUEST']);
    }

    /**
     * Check if this is an AJAX request.
     */
    public function isAjax(): bool
    {
        return $this->isHtmx()
            || (isset($this->headers['X-REQUESTED-WITH'])
                && strtolower($this->headers['X-REQUESTED-WITH']) === 'xmlhttprequest');
    }

    /**
     * Check if this is a state-changing request (POST, PUT, DELETE, PATCH).
     */
    public function isStateChanging(): bool
    {
        return in_array($this->method, ['POST', 'PUT', 'DELETE', 'PATCH'], true);
    }

    /**
     * Get a server parameter.
     */
    public function getServerParam(string $key, mixed $default = null): mixed
    {
        return $this->serverParams[$key] ?? $default;
    }

    /**
     * Get the client IP address.
     */
    public function getClientIp(): string
    {
        return $this->serverParams['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    /**
     * Get the user agent string.
     */
    public function getUserAgent(): string
    {
        return $this->serverParams['HTTP_USER_AGENT'] ?? '';
    }
}
