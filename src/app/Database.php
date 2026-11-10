<?php
declare(strict_types=1);

namespace Library;

use mysqli;
use mysqli_sql_exception;

final class Database
{
    /**
     * Opens the application database using only process environment values.
     * Connection details are intentionally not included in thrown exceptions.
     */
    public static function connect(): mysqli
    {
        $host = self::requiredEnvironment('DB_HOST');
        $port = self::port();
        $name = self::requiredEnvironment('DB_NAME');
        $user = self::requiredEnvironment('DB_USER');
        $password = self::requiredEnvironment('DB_PASSWORD');

        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        try {
            $connection = new mysqli($host, $user, $password, $name, $port);
            $connection->set_charset('utf8mb4');
            return $connection;
        } catch (mysqli_sql_exception $exception) {
            throw new \RuntimeException('Database connection failed.', 0, $exception);
        }
    }

    private static function requiredEnvironment(string $name): string
    {
        $value = getenv($name);
        if ($value === false || $value === '') {
            throw new \RuntimeException("Missing required database configuration: {$name}.");
        }

        return $value;
    }

    private static function port(): int
    {
        $value = self::requiredEnvironment('DB_PORT');
        if (!ctype_digit($value) || (int) $value < 1 || (int) $value > 65535) {
            throw new \RuntimeException('DB_PORT must be an integer between 1 and 65535.');
        }

        return (int) $value;
    }
}
