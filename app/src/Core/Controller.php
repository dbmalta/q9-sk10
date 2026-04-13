<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Abstract base controller.
 *
 * Provides access to the Application services and common helpers
 * for rendering templates, redirecting, enforcing auth/permissions,
 * and handling CSRF tokens.
 */
abstract class Controller
{
    protected Application $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    /**
     * Render a Twig template with standard data injected.
     *
     * @param string $template Template path relative to templates root
     * @param array $data Variables to pass to the template
     * @param int $statusCode HTTP status code
     * @return Response
     */
    protected function render(string $template, array $data = [], int $statusCode = 200): Response
    {
        $request = $this->app->getRequest();

        // Inject standard template variables
        $data = array_merge([
            'app_name' => $this->app->getConfigValue('app.name', 'ScoutKeeper'),
            'app_lang' => $this->app->getI18n()->getLanguage(),
            'theme' => $this->app->getSession()->get('theme', 'light'),
            'csrf_token' => $this->getCsrfToken(),
            'current_uri' => $request->getUri(),
            'user' => $this->app->getSession()->get('user'),
            'nav_items' => $this->app->getModuleRegistry()->getNavItems(
                $this->app->getSession()->get('user')
            ),
            'breadcrumbs' => $data['breadcrumbs'] ?? [],
        ], $data);

        // HTMX requests get only the partial content, not the full layout
        if ($request->isHtmx()) {
            $html = $this->app->getTwig()->render($template, $data);
        } else {
            $html = $this->app->getTwig()->render($template, $data);
        }

        return Response::html($html, $statusCode);
    }

    /**
     * Return a JSON response.
     */
    protected function json(mixed $data, int $statusCode = 200): Response
    {
        return Response::json($data, $statusCode);
    }

    /**
     * Return a redirect response.
     */
    protected function redirect(string $url, int $statusCode = 302): Response
    {
        return Response::redirect($url, $statusCode);
    }

    /**
     * Require the user to be authenticated. Returns a redirect to login if not.
     *
     * @return Response|null Null if authenticated, redirect Response if not
     */
    protected function requireAuth(): ?Response
    {
        if (!$this->app->getSession()->isAuthenticated()) {
            return Response::redirect('/login');
        }
        return null;
    }

    /**
     * Require a specific permission. Returns a 403 response if denied.
     *
     * @param string $permission Permission key e.g. 'members.read'
     * @return Response|null Null if permitted, 403 Response if denied
     */
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

    /**
     * Get the current CSRF token, generating one if needed.
     */
    protected function getCsrfToken(): string
    {
        $csrf = new Csrf($this->app->getSession());
        return $csrf->getToken();
    }

    /**
     * Validate the CSRF token from a POST request.
     *
     * Returns a 403 response if invalid, or null if valid.
     */
    protected function validateCsrf(Request $request): ?Response
    {
        $token = $request->getParam('_csrf', $request->getParam('_csrf_token', ''));
        $csrf = new Csrf($this->app->getSession());
        if (!$csrf->validateToken((string) $token)) {
            return Response::html('CSRF token mismatch.', 403);
        }
        return null;
    }

    /**
     * Get a request parameter.
     */
    protected function getParam(string $key, mixed $default = null): mixed
    {
        return $this->app->getRequest()->getParam($key, $default);
    }

    /**
     * Set a flash message for the next request.
     */
    protected function flash(string $type, string $message): void
    {
        $this->app->getSession()->flash($type, $message);
    }
}
