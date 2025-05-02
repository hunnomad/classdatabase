# classDatabase

![PHP Version](https://img.shields.io/badge/php-8.1%2B-blue) ![License](https://img.shields.io/badge/license-MIT-green) ![Stable](https://img.shields.io/badge/version-1.1-stable)

A universal multi-driver database connection class with support for MySQL, PostgreSQL, MSSQL, Oracle, Redis, and MongoDB.

#### Version

1.1 (upgraded from 1.0.1)

#### Features

✅ Easy to use
✅ Supports MySQL, PostgreSQL, MSSQL, Oracle, Redis, and MongoDB
✅ PSR-4 autoloading via Composer
✅ Error logging to file and system log
✅ Intelligent error context (timestamp, file, line, driver, function)
✅ Fully backward-compatible with existing MySQL-only usage

#### Supported Drivers

- MySQL (PDO)
- PostgreSQL (PDO)
- MSSQL / SQLSRV (PDO)
- Oracle (PDO)
- Redis (phpredis extension)
- MongoDB (mongodb/mongodb Composer package)

### Installation

`composer require hunnomad/classdatabase`

## Usage Examples

### MySQL (default):

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use HunNomad\Database\Database;

$db = new Database("localhost", "mydb", "user", "password", "3306");
$pdo = $db->getConnection();

$sql = "SELECT * FROM users WHERE email = :email";
$query = $pdo->prepare($sql);
$query->bindParam(':email', $email, PDO::PARAM_STR);
$query->execute();
$result = $query->fetch(PDO::FETCH_OBJ);
?>
```

### PostgreSQL

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use HunNomad\Database\Database;

$db = new Database("localhost", "mydb", "user", "password", "5432", "pgsql");
$pdo = $db->getConnection();

$sql = "SELECT * FROM users WHERE id = :id";
$query = $pdo->prepare($sql);
$query->bindParam(':id', $id, PDO::PARAM_INT);
$query->execute();
$result = $query->fetch(PDO::FETCH_ASSOC);
?>
 ```

### MSSQL / SQLSRV

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use HunNomad\Database\Database;

$db = new Database("localhost", "mydb", "sa", "password", "1433", "sqlsrv");
$pdo = $db->getConnection();

$sql = "SELECT * FROM customers";
$stmt = $pdo->query($sql);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
```

#### Oracle

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use HunNomad\Database\Database;

$db = new Database("localhost", "XE", "oracleuser", "password", "1521", "oracle");
$pdo = $db->getConnection();

$sql = "SELECT * FROM employees";
$stmt = $pdo->query($sql);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
```

### Redis

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use HunNomad\Database\Database;

$db = new Database("127.0.0.1", "", "", "mysecret", "6379", "redis");
$redis = $db->getConnection();

$redis->set("example_key", "Hello, Redis!");
echo $redis->get("example_key");
?>
```

### MongoDB

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use HunNomad\Database\Database;

$db = new Database("localhost", "mydb", "user", "password", "27017", "mongodb");
$mongo = $db->getConnection();

$collection = $mongo->selectCollection("mydb", "users");
$result = $collection->findOne(["username" => "john"]);
print_r($result);
?>
```

### Optional Methods

Get current driver version:

`echo $db->getDriver();` // Output: mysql, pgsql, redis, etc.

#### Error LoggingError Logging

- All connection errors are logged to:
- error_log.txt in the class directory
- PHP's system error log via error_log()

#### Each error includes:

- Timestamp
- File name and line number
- Function name
- Driver type
- Exception message

#### Requirements

- PHP 8.1+
- PDO extensions for selected drivers (pdo_mysql, pdo_pgsql, etc.)
- phpredis extension for Redis
- mongodb/mongodb Composer package for MongoDB

> ⚠️ **Ensure required PHP extensions and drivers are installed and enabled in your php.ini or Docker container.**