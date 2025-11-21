# HunNomad / classDatabase

## Overview
classDatabase is a lightweight, framework‑agnostic PHP 8+ multi‑driver database wrapper supporting:
- **PDO drivers** (MySQL, PostgreSQL, SQL Server, Oracle)
- **MongoDB**
- **Redis** (native functions)

Includes:
- Unified **CRUD** methods
- **rawQuery()** for direct SQL execution
- Full **transaction support**
- Native connection access (`getConnection()`)

## Features
- 🚀 Multi‑driver support  
- 🔧 Simple CRUD (`insert`, `select`, `update`, `delete`)  
- 🧪 rawQuery() automatic SELECT/non‑SELECT detection  
- 🔒 Transactions: `begin()`, `commit()`, `rollback()`  
- 🧩 100% framework‑independent  
- 🗄 Works with legacy code via `getConnection()`  

## Installation (Composer)
If using Packagist:
```
composer require hunnomad/classdatabase
```

For path‑based local development:
```
{
  "repositories": [
    { "type": "path", "url": "../hunnomad-classdatabase" }
  ]
}
```
Then:
```
composer require hunnomad/classdatabase:dev-main
```

## Usage Examples

### 1. MySQL (PDO)
```php
use HunNomad\Database\Database;

$db = new Database('localhost','example','root','pass','3306','mysql');
$pdo = $db->getConnection(); // native PDO
```

### INSERT
```php
$id = $db->insert('users', [
  'name' => 'John Doe',
  'email'=> 'john@example.com'
]);
```

### SELECT
```php
$rows = $db->select('users', ['status'=>1], [
  'order'=>'id DESC',
  'limit'=>20
]);
```

### UPDATE
```php
$db->update('users', ['status'=>0], ['id'=>5]);
```

### DELETE
```php
$db->delete('users', ['id'=>5]);
```

---

## 2. rawQuery() Example
```php
$rows = $db->rawQuery(
  "SELECT * FROM users WHERE email = :email",
  ['email' => 'john@example.com']
);
```

Write operations:
```php
$affected = $db->rawQuery(
  "UPDATE users SET last_login = NOW() WHERE id=:id",
  ['id'=>5]
);
```

---

## 3. Transactions
```php
$db->begin();
try {
    $db->insert('logs', ['msg'=>'Test']);
    $db->update('users', ['active'=>1], ['id'=>10]);
    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
}
```

---

## 4. MongoDB Example
```php
$db = new Database('localhost','mydb','user','pass','27017','mongodb');

$id = $db->insert('users', ['name'=>'Sarah']);
$docs = $db->select('users', ['name'=>'Sarah']);
$db->update('users', ['active'=>true], ['name'=>'Sarah']);
$db->delete('users', ['active'=>false]);
```

---

## 5. Redis Example
```php
$db = new Database('localhost','', '', 'pass','6379','redis');
$redis = $db->getConnection();

$redis->set('foo','bar');
echo $redis->get('foo');
```

*Note: CRUD is not provided for Redis, use native Redis methods.*

---

## Directory Structure
```
classdatabase/
├─ src/Database.php
├─ examples/
│  └─ example.php
├─ README.md
├─ CHANGELOG.md
├─ composer.json
└─ LICENSE
```

---

## License
MIT License  
© 2025 HunNomad (Zsolt Kállai)
