<?php
require_once '../src/SecureDB.php';

$db = SecureDB::getInstance([
    'host' => 'localhost',
    'user' => 'root',
    'pass' => '',
    'database' => 'your_database'
]);

echo "Insert:
";
$id = $db->insert('users', [
    'name' => 'Demo User',
    'email' => 'demo@example.com'
]);
echo "Inserted ID: $id
";

echo "Select:
";
$users = $db->select("SELECT * FROM users LIMIT 5");
print_r($users);

echo "Update:
";
$db->update('users', ['name' => 'Updated Demo'], ['id' => $id]);

echo "Delete:
";
$db->delete('users', ['id' => $id]);