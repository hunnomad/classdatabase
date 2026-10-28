<?php

namespace HunNomad\Database;

use PDO;
use PDOException;
use PDOStatement;
use Redis;
use MongoDB\Client as MongoClient;
use Exception;
use RuntimeException;

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
     *
     * @throws RuntimeException ha a kapcsolat nem jön létre (az eredeti hiba a getPrevious()-ban)
     */
    public function getConnection(): mixed
    {
        if ($this->connect !== null) {
            return $this->connect;
        }

        try {
            $this->connect = match ($this->driver) {
                'mysql', 'pgsql', 'mssql', 'sqlsrv', 'oracle' => $this->pdoConnection(),
                'mongodb'                                     => $this->mongoConnection(),
                'redis'                                       => $this->redisConnection(),
                default                                       => throw new RuntimeException("Unsupported driver: {$this->driver}"),
            };
            return $this->connect;
        } catch (Exception $e) {
            $this->log(sprintf(
                "[%s] Database connection error\nDriver: %s\nFile: %s\nLine: %d\nError: %s\n",
                date('Y-m-d H:i:s'),
                $this->driver,
                $e->getFile(),
                $e->getLine(),
                $e->getMessage()
            ));

            // Könyvtárként nem echo-zunk és nem die()-olunk: a hívó dönti el, mi legyen
            throw new RuntimeException("Database connection error ({$this->driver}): " . $e->getMessage(), 0, $e);
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
        $uri     = "mongodb://{$this->host}:{$this->port}";
        $options = [];

        // Hitelesítés URI-opcióként: így a speciális karaktereket nem kell escapelni,
        // és felhasználó nélkül sem jön létre érvénytelen "mongodb://:@host" URI
        if ($this->user !== '') {
            $options['username']   = $this->user;
            $options['password']   = $this->password;
            $options['authSource'] = $this->dbname !== '' ? $this->dbname : 'admin';
        }

        return new MongoClient($uri, $options);
    }

    /**
     * Redis connection factory
     */
    private function redisConnection(): Redis
    {
        $redis = new Redis();
        if (!$redis->connect($this->host, (int) $this->port)) {
            throw new RuntimeException("Could not connect to Redis at {$this->host}:{$this->port}");
        }
        if ($this->password !== '') {
            // Redis 6+ ACL: felhasználónév + jelszó, egyébként csak jelszó
            $auth = $this->user !== '' ? [$this->user, $this->password] : $this->password;
            if (!$redis->auth($auth)) {
                throw new RuntimeException("Redis authentication failed");
            }
        }
        return $redis;
    }

    /**
     * Simple error logger (PHP error_log – nem ír a vendor könyvtárba)
     */
    private function log(string $message): void
    {
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

    /**
     * WHERE feltétel indexelt placeholderekkel (pl. "u.id" oszlopnévnél is érvényes paraméternév)
     */
    private function buildWhere(array $conditions, array &$params): string
    {
        $parts = [];
        foreach ($conditions as $col => $val) {
            $param          = 'w' . count($params);
            $parts[]        = "{$col} = :{$param}";
            $params[$param] = $val;
        }
        return implode(' AND ', $parts);
    }

    /**
     * LIMIT / OFFSET driverfüggő szintaxissal
     */
    private function buildLimit(string $sql, array $options): string
    {
        if (!isset($options['limit'])) {
            return $sql;
        }

        $limit  = (int) $options['limit'];
        $offset = (int) ($options['offset'] ?? 0);

        if (in_array($this->driver, ['mssql', 'sqlsrv', 'oracle'], true)) {
            // SQL Server-en az OFFSET/FETCH csak ORDER BY-jal érvényes
            if ($this->driver !== 'oracle' && empty($options['order'])) {
                $sql .= " ORDER BY (SELECT NULL)";
            }
            return $sql . " OFFSET {$offset} ROWS FETCH NEXT {$limit} ROWS ONLY";
        }

        $sql .= " LIMIT {$limit}";
        if (isset($options['offset'])) {
            $sql .= " OFFSET {$offset}";
        }
        return $sql;
    }

    private function bindAll(PDOStatement $stmt, array $params): void
    {
        foreach ($params as $name => $val) {
            $stmt->bindValue(':' . $name, $val);
        }
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
        if (empty($data)) {
            throw new Exception("insert() requires at least one column.");
        }

        if ($this->isPdoDriver()) {
            $pdo    = $this->ensurePdo();
            $cols   = array_keys($data);
            $params = [];

            foreach (array_values($data) as $i => $val) {
                $params['v' . $i] = $val;
            }

            // MEGJEGYZÉS: $table és oszlopnevek NE legyenek user inputból!
            $placeholders = array_map(fn($p) => ':' . $p, array_keys($params));
            $sql = "INSERT INTO {$table} (" . implode(',', $cols) . ")
                    VALUES (" . implode(',', $placeholders) . ")";

            $stmt = $pdo->prepare($sql);
            $this->bindAll($stmt, $params);
            $stmt->execute();

            try {
                $id = $pdo->lastInsertId();
                return $id === false ? null : $id;
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
                $sql .= " WHERE " . $this->buildWhere($conditions, $params);
            }

            if (!empty($options['order'])) {
                $sql .= " ORDER BY " . $options['order'];
            }

            $sql = $this->buildLimit($sql, $options);

            $stmt = $pdo->prepare($sql);
            $this->bindAll($stmt, $params);

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
                $parts = preg_split('/\s+/', trim($options['order']));
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
        if (empty($data)) {
            throw new Exception("update() requires at least one column to set.");
        }

        if ($this->isPdoDriver()) {
            if (empty($conditions)) {
                throw new Exception("Refusing to run UPDATE without WHERE conditions.");
            }

            $pdo = $this->ensurePdo();

            $setParts = [];
            $params   = [];

            foreach ($data as $col => $val) {
                $param          = 's' . count($params);
                $setParts[]     = "{$col} = :{$param}";
                $params[$param] = $val;
            }

            $sql = "UPDATE {$table} SET " . implode(', ', $setParts)
                 . " WHERE " . $this->buildWhere($conditions, $params);

            $stmt = $pdo->prepare($sql);
            $this->bindAll($stmt, $params);

            $stmt->execute();
            return $stmt->rowCount();
        }

        if ($this->isMongoDriver()) {
            $client     = $this->ensureMongo();
            $collection = $client->selectCollection($this->dbname, $table);

            $filter = $conditions;
            $update = ['$set' => $data];

            $result = $collection->updateMany($filter, $update);
            return (int) $result->getModifiedCount();
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
            if (empty($conditions)) {
                throw new Exception("Refusing to run DELETE without WHERE conditions.");
            }

            $pdo    = $this->ensurePdo();
            $params = [];
            $sql    = "DELETE FROM {$table} WHERE " . $this->buildWhere($conditions, $params);

            $stmt = $pdo->prepare($sql);
            $this->bindAll($stmt, $params);

            $stmt->execute();
            return $stmt->rowCount();
        }

        if ($this->isMongoDriver()) {
            $client     = $this->ensureMongo();
            $collection = $client->selectCollection($this->dbname, $table);

            $filter = $conditions;
            $result = $collection->deleteMany($filter);

            return (int) $result->getDeletedCount();
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
     * - Ha a lekérdezés eredményhalmazt ad (SELECT, SHOW, WITH, ... RETURNING): tömböt ad vissza
     * - Egyébként: rowCount() (int)
     *
     * @param string $sql
     * @param array  $params  asszociatív (':id'=>1 vagy 'id'=>1) / pozicionális (['a', 'b'] vagy [1=>'a', 2=>'b'])
     * @return array|int
     * @throws Exception
     */
    public function rawQuery(string $sql, array $params = []): array|int
    {
        $pdo  = $this->ensurePdo();
        $stmt = $pdo->prepare($sql);

        // 0-tól indexelt lista -> 1-based pozíció; egyébként a kulcs már 1-based
        $isList = $params === array_values($params);

        foreach ($params as $key => $value) {
            if (is_int($key)) {
                $stmt->bindValue($isList ? $key + 1 : $key, $value);
            } else {
                $paramName = str_starts_with($key, ':') ? $key : ':' . $key;
                $stmt->bindValue($paramName, $value);
            }
        }

        $stmt->execute();

        // Van eredményhalmaz? (megbízhatóbb, mint az SQL első szavának vizsgálata)
        if ($stmt->columnCount() > 0) {
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
