<?php

declare(strict_types=1);

namespace Mygra\Adapters;

use Mygra\Schema\TableSchema;
use Mygra\Schema\ColumnSchema;

/**
 * Abstract Database Adapter
 * 
 * Provides common functionality for all database adapters.
 * Concrete adapters should extend this class and implement driver-specific methods.
 */
abstract class AbstractAdapter implements AdapterInterface
{
  protected ?\PDO $pdo = null;
  protected array $config = [];
  protected string $driver = '';

  // =========================================================================
  // Connection Management
  // =========================================================================

  abstract public function connect(array $config): void;

  public function disconnect(): void
  {
    $this->pdo = null;
  }

  public function isConnected(): bool
  {
    return $this->pdo !== null;
  }

  public function getPdo(): \PDO
  {
    if (!$this->isConnected()) {
      throw new \RuntimeException('Not connected to database');
    }
    return $this->pdo;
  }

  // =========================================================================
  // Query Execution
  // =========================================================================

  public function query(string $sql, array $params = []): array
  {
    $stmt = $this->getPdo()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(\PDO::FETCH_ASSOC);
  }

  public function execute(string $sql, array $params = []): int
  {
    $stmt = $this->getPdo()->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
  }

  public function streamQuery(string $sql, array $params = []): \Generator
  {
    $stmt = $this->getPdo()->prepare($sql);
    $stmt->execute($params);

    while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
      yield $row;
    }
  }

  // =========================================================================
  // Schema Introspection (Abstract - must be implemented by concrete adapters)
  // =========================================================================

  abstract public function getTables(): array;
  abstract public function getTableSchema(string $table): TableSchema;
  abstract public function getColumns(string $table): array;
  abstract public function getPrimaryKey(string $table): string|array|null;
  abstract public function getForeignKeys(string $table): array;
  abstract public function getIndexes(string $table): array;

  // =========================================================================
  // Transaction Support
  // =========================================================================

  public function beginTransaction(): void
  {
    $this->getPdo()->beginTransaction();
  }

  public function commit(): void
  {
    $this->getPdo()->commit();
  }

  public function rollback(): void
  {
    $this->getPdo()->rollBack();
  }

  public function inTransaction(): bool
  {
    return $this->getPdo()->inTransaction();
  }

  public function savepoint(string $name): void
  {
    $this->execute("SAVEPOINT " . $this->quoteIdentifier($name));
  }

  public function rollbackToSavepoint(string $name): void
  {
    $this->execute("ROLLBACK TO SAVEPOINT " . $this->quoteIdentifier($name));
  }

  public function releaseSavepoint(string $name): void
  {
    $this->execute("RELEASE SAVEPOINT " . $this->quoteIdentifier($name));
  }

  // =========================================================================
  // Batch Operations
  // =========================================================================

  public function batchInsert(string $table, array $columns, array $rows): int
  {
    if (empty($rows)) {
      return 0;
    }

    $quotedTable = $this->quoteIdentifier($table);
    $quotedColumns = array_map([$this, 'quoteIdentifier'], $columns);
    $columnList = implode(', ', $quotedColumns);

    // Build placeholders for each row
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

  public function getRowCount(string $table, ?string $where = null): int
  {
    $sql = "SELECT COUNT(*) as cnt FROM " . $this->quoteIdentifier($table);
    if ($where !== null) {
      $sql .= " WHERE " . $where;
    }

    $result = $this->query($sql);
    return (int) ($result[0]['cnt'] ?? 0);
  }

  public function syncSequences(string $table): void
  {
    // MySQL, SQLite and SQL Server advance their counters past explicit IDs on insert
  }

  public function reserveIds(string $table, int $next): void
  {
    // ponytail: no-op for SQLite (rowid = max + 1); add sqlite_sequence handling if a live SQLite target ever matters
  }

  public function getLastInsertId(?string $sequenceName = null): string|false
  {
    return $this->getPdo()->lastInsertId($sequenceName);
  }

  // =========================================================================
  // Utility Methods
  // =========================================================================

  public function quote(string $value): string
  {
    return $this->getPdo()->quote($value);
  }

  public function quoteIdentifier(string $identifier): string
  {
    // Default implementation using backticks (MySQL style)
    // Override in specific adapters for different quote characters
    $identifier = str_replace('`', '``', $identifier);
    return '`' . $identifier . '`';
  }

  public function getDriverName(): string
  {
    return $this->driver;
  }

  public function getPlaceholderStyle(): string
  {
    return 'positional'; // Default to ? style placeholders
  }

    // =========================================================================
    // Helper Methods
    // =========================================================================

  /**
   * Build a TableSchema from column data.
   */
  protected function buildTableSchema(string $table, array $columnsData): TableSchema
  {
    $columns = [];
    $primaryKey = null;

    foreach ($columnsData as $col) {
      $columnSchema = $this->buildColumnSchema($col);
      $columns[$columnSchema->name] = $columnSchema;

      if ($columnSchema->isPrimaryKey) {
        $primaryKey = $columnSchema->name;
      }
    }

    return new TableSchema(
      name: $table,
      columns: $columns,
      primaryKey: $primaryKey,
      foreignKeys: $this->getForeignKeys($table),
      indexes: $this->getIndexes($table)
    );
  }

  /**
   * Build a ColumnSchema from raw column data.
   * Override in concrete adapters to handle driver-specific column metadata.
   */
  abstract protected function buildColumnSchema(array $columnData): ColumnSchema;
}
