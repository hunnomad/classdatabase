<?php

namespace HunNomad\Database;

use PDO;
use PDOException;
use Redis;
use MongoDB\Client as MongoClient;
use Exception;

class Database {

    private mixed $connect = null;
    private string $driver;

    public function __construct(
        private string $host,
        private string $dbname,
        private string $user = '',
        private string $password = '',
        private string $port = '3306',
        string $driver = 'mysql'
    ) {
        $this->driver = strtolower(trim($driver));
    }

    public function getConnection(): mixed
    {
        try {
            if ($this->connect === null) {
                $this->connect = match ($this->driver) {
                    'mysql', 'pgsql', 'mssql', 'sqlsrv', 'oracle' => $this->pdoConnection(),
                    'mongodb' => $this->mongoConnection(),
                    'redis' => $this->redisConnection(),
                    default => throw new PDOException("Unsupported driver: $this->driver"),
                };
            }
            return $this->connect;
        } catch (Exception $e) {
            $errorMessage = sprintf(
                "[%s] Database connection error\nFile: %s\nLine: %d\nDriver: %s\nFunction: %s\nError: %s\n",
                date('Y-m-d H:i:s'),
                __FILE__,
                __LINE__,
                $this->driver,
                debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function'] ?? 'unknown',
                $e->getMessage()
            );

            echo $errorMessage;
            $this->log($errorMessage);
            die("Connection error [" . basename(__FILE__) . "]. Details have been logged.");
        }
    }

    private function pdoConnection(): PDO
    {
        $dsn = match ($this->driver) {
            'mysql' => "mysql:host={$this->host};port={$this->port};dbname={$this->dbname};charset=utf8mb4",
            'pgsql' => "pgsql:host={$this->host};port={$this->port};dbname={$this->dbname}",
            'mssql', 'sqlsrv' => "sqlsrv:Server={$this->host},{$this->port};Database={$this->dbname}",
            'oracle' => "oci:dbname=//{$this->host}:{$this->port}/{$this->dbname};charset=AL32UTF8",
            default => throw new PDOException("Unsupported PDO driver: $this->driver")
        };

        $pdo = new PDO($dsn, $this->user, $this->password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);

        return $pdo;
    }

    private function mongoConnection(): MongoClient
    {
        $uri = "mongodb://{$this->user}:{$this->password}@{$this->host}:{$this->port}/{$this->dbname}";
        return new MongoClient($uri);
    }

    private function redisConnection(): Redis
    {
        $redis = new Redis();
        $redis->connect($this->host, (int)$this->port);
        if ($this->password !== '') {
            $redis->auth($this->password);
        }
        return $redis;
    }

    private function log(string $message): void
    {
        $logFile = __DIR__ . '/error_log.txt';
        file_put_contents($logFile, $message, FILE_APPEND);
        error_log($message, 0);
    }

    public function getDriver(): string
    {
        return $this->driver;
    }

    public function __destruct()
    {
        $this->connect = null;
    }
}

?>
