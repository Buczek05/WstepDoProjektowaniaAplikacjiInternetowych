<?php

require_once 'src/controllers/SecurityController.php';
require_once 'src/controllers/DashboardController.php';

class Routing {

    private static $instances = [];

    public static $routes = [
        "login" => [
            "controller" => "SecurityController",
            "action" => "login"
        ],
        "dashboard" => [
            "controller" => "DashboardController",
            "action" => "index"
        ],
        "" => [
            "controller" => "SecurityController",
            "action" => "login"
        ],
    ];

    private static function getController(string $controllerClass) {
        if (!isset(self::$instances[$controllerClass])) {
            self::$instances[$controllerClass] = new $controllerClass;
        }
        return self::$instances[$controllerClass];
    }

    public static function run(string $path) {
        $id = null;

        // regex: wyciągnij bazową ścieżkę i opcjonalny ID np. /dashboard/12234
        if (preg_match('#^([a-zA-Z]+)/(\d+)$#', $path, $matches)) {
            $path = $matches[1];
            $id = $matches[2];
        }

        if (array_key_exists($path, self::$routes)) {
            $controller = self::$routes[$path]["controller"];
            $action = self::$routes[$path]["action"];

            $controllerObj = self::getController($controller);
            $controllerObj->$action($id);
        } else {
            http_response_code(404);
            include 'public/views/404.html';
        }
    }
}
