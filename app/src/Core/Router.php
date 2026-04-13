<?php

declare(strict_types=1);

namespace App\Core;

use FastRoute;

/**
 * URL router wrapping nikic/fast-route.
 *
 * Modules register their routes during bootstrap via the module registry.
 * The router dispatches incoming requests to the appropriate controller method.
 */
class Router
{
    /** @var array<array{method: string, pattern: string, handler: array}> */
    private array $routes = [];

    /** @var array<string, array{pattern: string, handler: array}> Named routes for URL generation */
    private array $namedRoutes = [];

    /**
     * Register a route.
     *
     * @param string $method HTTP method (GET, POST, PUT, DELETE)
     * @param string $pattern URL pattern with placeholders e.g. /members/{id:\d+}
     * @param array $handler [ControllerClass::class, 'methodName']
     * @param string|null $name Optional route name for URL generation
     */
    public function addRoute(string $method, string $pattern, array $handler, ?string $name = null): void
    {
        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $pattern,
            'handler' => $handler,
        ];

        if ($name !== null) {
            $this->namedRoutes[$name] = [
                'pattern' => $pattern,
                'handler' => $handler,
            ];
        }
    }

    /**
     * Convenience methods for common HTTP verbs.
     */
    public function get(string $pattern, array $handler, ?string $name = null): void
    {
        $this->addRoute('GET', $pattern, $handler, $name);
    }

    public function post(string $pattern, array $handler, ?string $name = null): void
    {
        $this->addRoute('POST', $pattern, $handler, $name);
    }

    public function put(string $pattern, array $handler, ?string $name = null): void
    {
        $this->addRoute('PUT', $pattern, $handler, $name);
    }

    public function delete(string $pattern, array $handler, ?string $name = null): void
    {
        $this->addRoute('DELETE', $pattern, $handler, $name);
    }

    /**
     * Dispatch the incoming request to a handler.
     *
     * @param Request $request The current request
     * @return Response The response from the matched handler, or an error response
     */
    public function dispatch(Request $request): Response
    {
        $dispatcher = FastRoute\simpleDispatcher(function (FastRoute\RouteCollector $r) {
            foreach ($this->routes as $route) {
                $r->addRoute($route['method'], $route['pattern'], $route['handler']);
            }
        });

        $routeInfo = $dispatcher->dispatch($request->getMethod(), $request->getUri());

        switch ($routeInfo[0]) {
            case FastRoute\Dispatcher::NOT_FOUND:
                return $this->handleNotFound($request);

            case FastRoute\Dispatcher::METHOD_NOT_ALLOWED:
                return $this->handleMethodNotAllowed($routeInfo[1]);

            case FastRoute\Dispatcher::FOUND:
                return $this->handleFound($routeInfo[1], $routeInfo[2], $request);
        }

        return $this->handleNotFound($request);
    }

    /**
     * Generate a URL for a named route.
     *
     * @param string $name Route name
     * @param array $params Route parameters to substitute
     * @return string The generated URL
     * @throws \InvalidArgumentException if route name not found
     */
    public function generateUrl(string $name, array $params = []): string
    {
        if (!isset($this->namedRoutes[$name])) {
            throw new \InvalidArgumentException("Route '$name' not found");
        }

        $pattern = $this->namedRoutes[$name]['pattern'];

        // Replace named placeholders like {id:\d+} or {slug}
        $url = preg_replace_callback('/\{(\w+)(?::[^}]+)?\}/', function ($matches) use ($params) {
            $key = $matches[1];
            if (!isset($params[$key])) {
                throw new \InvalidArgumentException("Missing parameter '$key' for route generation");
            }
            return (string) $params[$key];
        }, $pattern);

        // Strip optional segments without values
        $url = preg_replace('/\[[^\]]*\]/', '', $url);

        return $url;
    }

    /**
     * Handle a matched route by instantiating the controller and calling the method.
     */
    private function handleFound(array $handler, array $vars, Request $request): Response
    {
        [$controllerClass, $method] = $handler;

        $app = Application::getInstance();
        $controller = new $controllerClass($app);

        return $controller->$method($request, $vars);
    }

    /**
     * Handle a 404 Not Found.
     */
    private function handleNotFound(Request $request): Response
    {
        if ($request->isHtmx() || $request->isAjax()) {
            return Response::html('<p>Not found</p>', 404);
        }

        try {
            $app = Application::getInstance();
            $html = $app->getTwig()->render('errors/404.html.twig');
            return Response::html($html, 404);
        } catch (\Throwable) {
            return Response::html('<h1>404 — Not Found</h1>', 404);
        }
    }

    /**
     * Handle a 405 Method Not Allowed.
     */
    private function handleMethodNotAllowed(array $allowedMethods): Response
    {
        $response = Response::json([
            'error' => 'Method not allowed',
            'allowed' => $allowedMethods,
        ], 405);
        $response->setHeader('Allow', implode(', ', $allowedMethods));
        return $response;
    }
}
