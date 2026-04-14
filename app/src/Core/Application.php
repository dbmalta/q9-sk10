<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Application singleton — the central orchestrator for ScoutKeeper.
 *
 * Holds references to all core services (database, router, template engine,
 * session, i18n, module registry) and drives the request lifecycle.
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

    /**
     * Initialise the Application singleton with configuration.
     * Called once during bootstrap.
     */
    public static function init(array $config): void
    {
        if (self::$instance !== null) {
            return;
        }
        self::$instance = new self($config);
    }

    /**
     * Get the Application singleton instance.
     *
     * @throws \RuntimeException if not yet initialised
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            throw new \RuntimeException('Application not initialised. Call Application::init() first.');
        }
        return self::$instance;
    }

    /**
     * Run the full request lifecycle: session → modules → route → render.
     */
    public static function run(): void
    {
        $app = self::getInstance();

        // Register error handler
        $app->errorHandler = new ErrorHandler($app->config);
        $app->errorHandler->register();

        // Create request
        $app->request = Request::fromGlobals();

        // Start session
        $app->session = new Session($app->config);
        $app->session->start();

        // Initialise database
        $app->db = new Database($app->config['db']);

        // Initialise i18n
        $language = $app->session->get('language', $app->config['app']['language'] ?? 'en');
        $app->i18n = new I18n(ROOT_PATH . '/lang', $app->db, $language);

        // Initialise Twig
        $app->twig = new TwigRenderer($app);

        // Initialise module registry and load modules
        $app->moduleRegistry = new ModuleRegistry();
        $app->moduleRegistry->loadModules(ROOT_PATH . '/app/modules');

        // Initialise router and register routes
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

        // Dispatch the route
        $response = $app->router->dispatch($app->request);
        $app->sendResponse($response);

        // Pseudo-cron fallback: run pending cron tasks after response
        $app->runPseudoCron();
    }

    /**
     * Send the HTTP response to the client.
     */
    private function sendResponse(Response $response): void
    {
        http_response_code($response->getStatusCode());
        foreach ($response->getHeaders() as $name => $value) {
            header("$name: $value");
        }
        echo $response->getBody();
    }

    /**
     * Run pending cron tasks after the response has been sent.
     * Only activates when no real cron is configured and enough time has elapsed.
     */
    private function runPseudoCron(): void
    {
        // Only if fastcgi_finish_request is available (keeps response fast)
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

        // Execute cron handlers from module registry
        file_put_contents($lastRunFile, (string) time());
        foreach ($this->moduleRegistry->getCronHandlers() as $handler) {
            try {
                $handler->execute($this);
            } catch (\Throwable $e) {
                Logger::error('Pseudo-cron handler failed', [
                    'handler' => get_class($handler),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    // --- Accessors ---

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

    /**
     * Get the permission resolver, initialising it lazily for the current user.
     */
    public function getPermissionResolver(): PermissionResolver
    {
        if ($this->permissionResolver === null) {
            $this->permissionResolver = new PermissionResolver($this->db, $this->session);

            // Load permissions for the current user if authenticated
            $user = $this->session->getUser();
            if ($user !== null) {
                $this->permissionResolver->loadForUser((int) $user['id']);
            }
        }

        return $this->permissionResolver;
    }

    /**
     * Reset the singleton — used only in tests.
     */
    public static function reset(): void
    {
        self::$instance = null;
    }
}
