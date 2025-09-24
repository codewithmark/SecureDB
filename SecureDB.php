<?php

class SecureDB
{
    private static ?SecureDB $instance = null;
    private ?PDO $conn = null;
    
    // Fluent interface properties
    private string $fluentTable = '';
    private array $fluentWhere = [];
    private array $fluentData = [];
    private int $fluentBatchSize = 1000;

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

    public function insert(string $table, array $data = []): int|self
    {
        // If data is provided, execute immediately (backward compatibility)
        if (!empty($data)) {
            $columns = implode(', ', array_keys($data));
            $placeholders = ':' . implode(', :', array_keys($data));

            $sql = "INSERT INTO `$table` ($columns) VALUES ($placeholders)";
            $this->prepareAndExecute($sql, $data);

            return (int) $this->conn->lastInsertId();
        }
        
        // Fluent interface mode
        $this->reset();
        $this->fluentTable = $table;
        return $this;
    }

    public function insertMultiple(string $table, array $rows = [], int $batchSize = 1000): int|self
    {
        // If rows are provided, execute immediately (backward compatibility)
        if (!empty($rows)) {
            return $this->executeInsertMultiple($table, $rows, $batchSize);
        }
        
        // Fluent interface mode
        $this->reset();
        $this->fluentTable = $table;
        $this->fluentBatchSize = $batchSize;
        return $this;
    }

    /**
     * Execute bulk insert operation
     */
    private function executeInsertMultiple(string $table, array $rows, int $batchSize): int
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

    public function update(string $table, array $data = [], array $where = []): int|self
    {
        // If data and where are provided, execute immediately (backward compatibility)
        if (!empty($data) && !empty($where)) {
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
        
        // Fluent interface mode
        $this->reset();
        $this->fluentTable = $table;
        return $this;
    }

    public function delete(string $table, array $where = []): int|self
    {
        // If where is provided, execute immediately (backward compatibility)
        if (!empty($where)) {
            $cond = implode(' AND ', array_map(fn($k) => "`$k` = :$k", array_keys($where)));
            $sql = "DELETE FROM `$table` WHERE $cond";
            $stmt = $this->prepareAndExecute($sql, $where);

            return $stmt->rowCount();
        }
        
        // Fluent interface mode
        $this->reset();
        $this->fluentTable = $table;
        return $this;
    }

    public function query(string $sql, array $params = []): bool
    {
        $this->prepareAndExecute($sql, $params);
        return true;
    }

    /**
     * Set batch size for fluent insertMultiple
     */
    public function batch(int $size): self
    {
        $this->fluentBatchSize = $size;
        return $this;
    }

    /**
     * Execute bulk INSERT with stored table and provided rows data
     */
    public function rows(array $rows): int
    {
        if (empty($this->fluentTable)) {
            throw new Exception("No table specified. Use insertMultiple('table_name') first.");
        }
        
        if (empty($rows)) {
            throw new Exception("No rows data provided for bulk insert.");
        }

        $totalInserted = $this->executeInsertMultiple($this->fluentTable, $rows, $this->fluentBatchSize);
        $this->reset(); // Clear fluent state after execution
        
        return $totalInserted;
    }

    /**
     * Execute INSERT with stored table and provided data
     */
    public function row(array $data): int
    {
        if (empty($this->fluentTable)) {
            throw new Exception("No table specified. Use insert('table_name') first.");
        }
        
        if (empty($data)) {
            throw new Exception("No data provided for insert.");
        }

        $columns = implode(', ', array_keys($data));
        $placeholders = ':' . implode(', :', array_keys($data));

        $sql = "INSERT INTO `{$this->fluentTable}` ($columns) VALUES ($placeholders)";
        $this->prepareAndExecute($sql, $data);
        
        $lastId = (int) $this->conn->lastInsertId();
        $this->reset(); // Clear fluent state after execution
        
        return $lastId;
    }

    /**
     * Set WHERE conditions for fluent interface
     * For delete operations, this auto-executes and returns result
     */
    public function where(array $conditions): self|int
    {
        $this->fluentWhere = $conditions;
        
        // If this is a delete operation (table set but no data), auto-execute
        if (!empty($this->fluentTable) && empty($this->fluentData)) {
            return $this->executeDelete();
        }
        
        return $this;
    }

    /**
     * Execute UPDATE with stored table, where conditions, and provided data
     */
    public function change(array $data): int
    {
        if (empty($this->fluentTable)) {
            throw new Exception("No table specified. Use update('table_name') first.");
        }
        
        if (empty($this->fluentWhere)) {
            throw new Exception("No WHERE conditions specified. Use where(['column' => 'value']) first.");
        }
        
        if (empty($data)) {
            throw new Exception("No data provided for update.");
        }

        $set = implode(', ', array_map(fn($k) => "`$k` = :$k", array_keys($data)));
        $cond = implode(' AND ', array_map(fn($k) => "`$k` = :where_$k", array_keys($this->fluentWhere)));

        $params = $data + array_combine(
            array_map(fn($k) => "where_$k", array_keys($this->fluentWhere)),
            array_values($this->fluentWhere)
        );

        $sql = "UPDATE `{$this->fluentTable}` SET $set WHERE $cond";
        $stmt = $this->prepareAndExecute($sql, $params);
        
        $rowCount = $stmt->rowCount();
        $this->reset(); // Clear fluent state after execution
        
        return $rowCount;
    }

    /**
     * Execute DELETE with stored table and where conditions
     */
    public function executeDelete(): int
    {
        if (empty($this->fluentTable)) {
            throw new Exception("No table specified. Use delete('table_name') first.");
        }
        
        if (empty($this->fluentWhere)) {
            throw new Exception("No WHERE conditions specified. Use where(['column' => 'value']) first.");
        }

        $cond = implode(' AND ', array_map(fn($k) => "`$k` = :$k", array_keys($this->fluentWhere)));
        $sql = "DELETE FROM `{$this->fluentTable}` WHERE $cond";
        $stmt = $this->prepareAndExecute($sql, $this->fluentWhere);
        
        $rowCount = $stmt->rowCount();
        $this->reset(); // Clear fluent state after execution
        
        return $rowCount;
    }

    /**
     * General execute method for fluent operations
     */
    public function execute(): int
    {
        return $this->executeDelete();
    }

    /**
     * Reset fluent interface state
     */
    private function reset(): void
    {
        $this->fluentTable = '';
        $this->fluentWhere = [];
        $this->fluentData = [];
        $this->fluentBatchSize = 1000;
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
