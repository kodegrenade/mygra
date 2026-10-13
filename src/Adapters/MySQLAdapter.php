<?php

declare(strict_types=1);

namespace Mygra\Adapters;

use Mygra\Schema\ColumnSchema;
use Mygra\Schema\TableSchema;

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
      self::bufferedQueryAttr() => true,
    ];

    // Merge custom options
    if (isset($config['options']) && is_array($config['options'])) {
      $options = array_merge($options, $config['options']);
    }

    $this->pdo = new \PDO(
      $dsn,
      $config['user'] ?? 'root',
      $config['password'] ?? '',
      $options,
    );

    // Set SQL mode (relaxed to support legacy zero dates)
    $this->pdo->exec("SET sql_mode='STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO'");
  }

  protected function upsertClause(array $columns, array $keyColumns): string
  {
    // VALUES() is deprecated in MySQL 8.0.20+ but still works, and is the only form MariaDB accepts.
    // With no non-key columns, "key = VALUES(key)" makes an existing row a no-op instead of an error.
    $q = [$this, 'quoteIdentifier'];
    $updates = array_diff($columns, $keyColumns) ?: $keyColumns;
    return ' ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(fn($c) => "{$q($c)} = VALUES({$q($c)})", $updates));
  }

  public function reserveIds(string $table, int $next): void
  {
    // InnoDB never sets the counter below MAX(id) + 1, so this cannot move it backwards past used IDs
    $this->getPdo()->exec("ALTER TABLE {$this->quoteTable($table)} AUTO_INCREMENT = {$next}");
  }

  /**
   * PDO::MYSQL_ATTR_USE_BUFFERED_QUERY is deprecated in PHP 8.5; Pdo\Mysql only exists from 8.4.
   */
  private static function bufferedQueryAttr(): int
  {
    return defined('Pdo\Mysql::ATTR_USE_BUFFERED_QUERY')
      ? \Pdo\Mysql::ATTR_USE_BUFFERED_QUERY
      : \PDO::MYSQL_ATTR_USE_BUFFERED_QUERY;
  }

  /**
   * Enable unbuffered queries for streaming large datasets.
   */
  private function enableUnbufferedQueries(): void
  {
    $this->pdo->setAttribute(self::bufferedQueryAttr(), false);
  }

  /**
   * Disable unbuffered queries (return to default behavior).
   */
  private function disableUnbufferedQueries(): void
  {
    $this->pdo->setAttribute(self::bufferedQueryAttr(), true);
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
             ORDER BY TABLE_NAME",
    );

    return array_column($result, 'TABLE_NAME');
  }

  public function getTableSchema(string $table): TableSchema
  {
    $columnsData = $this->getColumns($table);
    return $this->buildTableSchema($table, $columnsData);
  }

  protected function getColumns(string $table): array
  {
    return $this->query(
      'SELECT 
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
             ORDER BY ORDINAL_POSITION',
      [$table],
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
      [$table],
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
      'SELECT 
                CONSTRAINT_NAME,
                COLUMN_NAME,
                REFERENCED_TABLE_NAME,
                REFERENCED_COLUMN_NAME
             FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = ?
             AND REFERENCED_TABLE_NAME IS NOT NULL',
      [$table],
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

  protected function getIndexes(string $table): array
  {
    $result = $this->query(
      'SELECT 
                INDEX_NAME,
                COLUMN_NAME,
                NON_UNIQUE,
                SEQ_IN_INDEX
             FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = ?
             ORDER BY INDEX_NAME, SEQ_IN_INDEX',
      [$table],
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
  // Utility Methods
  // =========================================================================

  public function quoteIdentifier(string $identifier): string
  {
    // MySQL uses backticks for identifier quoting
    $identifier = str_replace('`', '``', $identifier);
    return '`' . $identifier . '`';
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
      extra: ['mysql_extra' => $extra],
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
