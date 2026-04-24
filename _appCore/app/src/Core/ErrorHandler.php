<?php

declare(strict_types=1);

namespace AppCore\Core;

/**
 * Error and exception handler.
 *
 * Registers itself as the global PHP exception/error handler, routes
 * all failures to the Logger, and renders a generic 500 page (or a
 * developer-facing traceback in debug mode).
 */
class ErrorHandler
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function register(): void
    {
        set_exception_handler([$this, 'handleException']);
        set_error_handler([$this, 'handleError']);
        register_shutdown_function([$this, 'handleShutdown']);
    }

    public function handleException(\Throwable $e): void
    {
        Logger::log('error', $e->getMessage(), [
            'file'  => $e->getFile(),
            'line'  => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ]);

        $isDebug = (bool) ($this->config['app']['debug'] ?? false);

        if (!headers_sent()) {
            http_response_code(500);
        }

        echo $isDebug ? $this->renderDebugPage($e) : $this->renderProductionPage();
    }

    public function handleError(int $severity, string $message, string $file, int $line): bool
    {
        if (!(error_reporting() & $severity)) {
            return false;
        }
        throw new \ErrorException($message, 0, $severity, $file, $line);
    }

    public function handleShutdown(): void
    {
        $error = error_get_last();
        if ($error !== null && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE], true)) {
            Logger::log('fatal', $error['message'], [
                'file' => $error['file'],
                'line' => $error['line'],
            ]);
        }
    }

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
<head><meta charset="UTF-8"><title>Error</title>
<style>
body{font-family:system-ui,sans-serif;margin:2rem;background:#f8f9fa;color:#212529}
.box{background:#fff;border:1px solid #dee2e6;border-radius:.5rem;padding:2rem;max-width:900px;margin:0 auto}
h1{color:#dc3545;font-size:1.5rem;margin-top:0}
.meta{color:#6c757d;font-size:.875rem}
pre{background:#f1f3f5;padding:1rem;border-radius:.375rem;overflow-x:auto;font-size:.8125rem}
</style></head>
<body><div class="box">
<h1>{$class}</h1><p>{$message}</p><p class="meta">{$file}:{$line}</p>
<h2 style="font-size:1rem">Stack trace</h2><pre>{$trace}</pre>
</div></body></html>
HTML;
    }

    private function renderProductionPage(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><title>Error</title>
<style>
body{font-family:system-ui,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f8f9fa;color:#212529}
.box{text-align:center;padding:3rem}
h1{font-size:1.5rem;margin-bottom:.5rem}
p{color:#6c757d}
</style></head>
<body><div class="box">
<h1>Something went wrong</h1>
<p>An unexpected error occurred. Please try again later.</p>
</div></body></html>
HTML;
    }
}
