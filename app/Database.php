<?php
declare(strict_types=1);

namespace App;

use PDO;

final class Database
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }
        $db = require BASE_PATH . '/config/database.php';
        if ($db['name'] === '' || $db['user'] === '') {
            throw new \RuntimeException('Database configuration is incomplete.');
        }
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['name']);
        self::$pdo = new PDO($dsn, $db['user'], $db['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        // Hosting MySQL may use a different server timezone. Keep TIMESTAMP
        // reads/writes aligned with the application's Asia/Dhaka schedule.
        self::$pdo->exec("SET time_zone = '+06:00'");
        (new \App\Services\DatabaseUpgradeService(self::$pdo))->upgrade();
        return self::$pdo;
    }
}
