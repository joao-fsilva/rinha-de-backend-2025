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
            $user = 'rinhabackend';
            $password ='rinhabackend';
            
            $dsn = "pgsql:host=db;port=5432;dbname=rinhabackend";

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
