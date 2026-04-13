<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Error and exception handler.
 *
 * Catches all PHP errors and uncaught exceptions, logs them to
 * /var/logs/errors.json (structured JSON with rotation), and renders
 * an appropriate error page (detailed in debug mode, generic in production).
 */
class ErrorHandler
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Register as the PHP error and exception handler.
     */
    public function register(): void
    {
        set_exception_handler([$this, 'handleException']);
        set_error_handler([$this, 'handleError']);
        register_shutdown_function([$this, 'handleShutdown']);
    }

    /**
     * Handle an uncaught exception.
     */
    public function handleException(\Throwable $e): void
    {
        $this->logError('error', $e->getMessage(), [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ]);

        $isDebug = $this->config['app']['debug'] ?? false;

        if (!headers_sent()) {
            http_response_code(500);
        }

        if ($isDebug) {
            echo $this->renderDebugPage($e);
        } else {
            echo $this->renderProductionPage();
        }
    }

    /**
     * Convert PHP errors to exceptions.
     */
    public function handleError(int $severity, string $message, string $file, int $line): bool
    {
        if (!(error_reporting() & $severity)) {
            return false;
        }

        throw new \ErrorException($message, 0, $severity, $file, $line);
    }

    /**
     * Handle fatal errors on shutdown.
     */
    public function handleShutdown(): void
    {
        $error = error_get_last();
        if ($error !== null && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE], true)) {
            $this->logError('fatal', $error['message'], [
                'file' => $error['file'],
                'line' => $error['line'],
            ]);
        }
    }

    /**
     * Log an error entry to /var/logs/errors.json.
     */
    private function logError(string $level, string $message, array $context = []): void
    {
        Logger::log($level, $message, $context);
    }

    /**
     * Render a detailed error page for debug mode.
     */
    private function renderDebugPage(\Throwable $e): string
    {
        $file = htmlspecialchars($e->getFile(), ENT_QUOTES, 'UTF-8');
        $line = $e->getLine();
        $message = htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        $trace = htmlspecialchars($e->getTraceAsString(), ENT_QUOTES, 'UTF-8');
        $class = htmlspecialchars(get_class($e), ENT_QUOTES, 'UTF-8');

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Error — ScoutKeeper</title>
            <style>
                body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; margin: 2rem; background: #f8f9fa; color: #212529; }
                .error-box { background: #fff; border: 1px solid #dee2e6; border-radius: 0.5rem; padding: 2rem; max-width: 900px; margin: 0 auto; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
                h1 { color: #dc3545; font-size: 1.5rem; margin-top: 0; }
                .meta { color: #6c757d; font-size: 0.875rem; margin-bottom: 1rem; }
                pre { background: #f1f3f5; padding: 1rem; border-radius: 0.375rem; overflow-x: auto; font-size: 0.8125rem; line-height: 1.6; }
            </style>
        </head>
        <body>
            <div class="error-box">
                <h1>{$class}</h1>
                <p>{$message}</p>
                <p class="meta">{$file}:{$line}</p>
                <h2 style="font-size: 1rem;">Stack Trace</h2>
                <pre>{$trace}</pre>
            </div>
        </body>
        </html>
        HTML;
    }

    /**
     * Render a generic error page for production.
     */
    private function renderProductionPage(): string
    {
        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Error — ScoutKeeper</title>
            <style>
                body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; background: #f8f9fa; color: #212529; }
                .error-box { text-align: center; padding: 3rem; }
                h1 { font-size: 1.5rem; margin-bottom: 0.5rem; }
                p { color: #6c757d; }
            </style>
        </head>
        <body>
            <div class="error-box">
                <h1>Something went wrong</h1>
                <p>An unexpected error occurred. Please try again later.</p>
            </div>
        </body>
        </html>
        HTML;
    }
}
