<?php

namespace App;

use PDO;
use PDOException;

class Database
{
    private static ?PDO $pdo = null;

    public function __construct()
    {
        if (self::$pdo === null) {
            $host = getenv('DB_HOST') ?: 'db';
            $port = getenv('DB_PORT') ?: '5432';
            $dbname = getenv('DB_NAME') ?: 'rinhabackend';
            $user = getenv('DB_USER') ?: 'rinhabackend';
            $password = getenv('DB_PASS') ?: 'rinhabackend';
            
            $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";

            // Persist the connection to avoid reconnecting on every request
            $options = [
                PDO::ATTR_PERSISTENT => true,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ];

            try {
                self::$pdo = new PDO($dsn, $user, $password, $options);
            } catch (PDOException $e) {
                // Log the error and die to prevent the application from running without a DB
                error_log("DB Connection Error: " . $e->getMessage());
                die("Could not connect to the database.");
            }
        }
    }

    public function getPdo(): PDO
    {
        return self::$pdo;
    }
}
