<?php
require '../vendor/autoload.php';

use HunNomad\Database\Database;

$db = new Database('localhost','test','root','pass','3306','mysql');

$id = $db->insert('users', ['name'=>'John']);
$rows = $db->select('users');
print_r($rows);
