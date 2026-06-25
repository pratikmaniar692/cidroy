<?php

declare(strict_types=1);

namespace Forgeline\Http;

/**
 * A deliberately tiny router -- no framework. The brief's evaluation is
 * about correctness under failure, not about framework choice, so this
 * stays out of the way: a method+path match dispatches to a callable.
 */
final class Router
{
    /** @var array<int, array{method: string, pattern: string, handler: callable}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $this->routes[] = ['method' => $method, 'pattern' => $pattern, 'handler' => $handler];
    }

    public function dispatch(string $method, string $path): void
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }
            $params = $this->match($route['pattern'], $path);
            if ($params !== null) {
                ($route['handler'])($params);
                return;
            }
        }
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'not_found', 'path' => $path]);
    }

    /** @return array<string,string>|null */
    private function match(string $pattern, string $path): ?array
    {
        $patternParts = explode('/', trim($pattern, '/'));
        $pathParts = explode('/', trim($path, '/'));
        if (count($patternParts) !== count($pathParts)) {
            return null;
        }
        $params = [];
        foreach ($patternParts as $i => $part) {
            if (str_starts_with($part, '{') && str_ends_with($part, '}')) {
                $params[substr($part, 1, -1)] = $pathParts[$i];
            } elseif ($part !== $pathParts[$i]) {
                return null;
            }
        }
        return $params;
    }
}
