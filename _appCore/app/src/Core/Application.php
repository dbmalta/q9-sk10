<?php

declare(strict_types=1);

namespace AppCore\Core;

/**
 * Application singleton — the central orchestrator.
 *
 * Holds references to all core services (database, router, template engine,
 * session, i18n, module registry, permission resolver) and drives the
 * request lifecycle via run().
 */
class Application
{
    private static ?Application $instance = null;

    private array $config;
    private ?Database $db = null;
    private ?Router $router = null;
    private ?TwigRenderer $twig = null;
    private ?Session $session = null;
    private ?I18n $i18n = null;
    private ?ModuleRegistry $moduleRegistry = null;
    private ?Request $request = null;
    private ?ErrorHandler $errorHandler = null;
    private ?PermissionResolver $permissionResolver = null;

    private function __construct(array $config)
    {
        $this->config = $config;
    }

    public static function init(array $config): void
    {
        if (self::$instance !== null) {
            return;
        }
        self::$instance = new self($config);
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            throw new \RuntimeException('Application not initialised. Call Application::init() first.');
        }
        return self::$instance;
    }

    public static function run(): void
    {
        $app = self::getInstance();
        $requestStart = microtime(true);

        $app->errorHandler = new ErrorHandler($app->config);
        $app->errorHandler->register();

        $app->request = Request::fromGlobals();

        $app->session = new Session($app->config);
        $app->session->start();

        $dbConfig = $app->config['db'];
        if (isset($app->config['monitoring']['slow_query_threshold_ms'])) {
            $dbConfig['slow_query_threshold_ms'] = $app->config['monitoring']['slow_query_threshold_ms'];
        }
        $app->db = new Database($dbConfig);

        // Language resolution: session > DB default > config > 'en'.
        $language = $app->session->get('language');
        if ($language === null || $language === '') {
            try {
                $dbDefault = $app->db->fetchColumn(
                    "SELECT code FROM languages WHERE is_default = 1 AND is_active = 1 LIMIT 1"
                );
                if (is_string($dbDefault) && $dbDefault !== '') {
                    $language = $dbDefault;
                }
            } catch (\PDOException) {
                // languages table may not exist yet
            }
        }
        $language = $language ?: ($app->config['app']['language'] ?? 'en');
        $app->i18n = new I18n(ROOT_PATH . '/lang', $app->db, $language);

        $app->twig = new TwigRenderer($app);

        $app->moduleRegistry = new ModuleRegistry();
        $app->moduleRegistry->loadModules(ROOT_PATH . '/app/modules');

        $app->router = new Router();
        $app->moduleRegistry->registerRoutes($app->router);

        // CSRF validation for state-changing requests
        if ($app->request->isStateChanging()) {
            $csrf = new Csrf($app->session);
            $csrfToken = $app->request->getParam('_csrf_token')
                ?? $app->request->getParam('_csrf')
                ?? $app->request->getHeader('X-CSRF-Token')
                ?? '';
            if (!$csrf->validateToken((string) $csrfToken)) {
                $app->sendResponse(new Response(403, 'CSRF token validation failed'));
                return;
            }
        }

        $response = $app->router->dispatch($app->request);
        $app->sendResponse($response);

        $app->logRequestProfile($requestStart, $response);
        $app->runPseudoCron();
    }

    /**
     * Append a compact request profile to var/logs/requests.json when a
     * request is slow or issues more queries than the monitoring threshold.
     */
    private function logRequestProfile(float $startedAt, Response $response): void
    {
        $wallMs = (microtime(true) - $startedAt) * 1000;
        $profile = $this->db ? $this->db->getProfile() : ['count' => 0, 'total_ms' => 0.0, 'samples' => []];

        $wallThreshold  = (float) ($this->config['monitoring']['slow_request_threshold_ms'] ?? 500);
        $countThreshold = (int)   ($this->config['monitoring']['slow_request_query_count'] ?? 20);

        if ($wallMs < $wallThreshold && $profile['count'] < $countThreshold) {
            return;
        }

        // Aggregate by normalised SQL to surface N+1 patterns.
        $grouped = [];
        foreach ($profile['samples'] as $s) {
            $key = preg_replace('/\s+/', ' ', trim((string) $s['sql']));
            $key = substr($key ?? '', 0, 200);
            if (!isset($grouped[$key])) {
                $grouped[$key] = ['sql' => $key, 'count' => 0, 'total_ms' => 0.0, 'max_ms' => 0.0];
            }
            $grouped[$key]['count']++;
            $grouped[$key]['total_ms'] += $s['ms'];
            if ($s['ms'] > $grouped[$key]['max_ms']) {
                $grouped[$key]['max_ms'] = $s['ms'];
            }
        }
        usort($grouped, static fn($a, $b) => $b['count'] <=> $a['count'] ?: $b['total_ms'] <=> $a['total_ms']);
        $top = array_slice(array_map(static fn($g) => [
            'sql'      => $g['sql'],
            'count'    => $g['count'],
            'total_ms' => round($g['total_ms'], 2),
            'max_ms'   => round($g['max_ms'], 2),
        ], $grouped), 0, 10);

        $entry = [
            'timestamp'   => gmdate('c'),
            'method'      => $this->request?->getMethod() ?? '',
            'uri'         => $_SERVER['REQUEST_URI'] ?? '',
            'status'      => $response->getStatusCode(),
            'wall_ms'     => round($wallMs, 2),
            'query_count' => $profile['count'],
            'query_ms'    => $profile['total_ms'],
            'user_id'     => $_SESSION['user']['id'] ?? null,
            'top_queries' => $top,
        ];

        $file = ROOT_PATH . '/var/logs/requests.json';
        if (!is_dir(dirname($file))) {
            return;
        }

        $existing = [];
        if (file_exists($file)) {
            $existing = json_decode((string) file_get_contents($file), true) ?: [];
        }
        $existing[] = $entry;
        if (count($existing) > 500) {
            $existing = array_slice($existing, -500);
        }
        file_put_contents($file, json_encode($existing, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), LOCK_EX);
    }

    private function sendResponse(Response $response): void
    {
        http_response_code($response->getStatusCode());
        foreach ($response->getHeaders() as $name => $value) {
            header("$name: $value");
        }
        if ($response->isFileResponse() && $response->getFilePath() !== null) {
            readfile($response->getFilePath());
            return;
        }
        echo $response->getBody();
    }

    /**
     * Run pending cron handlers after the response is sent, when no real
     * cron is configured and the configured interval has elapsed.
     */
    private function runPseudoCron(): void
    {
        if (!function_exists('fastcgi_finish_request')) {
            return;
        }

        $lastRunFile = ROOT_PATH . '/var/cache/cron_last_run.txt';
        $interval = (int) ($this->config['cron']['email_interval_seconds'] ?? 60);

        if (file_exists($lastRunFile)) {
            $lastRun = (int) file_get_contents($lastRunFile);
            if (time() - $lastRun < $interval) {
                return;
            }
        }

        fastcgi_finish_request();

        file_put_contents($lastRunFile, (string) time());
        foreach ($this->moduleRegistry->getCronHandlers() as $handler) {
            try {
                $handler->execute($this);
            } catch (\Throwable $e) {
                Logger::error('Pseudo-cron handler failed', [
                    'handler' => get_class($handler),
                    'error'   => $e->getMessage(),
                ]);
            }
        }
    }

    // ── Accessors ────────────────────────────────────────────────────

    public function getConfig(): array
    {
        return $this->config;
    }

    public function getConfigValue(string $key, mixed $default = null): mixed
    {
        $keys = explode('.', $key);
        $value = $this->config;
        foreach ($keys as $k) {
            if (!is_array($value) || !array_key_exists($k, $value)) {
                return $default;
            }
            $value = $value[$k];
        }
        return $value;
    }

    public function getDb(): Database
    {
        return $this->db;
    }

    public function getRouter(): Router
    {
        return $this->router;
    }

    public function getTwig(): TwigRenderer
    {
        return $this->twig;
    }

    public function getSession(): Session
    {
        return $this->session;
    }

    public function getI18n(): I18n
    {
        return $this->i18n;
    }

    public function getModuleRegistry(): ModuleRegistry
    {
        return $this->moduleRegistry;
    }

    public function getRequest(): Request
    {
        return $this->request;
    }

    public function getPermissionResolver(): PermissionResolver
    {
        if ($this->permissionResolver === null) {
            $this->permissionResolver = new PermissionResolver($this->db, $this->session);

            $user = $this->session->getUser();
            if ($user !== null) {
                $this->permissionResolver->loadForUser((int) $user['id']);
            }
        }

        return $this->permissionResolver;
    }

    /**
     * Reset the singleton — tests only.
     */
    public static function reset(): void
    {
        self::$instance = null;
    }
}
