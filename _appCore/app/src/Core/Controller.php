<?php

declare(strict_types=1);

namespace AppCore\Core;

/**
 * Abstract base controller.
 *
 * Gives subclasses access to the Application and standard helpers for
 * rendering, redirecting, enforcing auth/permissions, CSRF, flash
 * messages, and parameter access.
 */
abstract class Controller
{
    protected Application $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    protected function render(string $template, array $data = [], int $statusCode = 200): Response
    {
        $request = $this->app->getRequest();

        $data = array_merge([
            'app_name'    => $this->app->getConfigValue('app.name', 'appCore'),
            'app_lang'    => $this->app->getI18n()->getLanguage(),
            'theme'       => $this->app->getSession()->get('theme', 'light'),
            'csrf_token'  => $this->getCsrfToken(),
            'current_uri' => $request->getUri(),
            'user'        => $this->app->getSession()->get('user'),
            'nav_items'   => $this->app->getModuleRegistry()->getNavItems(
                $this->app->getSession()->get('user'),
            ),
            'breadcrumbs' => $data['breadcrumbs'] ?? [],
            'app_version' => trim((string) @file_get_contents(dirname(__DIR__, 2) . '/VERSION') ?: ''),
        ], $data);

        $html = $this->app->getTwig()->render($template, $data);
        return Response::html($html, $statusCode);
    }

    protected function json(mixed $data, int $statusCode = 200): Response
    {
        return Response::json($data, $statusCode);
    }

    protected function redirect(string $url, int $statusCode = 302): Response
    {
        return Response::redirect($url, $statusCode);
    }

    protected function requireAuth(): ?Response
    {
        if (!$this->app->getSession()->isAuthenticated()) {
            return Response::redirect('/login');
        }
        return null;
    }

    protected function requirePermission(string $permission): ?Response
    {
        $authCheck = $this->requireAuth();
        if ($authCheck !== null) {
            return $authCheck;
        }

        $resolver = $this->app->getPermissionResolver();
        if (!$resolver->can($permission)) {
            return $this->render('errors/403.html.twig', [], 403);
        }

        return null;
    }

    protected function getCsrfToken(): string
    {
        return (new Csrf($this->app->getSession()))->getToken();
    }

    protected function validateCsrf(Request $request): ?Response
    {
        $token = $request->getParam('_csrf_token', $request->getParam('_csrf', ''));
        if (!(new Csrf($this->app->getSession()))->validateToken((string) $token)) {
            return Response::html('CSRF token mismatch.', 403);
        }
        return null;
    }

    protected function getParam(string $key, mixed $default = null): mixed
    {
        return $this->app->getRequest()->getParam($key, $default);
    }

    protected function flash(string $type, string $message): void
    {
        $this->app->getSession()->flash($type, $message);
    }

    protected function t(string $key, array $params = []): string
    {
        return $this->app->getI18n()->t($key, $params);
    }
}
