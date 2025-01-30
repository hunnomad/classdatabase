# classDatabase

A universal MySQL database connection class.

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
