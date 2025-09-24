<h1 align="center">🔐 SecureDB</h1>
<p align="center">A clean, fast, secure PDO database class for PHP</p> 
 
---

## 🚀 Features

- ✅ Simple, secure CRUD methods  
- ✅ Prepared statements (goodbye SQL injection)  
- ✅ Batch insert support with automatic transactions  
- ✅ Lightweight – no framework needed  
- ✅ PSR-compatible, autoload-ready  

---

## 🛠 Installation

** Manual**

- Download `SecureDB.php`
- Require it in your PHP code:

```php
require_once 'SecureDB.php';
```
 

---

## ⚙️ Getting Started

```php
$db = SecureDB::getInstance([
  'host' => 'localhost',
  'user' => 'root',
  'pass' => '',
  'database' => 'your_database'
]);
```

---

## 📚 Quick Examples

### 🔹 Select

```php
$users = $db->select("SELECT * FROM users WHERE role = :role", [
  'role' => 'admin'
]);
```

### 🔹 Insert One

```php
//Insert Basic
$db->insert('users', [
  'name' => 'Alice',
  'email' => 'alice@example.com'
]);

//Insert Fluent Interface
$db->insert('users')->row([
    'name' => 'Alice',
    'email' => 'alice@example.com'
]);

```

### 🔹 Insert Many (Batch)

```php
$rows = [
  ['name' => 'Bob', 'email' => 'bob@example.com'],
  ['name' => 'Carol', 'email' => 'carol@example.com'],
];

$db->insertMultiple('users', $rows); // Auto-batched and transactional


//Bulk Insert Fluent Interface

//Basic Usage (Auto-batched at 1000):
$totalInserted = $db->insertMultiple('users')->rows($rows);

// Method 1: Set batch size first
$db->insertMultiple('users')->batch(500)->rows($rows);

// Method 2: Chain batch() after rows() (both work)
$db->insertMultiple('users')->rows($rows)->batch(500);

// Handle large datasets efficiently
$largeDataset = [];
for ($i = 0; $i < 50000; $i++) {
    $largeDataset[] = [
        'name' => 'User ' . $i,
        'email' => "user{$i}@example.com",
        'created_at' => date('Y-m-d H:i:s')
    ];
}

// Processes in chunks of 2000 for optimal performance
$total = $db->insertMultiple('users')->batch(2000)->rows($largeDataset);


```

### 🔹 Update

```php
// Update Basic
$db->update( $table_name, array $update_data, array $where);
$db->update('users', ['name' => 'Updated'], ['id' => 5]);


// Update with fluent interface >  Multiple Column Updates:
$db->update('users')->where(['id' => 5])->change([
    'name' => 'Updated Name',
    'email' => 'newemail@gmail.com',
    'status' => 'active',
    'last_login' => '2025-09-23 10:30:00'
]);


```

### 🔹 Delete

```php
$db->delete($table_name, array $where);

$db->delete('users', ['id' => 5]);

// Single Condition Delete:
$db->delete('users')->where(['id' => 5]);

Multiple Conditions Delete:
$db->delete('users')->where([
    'id' => 5,
    'status' => 'inactive',
    'last_login' => null
]);

How It Works:
The where() method accepts an array of conditions
Creates WHERE clause: id = :id AND status = :status AND last_login = :last_login
All conditions are joined with AND operators
Uses PDO prepared statements for security


```

### 🔹 Raw Query

```php

//SELECT  
$db->query('SELECT * FROM users WHERE status = :status', ['status' => 'active']);
// Returns: Array of result rows

//INSERT
$db->query('INSERT INTO users (name, email) VALUES (:name, :email)', [
    'name' => 'John Doe',
    'email' => 'john@example.com'
]);
// Returns: Last insert ID (int)

//UPDATE
 $db->query('UPDATE users SET status = :status WHERE id = :id', [
    'status' => 'inactive',
    'id' => 5
]);
// Returns: Number of affected rows (int)

//DELETE
$db->query('DELETE FROM users WHERE last_login < :date', [
    'date' => '2024-01-01'
]);
// Returns: Number of deleted rows (int)

//DDL Statements (CREATE, ALTER, DROP, etc.)
$db->query('CREATE INDEX idx_user_email ON users(email)');
// Returns: true (bool)


//Complex Query Support
// JOIN queries with parameters
$data = $db->query('
    SELECT u.name, u.email, p.title as role 
    FROM users u 
    LEFT JOIN profiles p ON u.id = p.user_id 
    WHERE u.created_at > :date AND u.status = :status
', ['date' => '2025-01-01', 'status' => 'active']); 


```

---

## 🧪 Requirements

- PHP 7.4+
- MySQL or compatible
- PDO extension enabled

---

## 📁 File Structure

```
secure-db/
├── src/
│   └── SecureDB.php
├── examples/
│   └── demo.php
├── composer.json
└── README.md
```

---

## 📝 License

MIT – free to use, modify, and distribute.

---

<p align="center"><b>Made with 💙 for developers who love simplicity.</b></p>
