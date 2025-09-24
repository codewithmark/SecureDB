<?php

/**
 * Custom database exception class
 */
class SecureDBException extends Exception 
{
    public function __construct(string $message, int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}

class SecureDB
{
    private static ?SecureDB $instance = null;
    private ?PDO $conn = null;
    
    // Fluent interface properties
    private string $fluentTable = '';
    private array $fluentWhere = [];
    private array $fluentData = [];
    private int $fluentBatchSize = 1000;
    private string $fluentOperation = ''; // Track the current operation type
    private array $fluentColumns = []; // For SELECT operations
    private string $fluentOrderBy = ''; // For SELECT operations
    private int $fluentLimit = 0; // For SELECT operations

    private function __construct(array $config)
    {
        $host = $config['host'] ?? 'localhost';
        $user = $config['user'] ?? 'root';
        $pass = $config['pass'] ?? '';
        $dbname = $config['database'] ?? '';

        if (!$dbname) {
            throw new SecureDBException("Database name not specified.");
        }

        $dsn = "mysql:host=$host;dbname=$dbname;charset=utf8mb4";

        try {
            $this->conn = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_PERSISTENT => false
            ]);
        } catch (PDOException $e) {
            throw new SecureDBException("DB Connection failed: " . $e->getMessage(), $e->getCode(), $e);
        }
    }

    public static function getInstance(array $config = []): self
    {
        if (self::$instance === null) {
            if (empty($config)) {
                throw new SecureDBException("Database configuration must be provided on first getInstance() call.");
            }
            self::$instance = new self($config);
        }

        return self::$instance;
    }

    public function select(string $query, array $params = []): array
    {
        $stmt = $this->prepareAndExecute($query, $params);
        return $stmt->fetchAll();
    }

    /**
     * Start fluent SELECT operation
     */
    public function from(string $table, array $columns = ['*']): self
    {
        $this->reset();
        $this->fluentTable = $this->validateTableName($table);
        $this->fluentOperation = 'select';
        $this->fluentColumns = $columns;
        return $this;
    }

    public function insert(string $table, array $data = []): int|self
    {
        // If data is provided, execute immediately (backward compatibility)
        if (!empty($data)) {
            $table = $this->validateTableName($table);
            $columns = implode(', ', array_keys($data));
            $placeholders = ':' . implode(', :', array_keys($data));

            $sql = "INSERT INTO `$table` ($columns) VALUES ($placeholders)";
            $this->prepareAndExecute($sql, $data);

            return (int) $this->conn->lastInsertId();
        }
        
        // Fluent interface mode
        $this->reset();
        $this->fluentTable = $this->validateTableName($table);
        $this->fluentOperation = 'insert';
        return $this;
    }

    public function insertMultiple(string $table, array $rows = [], int $batchSize = 1000): int|self
    {
        // If rows are provided, execute immediately (backward compatibility)
        if (!empty($rows)) {
            return $this->executeInsertMultiple($this->validateTableName($table), $rows, $batchSize);
        }
        
        // Fluent interface mode
        $this->reset();
        $this->fluentTable = $this->validateTableName($table);
        $this->fluentOperation = 'insertMultiple';
        $this->fluentBatchSize = $batchSize;
        return $this;
    }

    /**
     * Execute bulk insert operation
     */
    private function executeInsertMultiple(string $table, array $rows, int $batchSize): int
    {
        if (empty($rows)) {
            throw new SecureDBException("No rows provided for bulk insert.");
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
                        throw new SecureDBException("All rows must have the same keys in the same order.");
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
            throw new SecureDBException("Bulk insert failed: " . $e->getMessage(), 0, $e);
        }

        return $totalInserted;
    }

    public function update(string $table, array $data = [], array $where = []): int|self
    {
        // If data and where are provided, execute immediately (backward compatibility)
        if (!empty($data) && !empty($where)) {
            $table = $this->validateTableName($table);
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
        $this->fluentTable = $this->validateTableName($table);
        $this->fluentOperation = 'update';
        return $this;
    }

    public function delete(string $table, array $where = []): int|self
    {
        // If where is provided, execute immediately (backward compatibility)
        if (!empty($where)) {
            $table = $this->validateTableName($table);
            $cond = implode(' AND ', array_map(fn($k) => "`$k` = :$k", array_keys($where)));
            $sql = "DELETE FROM `$table` WHERE $cond";
            $stmt = $this->prepareAndExecute($sql, $where);

            return $stmt->rowCount();
        }
        
        // Fluent interface mode
        $this->reset();
        $this->fluentTable = $this->validateTableName($table);
        $this->fluentOperation = 'delete';
        return $this;
    }

    /**
     * Quick query method - alias for query()
     */
    public function q(string $sql, array $params = []): mixed
    {
        return $this->query($sql, $params);
    }
    
    /**
     * Execute raw SQL query with automatic return type detection
     */
    public function query(string $sql, array $params = []): mixed
    {
        $stmt = $this->prepareAndExecute($sql, $params);
        
        // Determine the query type by examining the SQL statement
        $queryType = $this->detectQueryType($sql);
        
        switch ($queryType) {
            case 'SELECT':
                return $stmt->fetchAll();
                
            case 'INSERT':
                return (int) $this->conn->lastInsertId();
                
            case 'UPDATE':
            case 'DELETE':
                return $stmt->rowCount();
                
            default:
                // For other statements (CREATE, ALTER, DROP, etc.)
                return true;
        }
    }
    
    /**
     * Detect the type of SQL query
     */
    private function detectQueryType(string $sql): string
    {
        // Remove leading whitespace and comments
        $sql = trim($sql);
        $sql = preg_replace('/^\/\*.*?\*\/\s*/s', '', $sql); // Remove /* */ comments
        $sql = preg_replace('/^--.*$/m', '', $sql); // Remove -- comments
        $sql = trim($sql);
        
        // Get the first word (the SQL command)
        $firstWord = strtoupper(explode(' ', $sql)[0]);
        
        return $firstWord;
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
            throw new SecureDBException("No table specified. Use insertMultiple('table_name') first.");
        }
        
        if (empty($rows)) {
            throw new SecureDBException("No rows data provided for bulk insert.");
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
            throw new SecureDBException("No table specified. Use insert('table_name') first.");
        }
        
        if (empty($data)) {
            throw new SecureDBException("No data provided for insert.");
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
        
        // Only auto-execute for delete operations
        if (!empty($this->fluentTable) && $this->fluentOperation === 'delete') {
            return $this->executeDelete();
        }
        
        return $this;
    }

    /**
     * Set ORDER BY for fluent SELECT
     */
    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        if ($this->fluentOperation !== 'select') {
            throw new SecureDBException("orderBy() can only be used with SELECT operations.");
        }
        
        $direction = strtoupper($direction);
        if (!in_array($direction, ['ASC', 'DESC'])) {
            throw new SecureDBException("Invalid order direction. Use ASC or DESC.");
        }
        
        $column = $this->validateColumnName($column);
        $this->fluentOrderBy = "`$column` $direction";
        return $this;
    }

    /**
     * Set LIMIT for fluent SELECT
     */
    public function limit(int $count): self
    {
        if ($this->fluentOperation !== 'select') {
            throw new SecureDBException("limit() can only be used with SELECT operations.");
        }
        
        $this->fluentLimit = $count;
        return $this;
    }

    /**
     * Execute fluent SELECT and return results
     */
    public function get(): array
    {
        if (empty($this->fluentTable)) {
            throw new SecureDBException("No table specified. Use from('table_name') first.");
        }
        
        if ($this->fluentOperation !== 'select') {
            throw new SecureDBException("get() can only be used with SELECT operations. Use from('table') first.");
        }

        // Build SELECT query
        $columns = empty($this->fluentColumns) ? '*' : implode(', ', array_map(fn($col) => $col === '*' ? '*' : "`$col`", $this->fluentColumns));
        $sql = "SELECT $columns FROM `{$this->fluentTable}`";
        
        $params = [];
        if (!empty($this->fluentWhere)) {
            $cond = implode(' AND ', array_map(fn($k) => "`$k` = :$k", array_keys($this->fluentWhere)));
            $sql .= " WHERE $cond";
            $params = $this->fluentWhere;
        }
        
        if (!empty($this->fluentOrderBy)) {
            $sql .= " ORDER BY {$this->fluentOrderBy}";
        }
        
        if ($this->fluentLimit > 0) {
            $sql .= " LIMIT {$this->fluentLimit}";
        }

        $stmt = $this->prepareAndExecute($sql, $params);
        $result = $stmt->fetchAll();
        
        $this->reset(); // Clear fluent state after execution
        return $result;
    }

    /**
     * Execute UPDATE with stored table, where conditions, and provided data
     */
    public function change(array $data): int
    {
        if (empty($this->fluentTable)) {
            throw new SecureDBException("No table specified. Use update('table_name') first.");
        }
        
        if (empty($this->fluentWhere)) {
            throw new SecureDBException("No WHERE conditions specified. Use where(['column' => 'value']) first.");
        }
        
        if (empty($data)) {
            throw new SecureDBException("No data provided for update.");
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
            throw new SecureDBException("No table specified. Use delete('table_name') first.");
        }
        
        if (empty($this->fluentWhere)) {
            throw new SecureDBException("No WHERE conditions specified. Use where(['column' => 'value']) first.");
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
        switch ($this->fluentOperation) {
            case 'delete':
                return $this->executeDelete();
                
            case 'update':
                throw new SecureDBException("Cannot execute UPDATE without data. Use change(['column' => 'value']) instead.");
                
            case 'insert':
                throw new SecureDBException("Cannot execute INSERT without data. Use row(['column' => 'value']) instead.");
                
            case 'insertMultiple':
                throw new SecureDBException("Cannot execute INSERT MULTIPLE without data. Use rows([...]) instead.");
                
            default:
                throw new SecureDBException("No operation specified or operation not executable via execute().");
        }
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
        $this->fluentOperation = '';
        $this->fluentColumns = [];
        $this->fluentOrderBy = '';
        $this->fluentLimit = 0;
    }

    public function escape(string $value): string
    {
        return $this->conn->quote($value);
    }

    public function getConnection(): PDO
    {
        return $this->conn;
    }

    /**
     * Check if database connection is still valid
     */
    private function validateConnection(): void
    {
        if ($this->conn === null) {
            throw new SecureDBException("Database connection is null. Instance may have been destroyed.");
        }
        
        try {
            // Ping the database to check if connection is alive
            $this->conn->query('SELECT 1');
        } catch (PDOException $e) {
            throw new SecureDBException("Database connection is no longer valid: " . $e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Validate and sanitize table name to prevent SQL injection
     */
    private function validateTableName(string $table): string
    {
        // Remove backticks if present
        $table = trim($table, '`');
        
        // Check for valid table name pattern (letters, numbers, underscores, dots for database.table)
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z_][a-zA-Z0-9_]*)?$/', $table)) {
            throw new SecureDBException("Invalid table name: '$table'. Table names can only contain letters, numbers, and underscores.");
        }
        
        return $table;
    }

    /**
     * Validate and sanitize column name to prevent SQL injection
     */
    private function validateColumnName(string $column): string
    {
        // Remove backticks if present
        $column = trim($column, '`');
        
        // Check for valid column name pattern
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $column)) {
            throw new SecureDBException("Invalid column name: '$column'. Column names can only contain letters, numbers, and underscores.");
        }
        
        return $column;
    }

    private function prepareAndExecute(string $sql, array $params): PDOStatement
    {
        $this->validateConnection();
        
        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            throw new SecureDBException("SQL Error: " . $e->getMessage(), $e->getCode(), $e);
        }
    }

    public function __destruct()
    {
        $this->conn = null;
    }
}

?>
