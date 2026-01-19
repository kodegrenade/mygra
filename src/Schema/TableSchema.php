<?php

declare(strict_types=1);

namespace Mygra\Schema;

/**
 * Table Schema
 * 
 * Represents the complete schema of a database table including
 * columns, primary key, foreign keys, and indexes.
 */
class TableSchema
{
  /**
   * @param string $name Table name
   * @param array<string, ColumnSchema> $columns Columns indexed by name
   * @param string|array|null $primaryKey Primary key column(s)
   * @param array $foreignKeys Foreign key definitions
   * @param array $indexes Index definitions
   */
  public function __construct(
    public readonly string $name,
    public readonly array $columns = [],
    public readonly string|array|null $primaryKey = null,
    public readonly array $foreignKeys = [],
    public readonly array $indexes = []
  ) {}

  /**
   * Get a column by name.
   */
  public function getColumn(string $name): ?ColumnSchema
  {
    return $this->columns[$name] ?? null;
  }

  /**
   * Check if a column exists.
   */
  public function hasColumn(string $name): bool
  {
    return isset($this->columns[$name]);
  }

  /**
   * Get all column names.
   */
  public function getColumnNames(): array
  {
    return array_keys($this->columns);
  }

  /**
   * Get columns that are nullable.
   */
  public function getNullableColumns(): array
  {
    return array_filter($this->columns, fn($col) => $col->nullable);
  }

  /**
   * Get columns that are required (not nullable and no default).
   */
  public function getRequiredColumns(): array
  {
    return array_filter(
      $this->columns,
      fn($col) => !$col->nullable && $col->default === null && !$col->isAutoIncrement
    );
  }

  /**
   * Get the primary key column(s) as array.
   */
  public function getPrimaryKeyColumns(): array
  {
    if ($this->primaryKey === null) {
      return [];
    }
    return is_array($this->primaryKey) ? $this->primaryKey : [$this->primaryKey];
  }

  /**
   * Check if table has a composite primary key.
   */
  public function hasCompositePrimaryKey(): bool
  {
    return is_array($this->primaryKey) && count($this->primaryKey) > 1;
  }

  /**
   * Get foreign key for a specific column.
   */
  public function getForeignKeyForColumn(string $column): ?array
  {
    foreach ($this->foreignKeys as $fk) {
      if ($fk['column'] === $column) {
        return $fk;
      }
    }
    return null;
  }

  /**
   * Convert to array representation.
   */
  public function toArray(): array
  {
    return [
      'name' => $this->name,
      'columns' => array_map(fn($col) => $col->toArray(), $this->columns),
      'primaryKey' => $this->primaryKey,
      'foreignKeys' => $this->foreignKeys,
      'indexes' => $this->indexes,
    ];
  }

  /**
   * Create a TableSchema from array data.
   */
  public static function fromArray(array $data): self
  {
    $columns = [];
    foreach ($data['columns'] ?? [] as $colData) {
      $col = ColumnSchema::fromArray($colData);
      $columns[$col->name] = $col;
    }

    return new self(
      name: $data['name'],
      columns: $columns,
      primaryKey: $data['primaryKey'] ?? null,
      foreignKeys: $data['foreignKeys'] ?? [],
      indexes: $data['indexes'] ?? []
    );
  }

  /**
   * Generate a CREATE TABLE statement for this schema.
   */
  public function toCreateTableSQL(string $driver = 'mysql'): string
  {
    $driver = strtolower($driver);
    $lines = [];

    foreach ($this->columns as $col) {
      $nativeType = TypeMapper::toNative($col->type, $driver);

      // Handle length
      $length = $col->length;
      if ($length === null && $col->type === 'string') {
        $length = TypeMapper::defaultLength($col->type, $driver);
      }

      $typeStr = $nativeType;
      if ($length !== null && in_array($driver, ['mysql', 'sqlsrv'])) {
        $typeStr .= "({$length})";
      }

      $line = "  " . $this->quoteIdentifier($col->name, $driver) . " {$typeStr}";

      if (!$col->nullable) {
        $line .= ' NOT NULL';
      }

      if ($col->default !== null) {
        $default = $col->default;
        if (is_string($default) && !is_numeric($default)) {
          // Avoid double quoting if already quoted
          if (!(str_starts_with($default, "'") && str_ends_with($default, "'"))) {
            $default = "'{$default}'";
          }
        }
        $line .= " DEFAULT {$default}";
      }

      if ($col->isAutoIncrement) {
        $line .= match ($driver) {
          'mysql' => ' AUTO_INCREMENT',
          'pgsql' => '', // PostgreSQL usually uses SERIAL or IDENTITY which is part of the type
          'sqlsrv' => ' IDENTITY(1,1)',
          default => '',
        };
      }

      $lines[] = $line;
    }

    // Add primary key
    if ($this->primaryKey) {
      $pkCols = is_array($this->primaryKey) ? $this->primaryKey : [$this->primaryKey];
      $quotedPkCols = array_map(fn($c) => $this->quoteIdentifier($c, $driver), $pkCols);
      $pkList = implode(', ', $quotedPkCols);
      $lines[] = "  PRIMARY KEY ({$pkList})";
    }

    $columnDefs = implode(",\n", $lines);
    $tableName = $this->quoteIdentifier($this->name, $driver);

    return "CREATE TABLE {$tableName} (\n{$columnDefs}\n);";
  }

  private function quoteIdentifier(string $identifier, string $driver): string
  {
    return match ($driver) {
      'mysql' => "`{$identifier}`",
      'pgsql', 'sqlite' => "\"{$identifier}\"",
      'sqlsrv' => "[{$identifier}]",
      default => "`{$identifier}`",
    };
  }
}
