<?php

declare(strict_types=1);

namespace App\Core;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;
use Twig\TwigFilter;

/**
 * Twig template renderer.
 *
 * Initialises the Twig environment with template paths, caching,
 * and custom functions/filters for ScoutKeeper.
 */
class TwigRenderer
{
    private Environment $twig;

    public function __construct(Application $app)
    {
        $loader = new FilesystemLoader();

        // Add core template paths
        $loader->addPath(ROOT_PATH . '/app/templates');

        // Add module template paths
        $modulesPath = ROOT_PATH . '/app/modules';
        if (is_dir($modulesPath)) {
            $dirs = glob($modulesPath . '/*/templates');
            foreach ($dirs as $dir) {
                $moduleName = strtolower(basename(dirname($dir)));
                $loader->addPath($dir, $moduleName);
            }
        }

        $isDebug = $app->getConfigValue('app.debug', false);

        $this->twig = new Environment($loader, [
            'cache' => ROOT_PATH . '/var/cache/twig',
            'auto_reload' => $isDebug,
            'debug' => $isDebug,
            'strict_variables' => $isDebug,
            'autoescape' => 'html',
        ]);

        $this->registerFunctions($app);
        $this->registerFilters();
    }

    /**
     * Render a template with data.
     *
     * @param string $template Template path relative to a registered loader path
     * @param array $data Variables to pass to the template
     * @return string Rendered HTML
     */
    public function render(string $template, array $data = []): string
    {
        return $this->twig->render($template, $data);
    }

    /**
     * Get the underlying Twig environment (for advanced use or testing).
     */
    public function getEnvironment(): Environment
    {
        return $this->twig;
    }

    /**
     * Register custom Twig functions.
     */
    private function registerFunctions(Application $app): void
    {
        // Translation function: {{ t('key', {param: 'value'}) }}
        $this->twig->addFunction(new TwigFunction('t', function (string $key, array $params = []) use ($app): string {
            return $app->getI18n()->t($key, $params);
        }));

        // CSRF hidden field: {{ csrf_field()|raw }}
        $this->twig->addFunction(new TwigFunction('csrf_field', function () use ($app): string {
            $csrf = new Csrf($app->getSession());
            return $csrf->field();
        }, ['is_safe' => ['html']]));

        // CSRF token value: {{ csrf_token() }}
        $this->twig->addFunction(new TwigFunction('csrf_token', function () use ($app): string {
            $csrf = new Csrf($app->getSession());
            return $csrf->getToken();
        }));

        // Route URL generation: {{ route('members.view', {id: 42}) }}
        $this->twig->addFunction(new TwigFunction('route', function (string $name, array $params = []) use ($app): string {
            return $app->getRouter()->generateUrl($name, $params);
        }));

        // Asset URL with cache busting: {{ asset('css/app.css') }}
        $this->twig->addFunction(new TwigFunction('asset', function (string $path) use ($app): string {
            $fullPath = ROOT_PATH . '/assets/' . ltrim($path, '/');
            $version = file_exists($fullPath) ? filemtime($fullPath) : '0';
            return '/assets/' . ltrim($path, '/') . '?v=' . $version;
        }));

        // Permission check: {% if has_permission('members.read') %}
        $this->twig->addFunction(new TwigFunction('has_permission', function (string $permission) use ($app): bool {
            if (!$app->getSession()->isAuthenticated()) {
                return false;
            }
            return $app->getPermissionResolver()->can($permission);
        }));

        // Current route check: {% if current_route() == '/members' %}
        $this->twig->addFunction(new TwigFunction('current_route', function () use ($app): string {
            return $app->getRequest()->getUri();
        }));

        // Flash messages: {% for message in flash('success') %}
        $this->twig->addFunction(new TwigFunction('flash', function () use ($app): array {
            return $app->getSession()->getFlash();
        }));

        // Active languages for the topbar switcher: {% for lang in available_languages() %}
        $this->twig->addFunction(new TwigFunction('available_languages', function () use ($app): array {
            return array_values(array_filter(
                $app->getI18n()->getAvailableLanguages(),
                fn(array $lang): bool => (bool) $lang['is_active']
            ));
        }));
    }

    /**
     * Register custom Twig filters.
     */
    private function registerFilters(): void
    {
        // Time ago: {{ date|time_ago }}
        $this->twig->addFilter(new TwigFilter('time_ago', function (string|\DateTimeInterface $datetime): string {
            if (is_string($datetime)) {
                $datetime = new \DateTimeImmutable($datetime);
            }
            $diff = (new \DateTimeImmutable())->diff($datetime);

            if ($diff->y > 0) {
                return $diff->y . ' year' . ($diff->y > 1 ? 's' : '') . ' ago';
            }
            if ($diff->m > 0) {
                return $diff->m . ' month' . ($diff->m > 1 ? 's' : '') . ' ago';
            }
            if ($diff->d > 0) {
                return $diff->d . ' day' . ($diff->d > 1 ? 's' : '') . ' ago';
            }
            if ($diff->h > 0) {
                return $diff->h . ' hour' . ($diff->h > 1 ? 's' : '') . ' ago';
            }
            if ($diff->i > 0) {
                return $diff->i . ' minute' . ($diff->i > 1 ? 's' : '') . ' ago';
            }
            return 'just now';
        }));

        // Date formatting: {{ date|format_date('d M Y') }}
        $this->twig->addFilter(new TwigFilter('format_date', function (string|\DateTimeInterface|null $datetime, string $format = 'd M Y'): string {
            if ($datetime === null) {
                return '';
            }
            if (is_string($datetime)) {
                $datetime = new \DateTimeImmutable($datetime);
            }
            return $datetime->format($format);
        }));
    }
}
