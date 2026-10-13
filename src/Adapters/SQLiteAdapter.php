<?php

declare(strict_types=1);

namespace Mygra\Adapters;

use Mygra\Schema\TableSchema;
use Mygra\Schema\ColumnSchema;

/**
 * SQLite Database Adapter
 * 
 * Full-featured SQLite adapter with schema introspection and transaction support.
 * Ideal for testing, development, and embedded use cases.
 */
class SQLiteAdapter extends AbstractAdapter
{
  protected string $driver = 'sqlite';

  /** SQLITE_MAX_VARIABLE_NUMBER (default since SQLite 3.32) */
  protected int $maxParams = 32766;

  /** SQLITE_MAX_COMPOUND_SELECT default */
  protected int $maxRowsPerInsert = 500;

  // =========================================================================
  // Connection Management
  // =========================================================================

  public function connect(array $config): void
  {
    $this->config = $config;

    $database = $config['database'] ?? ':memory:';
    $dsn = "sqlite:{$database}";

    $options = [
      \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
      \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
    ];

    if (isset($config['options']) && is_array($config['options'])) {
      $options = array_merge($options, $config['options']);
    }

    $this->pdo = new \PDO($dsn, null, null, $options);

    // Enable foreign key constraints (disabled by default in SQLite)
    $this->pdo->exec('PRAGMA foreign_keys = ON');

    // Set journal mode for better performance and durability
    $journalMode = $config['journal_mode'] ?? 'WAL';
    $this->pdo->exec("PRAGMA journal_mode = {$journalMode}");

    // Set synchronous mode
    $synchronous = $config['synchronous'] ?? 'NORMAL';
    $this->pdo->exec("PRAGMA synchronous = {$synchronous}");
  }

  // =========================================================================
  // Schema Introspection
  // =========================================================================

  public function getTables(): array
  {
    $result = $this->query(
      "SELECT name 
             FROM sqlite_master 
             WHERE type = 'table' 
             AND name NOT LIKE 'sqlite_%'
             ORDER BY name"
    );

    return array_column($result, 'name');
  }

  public function getTableSchema(string $table): TableSchema
  {
    $columnsData = $this->getColumns($table);
    return $this->buildTableSchema($table, $columnsData);
  }

  public function getColumns(string $table): array
  {
    return $this->query("PRAGMA table_info(" . $this->quoteIdentifier($table) . ")");
  }

  public function getPrimaryKey(string $table): string|array|null
  {
    $columns = $this->getColumns($table);

    $pkColumns = array_filter($columns, fn($col) => $col['pk'] > 0);

    if (empty($pkColumns)) {
      return null;
    }

    // Sort by pk order
    usort($pkColumns, fn($a, $b) => $a['pk'] <=> $b['pk']);

    $pkNames = array_column($pkColumns, 'name');
    return count($pkNames) === 1 ? $pkNames[0] : $pkNames;
  }

  public function getForeignKeys(string $table): array
  {
    $result = $this->query("PRAGMA foreign_key_list(" . $this->quoteIdentifier($table) . ")");

    $foreignKeys = [];
    foreach ($result as $row) {
      $foreignKeys[] = [
        'name' => "fk_{$table}_{$row['from']}",
        'column' => $row['from'],
        'referencedTable' => $row['table'],
        'referencedColumn' => $row['to'],
        'onUpdate' => $row['on_update'],
        'onDelete' => $row['on_delete'],
      ];
    }

    return $foreignKeys;
  }

  public function getIndexes(string $table): array
  {
    $result = $this->query("PRAGMA index_list(" . $this->quoteIdentifier($table) . ")");

    $indexes = [];
    foreach ($result as $row) {
      $indexName = $row['name'];

      // Get columns for this index
      $indexInfo = $this->query("PRAGMA index_info(" . $this->quoteIdentifier($indexName) . ")");

      $indexes[] = [
        'name' => $indexName,
        'columns' => array_column($indexInfo, 'name'),
        'unique' => (bool) $row['unique'],
        'primary' => str_starts_with($indexName, 'sqlite_autoindex_'),
      ];
    }

    return $indexes;
  }

  // =========================================================================
  // Transaction Support
  // =========================================================================

  public function savepoint(string $name): void
  {
    $this->execute("SAVEPOINT {$name}");
  }

  public function rollbackToSavepoint(string $name): void
  {
    $this->execute("ROLLBACK TO SAVEPOINT {$name}");
  }

  public function releaseSavepoint(string $name): void
  {
    $this->execute("RELEASE SAVEPOINT {$name}");
  }

  // =========================================================================
  // Utility Methods
  // =========================================================================

  public function quoteIdentifier(string $identifier): string
  {
    // SQLite uses double quotes for identifier quoting
    $identifier = str_replace('"', '""', $identifier);
    return '"' . $identifier . '"';
  }

  public function getPlaceholderStyle(): string
  {
    return 'positional'; // SQLite uses ? placeholders
  }

  /**
   * Get SQLite version.
   */
  public function getServerVersion(): string
  {
    $result = $this->query("SELECT sqlite_version() as version");
    return $result[0]['version'] ?? '';
  }

  /**
   * Vacuum the database to reclaim space.
   */
  public function vacuum(): void
  {
    $this->execute('VACUUM');
  }

  /**
   * Analyze the database for query optimization.
   */
  public function analyze(): void
  {
    $this->execute('ANALYZE');
  }

  // =========================================================================
  // Helper Methods
  // =========================================================================

  protected function buildColumnSchema(array $columnData): ColumnSchema
  {
    // Parse type for length info (e.g., VARCHAR(255))
    $type = $columnData['type'];
    $length = null;

    if (preg_match('/(\w+)\((\d+)\)/', $type, $matches)) {
      $type = $matches[1];
      $length = (int) $matches[2];
    }

    return new ColumnSchema(
      name: $columnData['name'],
      type: $this->normalizeType($type),
      nativeType: $columnData['type'],
      nullable: $columnData['notnull'] == 0,
      default: $columnData['dflt_value'],
      length: $length,
      precision: null,
      scale: null,
      isPrimaryKey: $columnData['pk'] > 0,
      isAutoIncrement: $columnData['pk'] > 0 && strtoupper($type) === 'INTEGER',
      isUnique: false,
      comment: null,
      extra: []
    );
  }

  /**
   * Normalize SQLite data type to a standard type.
   */
  private function normalizeType(string $sqliteType): string
  {
    $type = strtoupper($sqliteType);

    // SQLite type affinity rules
    if (str_contains($type, 'INT')) {
      return 'integer';
    }
    if (str_contains($type, 'CHAR') || str_contains($type, 'CLOB') || str_contains($type, 'TEXT')) {
      return 'text';
    }
    if (str_contains($type, 'BLOB') || $type === '') {
      return 'blob';
    }
    if (str_contains($type, 'REAL') || str_contains($type, 'FLOA') || str_contains($type, 'DOUB')) {
      return 'float';
    }

    // NUMERIC affinity
    return 'numeric';
  }
}
