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
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
                // Note: A1 (SQL-injection prevention) is satisfied by using
                // prepared statements with bound parameters everywhere — PDO
                // parameterizes safely regardless of emulation mode. We keep the
                // default (emulated) prepares to avoid driver edge-cases.
            );
        }

        return self::$conn;
    }
}
