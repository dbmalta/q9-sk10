<?php

declare(strict_types=1);

namespace AppCore\Core;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Twig 3 renderer.
 *
 * Adds /app/templates as the default path and exposes every module's
 * templates/ directory as `@{moduleId}`. Registers the standard global
 * functions (t, csrf_field, route, asset, has_permission, flash,
 * current_route, available_languages).
 */
class TwigRenderer
{
    private Environment $twig;

    public function __construct(Application $app)
    {
        $loader = new FilesystemLoader();
        $loader->addPath(ROOT_PATH . '/app/templates');

        $modulesPath = ROOT_PATH . '/app/modules';
        if (is_dir($modulesPath)) {
            $dirs = glob($modulesPath . '/*/templates') ?: [];
            foreach ($dirs as $dir) {
                $moduleName = strtolower(basename(dirname($dir)));
                $loader->addPath($dir, $moduleName);
            }
        }

        $isDebug = (bool) $app->getConfigValue('app.debug', false);

        $this->twig = new Environment($loader, [
            'cache'            => ROOT_PATH . '/var/cache/twig',
            'auto_reload'      => $isDebug,
            'debug'            => $isDebug,
            'strict_variables' => $isDebug,
            'autoescape'       => 'html',
        ]);

        $this->registerFunctions($app);
        $this->registerFilters();
    }

    public function render(string $template, array $data = []): string
    {
        return $this->twig->render($template, $data);
    }

    public function getEnvironment(): Environment
    {
        return $this->twig;
    }

    private function registerFunctions(Application $app): void
    {
        $this->twig->addFunction(new TwigFunction('t', function (string $key, array $params = []) use ($app): string {
            return $app->getI18n()->t($key, $params);
        }));

        $this->twig->addFunction(new TwigFunction('csrf_field', function () use ($app): string {
            return (new Csrf($app->getSession()))->field();
        }, ['is_safe' => ['html']]));

        $this->twig->addFunction(new TwigFunction('csrf_token', function () use ($app): string {
            return (new Csrf($app->getSession()))->getToken();
        }));

        $this->twig->addFunction(new TwigFunction('route', function (string $name, array $params = []) use ($app): string {
            return $app->getRouter()->generateUrl($name, $params);
        }));

        $this->twig->addFunction(new TwigFunction('asset', function (string $path): string {
            $fullPath = ROOT_PATH . '/assets/' . ltrim($path, '/');
            $version = file_exists($fullPath) ? (string) filemtime($fullPath) : '0';
            return '/assets/' . ltrim($path, '/') . '?v=' . $version;
        }));

        $this->twig->addFunction(new TwigFunction('has_permission', function (string $permission) use ($app): bool {
            if (!$app->getSession()->isAuthenticated()) {
                return false;
            }
            return $app->getPermissionResolver()->can($permission);
        }));

        $this->twig->addFunction(new TwigFunction('current_route', function () use ($app): string {
            return $app->getRequest()->getUri();
        }));

        $this->twig->addFunction(new TwigFunction('flash', function () use ($app): array {
            return $app->getSession()->getFlash();
        }));

        $this->twig->addFunction(new TwigFunction('available_languages', function () use ($app): array {
            return array_values(array_filter(
                $app->getI18n()->getAvailableLanguages(),
                static fn(array $lang): bool => (bool) $lang['is_active']
            ));
        }));
    }

    private function registerFilters(): void
    {
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
