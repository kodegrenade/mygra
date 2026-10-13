<?php

declare(strict_types=1);

namespace Mygra\Adapters;

use Mygra\Schema\ColumnSchema;
use Mygra\Schema\TableSchema;

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

  /** Most parameters one statement may bind (MySQL and PostgreSQL: 65535) */
  protected int $maxParams = 65535;

  /** Most rows one multi-row INSERT may hold */
  protected int $maxRowsPerInsert = PHP_INT_MAX;

  // =========================================================================
  // Connection Management
  // =========================================================================

  abstract public function connect(array $config): void;

  protected function getPdo(): \PDO
  {
    if ($this->pdo === null) {
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
  abstract protected function getColumns(string $table): array;
  abstract public function getPrimaryKey(string $table): string|array|null;
  abstract public function getForeignKeys(string $table): array;
  abstract protected function getIndexes(string $table): array;

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

  // =========================================================================
  // Batch Operations
  // =========================================================================

  public function batchInsert(string $table, array $columns, array $rows): int
  {
    return $this->writeRows($this->quoteTable($table), $columns, $rows, '');
  }

  public function batchUpsert(string $table, array $columns, array $rows, array $keyColumns): int
  {
    return $this->writeRows($this->quoteTable($table), $columns, $rows, $this->upsertClause($columns, $keyColumns));
  }

  /**
   * INSERT ... VALUES in chunks that stay under the driver's parameter limit, plus an optional trailing clause.
   */
  protected function writeRows(string $quotedTable, array $columns, array $rows, string $suffix): int
  {
    if (!$rows) {
      return 0;
    }

    $columnList = implode(', ', array_map([$this, 'quoteIdentifier'], $columns));
    $placeholderRow = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';

    $total = 0;
    foreach (array_chunk($rows, $this->insertChunkSize(count($columns))) as $chunk) {
      $placeholders = implode(', ', array_fill(0, count($chunk), $placeholderRow));
      $total += $this->execute("INSERT INTO {$quotedTable} ({$columnList}) VALUES {$placeholders}{$suffix}", $this->rowValues($chunk, $columns));
    }
    return $total;
  }

  /**
   * ON CONFLICT upsert (PostgreSQL, SQLite 3.24+): update every non-key column in place.
   * SQLite's INSERT OR REPLACE is avoided on purpose: it deletes the row, firing ON DELETE CASCADE.
   */
  protected function upsertClause(array $columns, array $keyColumns): string
  {
    $q = [$this, 'quoteIdentifier'];
    $updates = array_map(fn($c) => "{$q($c)} = EXCLUDED.{$q($c)}", array_diff($columns, $keyColumns));
    return ' ON CONFLICT (' . implode(', ', array_map($q, $keyColumns)) . ') '
      . ($updates ? 'DO UPDATE SET ' . implode(', ', $updates) : 'DO NOTHING');
  }

  /**
   * Row values flattened in column order, for binding.
   */
  protected function rowValues(array $rows, array $columns): array
  {
    $values = [];
    foreach ($rows as $row) {
      foreach ($columns as $col) {
        $values[] = $row[$col] ?? null;
      }
    }
    return $values;
  }

  /**
   * Rows per INSERT that keep rows x columns under the driver's parameter limit (wide tables).
   */
  protected function insertChunkSize(int $columnCount): int
  {
    return max(1, min($this->maxRowsPerInsert, intdiv($this->maxParams, $columnCount)));
  }

  public function getRowCount(string $table, ?string $where = null): int
  {
    $sql = 'SELECT COUNT(*) as cnt FROM ' . $this->quoteTable($table);
    if ($where !== null) {
      $sql .= ' WHERE ' . $where;
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

  // =========================================================================
  // Utility Methods
  // =========================================================================

  protected function quote(string $value): string
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

  public function quoteTable(string $table): string
  {
    return $this->quoteIdentifier($table);
  }

  public function limit(string $sql, int $count): string
  {
    return "{$sql} LIMIT {$count}";
  }

  public function getDriverName(): string
  {
    return $this->driver;
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
      indexes: $this->getIndexes($table),
    );
  }

  /**
   * Build a ColumnSchema from raw column data.
   * Override in concrete adapters to handle driver-specific column metadata.
   */
  abstract protected function buildColumnSchema(array $columnData): ColumnSchema;
}
