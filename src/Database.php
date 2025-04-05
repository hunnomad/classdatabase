<?php

namespace HunNomad\Database;

use PDO;
use PDOException;

class Database {

    private ?PDO $connect = null;
    private string $driver;

    public function __construct(
        private string $host,
        private string $dbname,
        private string $user,
        private string $password,
        private string $port,
        string $driver = 'mysql' // Default driver
    ) {
        $this->driver = strtolower(trim($driver)); // lowercase, trim for safety
    }

    public function getConnection(): ?PDO
    {
        try {
            if ($this->connect === null) {
                $dsn = $this->getDsn();
                $this->connect = new PDO($dsn, $this->user, $this->password);
                $this->connect->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $this->connect->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
                $this->connect->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);
            }
            return $this->connect;
        } catch (PDOException $e) {
            $errorMessage = sprintf(
                "[%s] An error occurred while establishing a database connection:\File: %a\Function: %s\nDriver: %s\nError message: %s\n",
                date('Y-m-d H:i:s'),
                __FILE__,
                debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function'] ?? 'unknown',
                $this->driver,
                $e->getMessage()
            );

            echo $errorMessage;
            $this->log($errorMessage);
            die("Connection error [".basename(__FILE__)."]. Details have been logged.");
        }
    }

    private function getDsn(): string
    {
        return match ($this->driver) {
            'mysql' => "mysql:host=$this->host;port=$this->port;dbname=$this->dbname;charset=utf8mb4",
            'pgsql' => "pgsql:host=$this->host;port=$this->port;dbname=$this->dbname",
            default => throw new PDOException("Unsupported driver: $this->driver"),
        };
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
