<?php

declare(strict_types=1);

namespace AppCore\Core;

use FastRoute;

/**
 * URL router wrapping nikic/fast-route. Modules register their routes
 * during bootstrap via the module registry.
 */
class Router
{
    /** @var array<array{method: string, pattern: string, handler: array}> */
    private array $routes = [];

    /** @var array<string, array{pattern: string, handler: array}> */
    private array $namedRoutes = [];

    public function addRoute(string $method, string $pattern, array $handler, ?string $name = null): void
    {
        $this->routes[] = [
            'method'  => strtoupper($method),
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

    public function dispatch(Request $request): Response
    {
        $dispatcher = FastRoute\simpleDispatcher(function (FastRoute\RouteCollector $r): void {
            foreach ($this->routes as $route) {
                $r->addRoute($route['method'], $route['pattern'], $route['handler']);
            }
        });

        $routeInfo = $dispatcher->dispatch($request->getMethod(), $request->getUri());

        return match ($routeInfo[0]) {
            FastRoute\Dispatcher::NOT_FOUND          => $this->handleNotFound($request),
            FastRoute\Dispatcher::METHOD_NOT_ALLOWED => $this->handleMethodNotAllowed($routeInfo[1]),
            FastRoute\Dispatcher::FOUND              => $this->handleFound($routeInfo[1], $routeInfo[2], $request),
            default                                  => $this->handleNotFound($request),
        };
    }

    public function generateUrl(string $name, array $params = []): string
    {
        if (!isset($this->namedRoutes[$name])) {
            throw new \InvalidArgumentException("Route '$name' not found");
        }

        $pattern = $this->namedRoutes[$name]['pattern'];

        $url = preg_replace_callback('/\{(\w+)(?::[^}]+)?\}/', function ($matches) use ($params) {
            $key = $matches[1];
            if (!isset($params[$key])) {
                throw new \InvalidArgumentException("Missing parameter '$key' for route generation");
            }
            return (string) $params[$key];
        }, $pattern);

        return preg_replace('/\[[^\]]*\]/', '', (string) $url);
    }

    private function handleFound(array $handler, array $vars, Request $request): Response
    {
        [$controllerClass, $method] = $handler;

        $app = Application::getInstance();
        $controller = new $controllerClass($app);

        return $controller->$method($request, $vars);
    }

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

    private function handleMethodNotAllowed(array $allowedMethods): Response
    {
        $response = Response::json([
            'error'   => 'Method not allowed',
            'allowed' => $allowedMethods,
        ], 405);
        $response->setHeader('Allow', implode(', ', $allowedMethods));
        return $response;
    }
}
