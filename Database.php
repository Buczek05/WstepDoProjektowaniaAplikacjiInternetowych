<?php

require_once "config.php";

class Database {
    private static ?PDO $conn = null;

    public static function connect(): PDO
    {
        if (self::$conn === null) {
            $host     = HOST;
            $database = DATABASE;
            $username = USERNAME;
            $password = PASSWORD;

            self::$conn = new PDO(
                "pgsql:host={$host};port=5432;dbname={$database}",
                $username,
                $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        }

        return self::$conn;
    }
}
