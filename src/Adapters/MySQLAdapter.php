<?php

declare(strict_types=1);

namespace Mygra\Adapters;

use Mygra\Schema\TableSchema;
use Mygra\Schema\ColumnSchema;

/**
 * MySQL Database Adapter
 * 
 * Full-featured MySQL/MariaDB adapter with schema introspection,
 * transaction support, and streaming capabilities.
 */
class MySQLAdapter extends AbstractAdapter
{
  protected string $driver = 'mysql';

  // =========================================================================
  // Connection Management
  // =========================================================================

  public function connect(array $config): void
  {
    $this->config = $config;

    $host = $config['host'] ?? 'localhost';
    $port = $config['port'] ?? 3306;
    $database = $config['database'] ?? '';
    $charset = $config['charset'] ?? 'utf8mb4';

    $dsn = "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";

    $options = [
      \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
      \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
      \PDO::ATTR_EMULATE_PREPARES => false,
      \PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
    ];

    // Merge custom options
    if (isset($config['options']) && is_array($config['options'])) {
      $options = array_merge($options, $config['options']);
    }

    $this->pdo = new \PDO(
      $dsn,
      $config['user'] ?? 'root',
      $config['password'] ?? '',
      $options
    );

    // Set SQL mode (relaxed to support legacy zero dates)
    $this->pdo->exec("SET sql_mode='STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO'");
  }

  /**
   * Enable unbuffered queries for streaming large datasets.
   */
  public function enableUnbufferedQueries(): void
  {
    $this->pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
  }

  /**
   * Disable unbuffered queries (return to default behavior).
   */
  public function disableUnbufferedQueries(): void
  {
    $this->pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
  }

  public function streamQuery(string $sql, array $params = []): \Generator
  {
    // Use unbuffered query for streaming
    $this->enableUnbufferedQueries();

    try {
      $stmt = $this->getPdo()->prepare($sql);
      $stmt->execute($params);

      while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
        yield $row;
      }
    } finally {
      $this->disableUnbufferedQueries();
    }
  }

  // =========================================================================
  // Schema Introspection
  // =========================================================================

  public function getTables(): array
  {
    $result = $this->query(
      "SELECT TABLE_NAME 
             FROM INFORMATION_SCHEMA.TABLES 
             WHERE TABLE_SCHEMA = DATABASE() 
             AND TABLE_TYPE = 'BASE TABLE'
             ORDER BY TABLE_NAME"
    );

    return array_column($result, 'TABLE_NAME');
  }

  public function getTableSchema(string $table): TableSchema
  {
    $columnsData = $this->getColumns($table);
    return $this->buildTableSchema($table, $columnsData);
  }

  public function getColumns(string $table): array
  {
    return $this->query(
      "SELECT 
                COLUMN_NAME,
                DATA_TYPE,
                COLUMN_TYPE,
                IS_NULLABLE,
                COLUMN_DEFAULT,
                CHARACTER_MAXIMUM_LENGTH,
                NUMERIC_PRECISION,
                NUMERIC_SCALE,
                COLUMN_KEY,
                EXTRA,
                COLUMN_COMMENT
             FROM INFORMATION_SCHEMA.COLUMNS 
             WHERE TABLE_SCHEMA = DATABASE() 
             AND TABLE_NAME = ?
             ORDER BY ORDINAL_POSITION",
      [$table]
    );
  }

  public function getPrimaryKey(string $table): string|array|null
  {
    $result = $this->query(
      "SELECT COLUMN_NAME
             FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = ?
             AND CONSTRAINT_NAME = 'PRIMARY'
             ORDER BY ORDINAL_POSITION",
      [$table]
    );

    if (empty($result)) {
      return null;
    }

    $columns = array_column($result, 'COLUMN_NAME');
    return count($columns) === 1 ? $columns[0] : $columns;
  }

  public function getForeignKeys(string $table): array
  {
    $result = $this->query(
      "SELECT 
                CONSTRAINT_NAME,
                COLUMN_NAME,
                REFERENCED_TABLE_NAME,
                REFERENCED_COLUMN_NAME
             FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = ?
             AND REFERENCED_TABLE_NAME IS NOT NULL",
      [$table]
    );

    $foreignKeys = [];
    foreach ($result as $row) {
      $foreignKeys[] = [
        'name' => $row['CONSTRAINT_NAME'],
        'column' => $row['COLUMN_NAME'],
        'referencedTable' => $row['REFERENCED_TABLE_NAME'],
        'referencedColumn' => $row['REFERENCED_COLUMN_NAME'],
      ];
    }

    return $foreignKeys;
  }

  public function getIndexes(string $table): array
  {
    $result = $this->query(
      "SELECT 
                INDEX_NAME,
                COLUMN_NAME,
                NON_UNIQUE,
                SEQ_IN_INDEX
             FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = ?
             ORDER BY INDEX_NAME, SEQ_IN_INDEX",
      [$table]
    );

    // Group by index name
    $indexes = [];
    foreach ($result as $row) {
      $indexName = $row['INDEX_NAME'];
      if (!isset($indexes[$indexName])) {
        $indexes[$indexName] = [
          'name' => $indexName,
          'columns' => [],
          'unique' => !$row['NON_UNIQUE'],
          'primary' => $indexName === 'PRIMARY',
        ];
      }
      $indexes[$indexName]['columns'][] = $row['COLUMN_NAME'];
    }

    return array_values($indexes);
  }

  // =========================================================================
  // Batch Operations (Optimized for MySQL)
  // =========================================================================

  public function batchInsert(string $table, array $columns, array $rows): int
  {
    if (empty($rows)) {
      return 0;
    }

    $quotedTable = $this->quoteIdentifier($table);
    $quotedColumns = array_map([$this, 'quoteIdentifier'], $columns);
    $columnList = implode(', ', $quotedColumns);

    // Use multi-row INSERT for efficiency
    $placeholderRow = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
    $placeholders = implode(', ', array_fill(0, count($rows), $placeholderRow));

    $sql = "INSERT INTO {$quotedTable} ({$columnList}) VALUES {$placeholders}";

    // Flatten the rows array for binding
    $values = [];
    foreach ($rows as $row) {
      foreach ($columns as $col) {
        $values[] = $row[$col] ?? null;
      }
    }

    return $this->execute($sql, $values);
  }

  /**
   * Batch insert with ON DUPLICATE KEY UPDATE support.
   */
  public function batchUpsert(string $table, array $columns, array $rows, array $updateColumns = []): int
  {
    if (empty($rows)) {
      return 0;
    }

    $quotedTable = $this->quoteIdentifier($table);
    $quotedColumns = array_map([$this, 'quoteIdentifier'], $columns);
    $columnList = implode(', ', $quotedColumns);

    $placeholderRow = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
    $placeholders = implode(', ', array_fill(0, count($rows), $placeholderRow));

    $sql = "INSERT INTO {$quotedTable} ({$columnList}) VALUES {$placeholders}";

    // Add ON DUPLICATE KEY UPDATE clause
    if (!empty($updateColumns)) {
      $updates = [];
      foreach ($updateColumns as $col) {
        $quoted = $this->quoteIdentifier($col);
        $updates[] = "{$quoted} = VALUES({$quoted})";
      }
      $sql .= " ON DUPLICATE KEY UPDATE " . implode(', ', $updates);
    }

    $values = [];
    foreach ($rows as $row) {
      foreach ($columns as $col) {
        $values[] = $row[$col] ?? null;
      }
    }

    return $this->execute($sql, $values);
  }

  // =========================================================================
  // Utility Methods
  // =========================================================================

  public function quoteIdentifier(string $identifier): string
  {
    // MySQL uses backticks for identifier quoting
    $identifier = str_replace('`', '``', $identifier);
    return '`' . $identifier . '`';
  }

  public function getPlaceholderStyle(): string
  {
    return 'positional'; // MySQL uses ? placeholders
  }

  /**
   * Get the current database name.
   */
  public function getCurrentDatabase(): string
  {
    $result = $this->query("SELECT DATABASE() as db");
    return $result[0]['db'] ?? '';
  }

  /**
   * Get MySQL server version.
   */
  public function getServerVersion(): string
  {
    return $this->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION);
  }

  // =========================================================================
  // Helper Methods
  // =========================================================================

  protected function buildColumnSchema(array $columnData): ColumnSchema
  {
    $extra = $columnData['EXTRA'] ?? '';

    return new ColumnSchema(
      name: $columnData['COLUMN_NAME'],
      type: $this->normalizeType($columnData['DATA_TYPE']),
      nativeType: $columnData['COLUMN_TYPE'],
      nullable: ($columnData['IS_NULLABLE'] ?? 'YES') === 'YES',
      default: $columnData['COLUMN_DEFAULT'],
      length: isset($columnData['CHARACTER_MAXIMUM_LENGTH'])
        ? (int) $columnData['CHARACTER_MAXIMUM_LENGTH']
        : null,
      precision: isset($columnData['NUMERIC_PRECISION'])
        ? (int) $columnData['NUMERIC_PRECISION']
        : null,
      scale: isset($columnData['NUMERIC_SCALE'])
        ? (int) $columnData['NUMERIC_SCALE']
        : null,
      isPrimaryKey: ($columnData['COLUMN_KEY'] ?? '') === 'PRI',
      isAutoIncrement: str_contains(strtolower($extra), 'auto_increment'),
      isUnique: ($columnData['COLUMN_KEY'] ?? '') === 'UNI',
      comment: $columnData['COLUMN_COMMENT'] ?? null,
      extra: ['mysql_extra' => $extra]
    );
  }

  /**
   * Normalize MySQL data type to a standard type.
   */
  private function normalizeType(string $mysqlType): string
  {
    $typeMap = [
      'int' => 'integer',
      'bigint' => 'bigint',
      'smallint' => 'smallint',
      'tinyint' => 'tinyint',
      'mediumint' => 'integer',
      'float' => 'float',
      'double' => 'double',
      'decimal' => 'decimal',
      'numeric' => 'decimal',
      'char' => 'char',
      'varchar' => 'varchar',
      'text' => 'text',
      'tinytext' => 'text',
      'mediumtext' => 'text',
      'longtext' => 'text',
      'blob' => 'blob',
      'tinyblob' => 'blob',
      'mediumblob' => 'blob',
      'longblob' => 'blob',
      'date' => 'date',
      'datetime' => 'datetime',
      'timestamp' => 'timestamp',
      'time' => 'time',
      'year' => 'year',
      'boolean' => 'boolean',
      'bool' => 'boolean',
      'json' => 'json',
      'enum' => 'enum',
      'set' => 'set',
    ];

    return $typeMap[strtolower($mysqlType)] ?? $mysqlType;
  }
}
