<?php

namespace HunNomad\Database;

use PDO;
use PDOException;
use Redis;
use MongoDB\Client as MongoClient;
use Exception;

class Database
{
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

    /**
     * Lazy connection getter – PDO / MongoClient / Redis
     */
    public function getConnection(): mixed
    {
        try {
            if ($this->connect === null) {
                $this->connect = match ($this->driver) {
                    'mysql', 'pgsql', 'mssql', 'sqlsrv', 'oracle' => $this->pdoConnection(),
                    'mongodb'                                            => $this->mongoConnection(),
                    'redis'                                              => $this->redisConnection(),
                    default                                              => throw new PDOException("Unsupported driver: {$this->driver}"),
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

    /**
     * PDO connection factory
     */
    private function pdoConnection(): PDO
    {
        $dsn = match ($this->driver) {
            'mysql' => "mysql:host={$this->host};port={$this->port};dbname={$this->dbname};charset=utf8mb4",
            'pgsql' => "pgsql:host={$this->host};port={$this->port};dbname={$this->dbname}",
            'mssql', 'sqlsrv' => "sqlsrv:Server={$this->host},{$this->port};Database={$this->dbname}",
            'oracle' => "oci:dbname=//{$this->host}:{$this->port}/{$this->dbname};charset=AL32UTF8",
            default => throw new PDOException("Unsupported PDO driver: {$this->driver}"),
        };

        $pdo = new PDO($dsn, $this->user, $this->password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);

        return $pdo;
    }

    /**
     * MongoDB connection factory
     */
    private function mongoConnection(): MongoClient
    {
        $uri = "mongodb://{$this->user}:{$this->password}@{$this->host}:{$this->port}/{$this->dbname}";
        return new MongoClient($uri);
    }

    /**
     * Redis connection factory
     */
    private function redisConnection(): Redis
    {
        $redis = new Redis();
        $redis->connect($this->host, (int) $this->port);
        if ($this->password !== '') {
            $redis->auth($this->password);
        }
        return $redis;
    }

    /**
     * Simple error logger (file + PHP error_log)
     */
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

    private function isPdoDriver(): bool
    {
        return in_array($this->driver, ['mysql', 'pgsql', 'mssql', 'sqlsrv', 'oracle'], true);
    }

    private function isMongoDriver(): bool
    {
        return $this->driver === 'mongodb';
    }

    private function ensurePdo(): PDO
    {
        if (!$this->isPdoDriver()) {
            throw new Exception("This operation is only available for PDO drivers, current: {$this->driver}");
        }
        /** @var PDO $pdo */
        $pdo = $this->getConnection();
        return $pdo;
    }

    private function ensureMongo(): MongoClient
    {
        if (!$this->isMongoDriver()) {
            throw new Exception("This operation is only available for MongoDB driver, current: {$this->driver}");
        }
        /** @var MongoClient $client */
        $client = $this->getConnection();
        return $client;
    }

    /* =========================================================
     *  CRUD – INSERT
     * ========================================================= */

    /**
     * INSERT (CREATE) – array bemenettel
     *
     * @param string $table  SQL table / Mongo collection neve
     * @param array  $data   ['col' => value, ...]
     * @return mixed lastInsertId / insertedId / null
     * @throws Exception
     */
    public function insert(string $table, array $data): mixed
    {
        if ($this->isPdoDriver()) {
            $pdo  = $this->ensurePdo();
            $cols = array_keys($data);

            // MEGJEGYZÉS: $table és oszlopnevek NE legyenek user inputból!
            $placeholders = array_map(fn($c) => ':' . $c, $cols);
            $sql = "INSERT INTO {$table} (" . implode(',', $cols) . ")
                    VALUES (" . implode(',', $placeholders) . ")";

            $stmt = $pdo->prepare($sql);
            foreach ($data as $col => $val) {
                $stmt->bindValue(':' . $col, $val);
            }
            $stmt->execute();

            try {
                return $pdo->lastInsertId();
            } catch (PDOException) {
                // Ha a driver nem támogatja a lastInsertId-t
                return null;
            }
        }

        if ($this->isMongoDriver()) {
            $client     = $this->ensureMongo();
            $collection = $client->selectCollection($this->dbname, $table);
            $result     = $collection->insertOne($data);
            return (string) $result->getInsertedId();
        }

        if ($this->driver === 'redis') {
            throw new Exception("Generic insert() is not implemented for Redis driver, use getConnection() and native Redis methods.");
        }

        throw new Exception("insert() not implemented for driver: {$this->driver}");
    }

    /* =========================================================
     *  CRUD – SELECT
     * ========================================================= */

    /**
     * SELECT (READ) – egyszerű where feltétellel, array bemenettel
     *
     * @param string $table
     * @param array  $conditions ['col' => value, ...] – mind AND kapcsolat
     * @param array  $options    ['columns'=>[], 'order'=> 'id DESC', 'limit'=>10, 'offset'=>0]
     * @return array
     * @throws Exception
     */
    public function select(string $table, array $conditions = [], array $options = []): array
    {
        if ($this->isPdoDriver()) {
            $pdo = $this->ensurePdo();

            $columns = $options['columns'] ?? ['*'];
            $columns = is_array($columns) ? implode(',', $columns) : $columns;

            $sql    = "SELECT {$columns} FROM {$table}";
            $params = [];

            if (!empty($conditions)) {
                $whereParts = [];
                foreach ($conditions as $col => $val) {
                    $param           = 'w_' . $col;
                    $whereParts[]    = "{$col} = :{$param}";
                    $params[$param]  = $val;
                }
                $sql .= " WHERE " . implode(' AND ', $whereParts);
            }

            if (!empty($options['order'])) {
                $sql .= " ORDER BY " . $options['order'];
            }

            if (isset($options['limit'])) {
                $sql .= " LIMIT " . (int) $options['limit'];
                if (isset($options['offset'])) {
                    $sql .= " OFFSET " . (int) $options['offset'];
                }
            }

            $stmt = $pdo->prepare($sql);
            foreach ($params as $name => $val) {
                $stmt->bindValue(':' . $name, $val);
            }

            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        if ($this->isMongoDriver()) {
            $client     = $this->ensureMongo();
            $collection = $client->selectCollection($this->dbname, $table);

            $filter = $conditions;
            $opts   = [];

            if (!empty($options['columns']) && is_array($options['columns'])) {
                $projection = [];
                foreach ($options['columns'] as $field) {
                    $projection[$field] = 1;
                }
                $opts['projection'] = $projection;
            }

            if (!empty($options['order'])) {
                // pl. "field DESC" vagy "field ASC"
                $parts = explode(' ', $options['order']);
                $field = $parts[0] ?? null;
                $dir   = strtoupper($parts[1] ?? 'ASC');
                if ($field) {
                    $opts['sort'] = [$field => ($dir === 'DESC' ? -1 : 1)];
                }
            }

            if (isset($options['limit'])) {
                $opts['limit'] = (int) $options['limit'];
            }

            if (isset($options['offset'])) {
                $opts['skip'] = (int) $options['offset'];
            }

            $cursor = $collection->find($filter, $opts);
            return iterator_to_array($cursor, false);
        }

        if ($this->driver === 'redis') {
            throw new Exception("Generic select() is not implemented for Redis driver, use getConnection() and native Redis methods.");
        }

        throw new Exception("select() not implemented for driver: {$this->driver}");
    }

    /* =========================================================
     *  CRUD – UPDATE
     * ========================================================= */

    /**
     * UPDATE – array data + array conditions
     *
     * @param string $table
     * @param array  $data       ['col' => value, ...]
     * @param array  $conditions ['col' => value, ...]
     * @return int   affected rows / modified count
     * @throws Exception
     */
    public function update(string $table, array $data, array $conditions): int
    {
        if ($this->isPdoDriver()) {
            $pdo = $this->ensurePdo();

            $setParts = [];
            $params   = [];

            foreach ($data as $col => $val) {
                $param          = 's_' . $col;
                $setParts[]     = "{$col} = :{$param}";
                $params[$param] = $val;
            }

            $whereParts = [];
            foreach ($conditions as $col => $val) {
                $param           = 'w_' . $col;
                $whereParts[]    = "{$col} = :{$param}";
                $params[$param]  = $val;
            }

            if (empty($whereParts)) {
                throw new Exception("Refusing to run UPDATE without WHERE conditions.");
            }

            $sql = "UPDATE {$table} SET " . implode(', ', $setParts)
                 . " WHERE " . implode(' AND ', $whereParts);

            $stmt = $pdo->prepare($sql);
            foreach ($params as $name => $val) {
                $stmt->bindValue(':' . $name, $val);
            }

            $stmt->execute();
            return $stmt->rowCount();
        }

        if ($this->isMongoDriver()) {
            $client     = $this->ensureMongo();
            $collection = $client->selectCollection($this->dbname, $table);

            $filter = $conditions;
            $update = ['$set' => $data];

            $result = $collection->updateMany($filter, $update);
            return $result->getModifiedCount();
        }

        if ($this->driver === 'redis') {
            throw new Exception("Generic update() is not implemented for Redis driver, use getConnection() and native Redis methods.");
        }

        throw new Exception("update() not implemented for driver: {$this->driver}");
    }

    /* =========================================================
     *  CRUD – DELETE
     * ========================================================= */

    /**
     * DELETE – array conditions
     *
     * @param string $table
     * @param array  $conditions ['col' => value, ...]
     * @return int   affected rows / deleted count
     * @throws Exception
     */
    public function delete(string $table, array $conditions): int
    {
        if ($this->isPdoDriver()) {
            $pdo = $this->ensurePdo();

            if (empty($conditions)) {
                throw new Exception("Refusing to run DELETE without WHERE conditions.");
            }

            $whereParts = [];
            $params     = [];

            foreach ($conditions as $col => $val) {
                $param           = 'w_' . $col;
                $whereParts[]    = "{$col} = :{$param}";
                $params[$param]  = $val;
            }

            $sql = "DELETE FROM {$table} WHERE " . implode(' AND ', $whereParts);

            $stmt = $pdo->prepare($sql);
            foreach ($params as $name => $val) {
                $stmt->bindValue(':' . $name, $val);
            }

            $stmt->execute();
            return $stmt->rowCount();
        }

        if ($this->isMongoDriver()) {
            $client     = $this->ensureMongo();
            $collection = $client->selectCollection($this->dbname, $table);

            $filter = $conditions;
            $result = $collection->deleteMany($filter);

            return $result->getDeletedCount();
        }

        if ($this->driver === 'redis') {
            throw new Exception("Generic delete() is not implemented for Redis driver, use getConnection() and native Redis methods.");
        }

        throw new Exception("delete() not implemented for driver: {$this->driver}");
    }

    /* =========================================================
     *  rawQuery() – kényelmi wrapper PDO-hoz
     * ========================================================= */

    /**
     * rawQuery – bármilyen SQL lefuttatása PDO-n
     *
     * - Ha SELECT/SHOW/DESCRIBE/PRAGMA/WITH: tömböt ad vissza
     * - Egyébként: rowCount() (int)
     *
     * @param string $sql
     * @param array  $params  asszociatív (':id'=>1 vagy 'id'=>1) / numerikus ([1=>'val', ...])
     * @return array|int
     * @throws Exception
     */
    public function rawQuery(string $sql, array $params = []): array|int
    {
        $pdo  = $this->ensurePdo();
        $stmt = $pdo->prepare($sql);

        foreach ($params as $key => $value) {
            if (is_int($key)) {
                // numerikus param: 0-based -> 1-based
                $stmt->bindValue($key + 1, $value);
            } else {
                $paramName = $key;
                if ($paramName[0] !== ':') {
                    $paramName = ':' . $paramName;
                }
                $stmt->bindValue($paramName, $value);
            }
        }

        $stmt->execute();

        $operation = strtolower(strtok(ltrim($sql), " \t\n\r"));

        if (in_array($operation, ['select', 'show', 'describe', 'pragma', 'with'], true)) {
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        return $stmt->rowCount();
    }

    /* =========================================================
     *  TRANZAKCIÓK – begin / commit / rollback
     * ========================================================= */

    /**
     * Tranzakció kezdése (PDO)
     */
    public function begin(): void
    {
        $pdo = $this->ensurePdo();
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
        }
    }

    /**
     * Tranzakció commit (PDO)
     */
    public function commit(): void
    {
        $pdo = $this->ensurePdo();
        if ($pdo->inTransaction()) {
            $pdo->commit();
        }
    }

    /**
     * Tranzakció rollback (PDO)
     */
    public function rollback(): void
    {
        $pdo = $this->ensurePdo();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }

    public function __destruct()
    {
        $this->connect = null;
    }
}

