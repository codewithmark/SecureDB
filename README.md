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

**Option 1: Manual**

- Download `SecureDB.php` from `/src`
- Require it in your PHP code:

```php
require_once 'src/SecureDB.php';
```

**Option 2: Composer**

```bash
composer require yourname/secure-db
```

Then autoload with:

```php
require 'vendor/autoload.php';
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
$id = $db->insert('users', [
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
```

### 🔹 Update

```php
$db->update( $table_name, array $update_data, array $where);

$db->update('users', ['name' => 'Updated'], ['id' => 5]);
```

### 🔹 Delete

```php
$db->delete($table_name, array $where);

$db->delete('users', ['id' => 5]);
```

### 🔹 Raw Query

```php
$db->query("UPDATE settings SET value = :v WHERE name = :n", [
  'v' => 'off',
  'n' => 'maintenance'
]);
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
