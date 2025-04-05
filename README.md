# classDatabase

![PHP Version](https://img.shields.io/badge/php-%5E8.1-blue)
![PDO Support](https://img.shields.io/badge/pdo-mysql%20%7C%20pgsql-brightgreen)
![License](https://img.shields.io/github/license/hunnomad/classdatabase)
![Composer](https://img.shields.io/packagist/v/hunnomad/classdatabase)
![Build](https://img.shields.io/badge/build-passing-success)

A universal **PDO-based** database connection class with support for **MySQL** and **PostgreSQL**.

## Installation

```sh
composer require hunnomad/classdatabase
```

##### Usage:

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use HunNomad\Database\Database;

$db = new Database("localhost", "mydb", "user", "password", "3306");
$pdo = $db->getConnection();

$sql = "SQL COMMAND";
$query = $pdo->prepare($sql);
$query->bindParam(':param', $xxx, PDO::PARAM_STR);
$query->execute();
$r = $query->fetch(PDO::FETCH_OBJ);
?>
```



# classDatabase

A universal **PDO-based** database connection class with support for **MySQL** and **PostgreSQL**.

## Features

- ✅ Easy to use
- ✅ Supports **MySQL** and **PostgreSQL**
- ✅ PSR-4 autoloading via Composer
- ✅ Error logging to file and system log
- ✅ Fully backward-compatible with existing MySQL-only usage

---

## Installation

```sh
composer require hunnomad/classdatabase
```

##### Usage:

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

### Optional Methods
Get current driver version

```php
echo $db->getDriver(); // Output: mysql or pgsql
```

### Error Logging
All connection errors are logged to:
- error_log.txt in the class directory
- PHP's system error log via error_log()

Errors include timestamp, driver info, and source file/function

### Requirements
- PHP 8.1+
- PDO extension enabled for the selected driver (pdo_mysql or pdo_pgsql)

