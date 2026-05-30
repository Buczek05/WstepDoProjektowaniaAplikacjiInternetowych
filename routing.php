<?php

require_once 'src/controllers/SecurityController.php';
require_once 'src/controllers/DashboardController.php';
require_once 'src/Middleware/checkRequestAllowed.php';

class Routing {
    private static array $instances = [];

    public static array $routes = [
        '' => [
            'controller' => 'SecurityController',
            'action'     => 'login',
        ],
        'login' => [
            'controller' => 'SecurityController',
            'action'     => 'login',
        ],
        'register' => [
            'controller' => 'SecurityController',
            'action'     => 'register',
        ],
        'logout' => [
            'controller' => 'SecurityController',
            'action'     => 'logout',
        ],
        'dashboard' => [
            'controller' => 'DashboardController',
            'action'     => 'index',
        ],
    ];

    private static function getController(string $controllerClass): object
    {
        if (!isset(self::$instances[$controllerClass])) {
            self::$instances[$controllerClass] = new $controllerClass();
        }
        return self::$instances[$controllerClass];
    }

    public static function run(string $path): void
    {
        $id = null;

        // Extract optional numeric ID, e.g. /dashboard/123
        if (preg_match('#^([a-zA-Z]+)/(\d+)$#', $path, $matches)) {
            $path = $matches[1];
            $id   = $matches[2];
        }

        if (!array_key_exists($path, self::$routes)) {
            http_response_code(404);
            include 'public/views/404.html';
            return;
        }

        $controllerClass = self::$routes[$path]['controller'];
        $action          = self::$routes[$path]['action'];

        $controller = self::getController($controllerClass);

        checkRequestAllowed($controller, $action);

        $controller->$action($id);
    }
}
