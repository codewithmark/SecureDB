<?php

class SecureDB
{
    private static ?SecureDB $instance = null;
    private ?PDO $conn = null;

    private function __construct(array $config)
    {
        $host = $config['host'] ?? 'localhost';
        $user = $config['user'] ?? 'root';
        $pass = $config['pass'] ?? '';
        $dbname = $config['database'] ?? '';

        if (!$dbname) {
            throw new Exception("Database name not specified.");
        }

        $dsn = "mysql:host=$host;dbname=$dbname;charset=utf8mb4";

        try {
            $this->conn = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_PERSISTENT => false
            ]);
        } catch (PDOException $e) {
            throw new Exception("DB Connection failed: " . $e->getMessage());
        }
    }

    public static function getInstance(array $config = []): self
    {
        if (self::$instance === null) {
            self::$instance = new self($config);
        }

        return self::$instance;
    }

    public function select(string $query, array $params = []): array
    {
        $stmt = $this->prepareAndExecute($query, $params);
        return $stmt->fetchAll();
    }

    public function insert(string $table, array $data): int
    {
        $columns = implode(', ', array_keys($data));
        $placeholders = ':' . implode(', :', array_keys($data));

        $sql = "INSERT INTO `$table` ($columns) VALUES ($placeholders)";
        $this->prepareAndExecute($sql, $data);

        return (int) $this->conn->lastInsertId();
    }

    public function insertMultiple(string $table, array $rows, int $batchSize = 1000): int
    {
        if (empty($rows)) {
            throw new Exception("No rows provided for bulk insert.");
        }

        $totalInserted = 0;
        $columns = array_keys($rows[0]);

        try {
            $this->conn->beginTransaction();

            foreach (array_chunk($rows, $batchSize) as $batch) {
                $placeholders = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
                $allPlaceholders = implode(', ', array_fill(0, count($batch), $placeholders));
                $columnList = implode(', ', array_map(fn($col) => "`$col`", $columns));

                $sql = "INSERT INTO `$table` ($columnList) VALUES $allPlaceholders";

                $values = [];
                foreach ($batch as $row) {
                    if (array_keys($row) !== $columns) {
                        throw new Exception("All rows must have the same keys in the same order.");
                    }
                    foreach ($row as $value) {
                        $values[] = $value;
                    }
                }

                $stmt = $this->prepareAndExecute($sql, $values);
                $totalInserted += $stmt->rowCount();
            }

            $this->conn->commit();
        } catch (Exception $e) {
            $this->conn->rollBack();
            throw new Exception("Bulk insert failed: " . $e->getMessage());
        }

        return $totalInserted;
    }

    public function update(string $table, array $data, array $where): int
    {
        $set = implode(', ', array_map(fn($k) => "`$k` = :$k", array_keys($data)));
        $cond = implode(' AND ', array_map(fn($k) => "`$k` = :where_$k", array_keys($where)));

        $params = $data + array_combine(
            array_map(fn($k) => "where_$k", array_keys($where)),
            array_values($where)
        );

        $sql = "UPDATE `$table` SET $set WHERE $cond";
        $stmt = $this->prepareAndExecute($sql, $params);

        return $stmt->rowCount();
    }

    public function delete(string $table, array $where): int
    {
        $cond = implode(' AND ', array_map(fn($k) => "`$k` = :$k", array_keys($where)));
        $sql = "DELETE FROM `$table` WHERE $cond";
        $stmt = $this->prepareAndExecute($sql, $where);

        return $stmt->rowCount();
    }

    public function query(string $sql, array $params = []): bool
    {
        $this->prepareAndExecute($sql, $params);
        return true;
    }

    public function escape(string $value): string
    {
        return $this->conn->quote($value);
    }

    public function getConnection(): PDO
    {
        return $this->conn;
    }

    private function prepareAndExecute(string $sql, array $params): PDOStatement
    {
        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            throw new Exception("SQL Error: " . $e->getMessage());
        }
    }

    public function __destruct()
    {
        $this->conn = null;
    }
}

?>
