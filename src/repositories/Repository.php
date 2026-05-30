<?php

require_once __DIR__ . "/../../Database.php";

class Repository {
    protected PDO $database;

    public function __construct() {
        $this->database = Database::connect();
    }
}
