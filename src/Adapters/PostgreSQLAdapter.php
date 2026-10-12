<?php

declare(strict_types=1);

namespace Mygra\Adapters;

use Mygra\Schema\TableSchema;
use Mygra\Schema\ColumnSchema;

/**
 * PostgreSQL Database Adapter
 * 
 * Full-featured PostgreSQL adapter with schema introspection,
 * transaction support with savepoints, and streaming capabilities.
 */
class PostgreSQLAdapter extends AbstractAdapter
{
  protected string $driver = 'pgsql';
  private string $schema = 'public';

  // =========================================================================
  // Connection Management
  // =========================================================================

  public function connect(array $config): void
  {
    $this->config = $config;

    $host = $config['host'] ?? 'localhost';
    $port = $config['port'] ?? 5432;
    $database = $config['database'] ?? '';
    $this->schema = $config['schema'] ?? 'public';

    $dsn = "pgsql:host={$host};port={$port};dbname={$database}";

    $options = [
      \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
      \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
      \PDO::ATTR_EMULATE_PREPARES => false,
    ];

    if (isset($config['options']) && is_array($config['options'])) {
      $options = array_merge($options, $config['options']);
    }

    $this->pdo = new \PDO(
      $dsn,
      $config['user'] ?? 'postgres',
      $config['password'] ?? '',
      $options
    );

    // Set search path to the specified schema
    $this->pdo->exec("SET search_path TO " . $this->quoteIdentifier($this->schema));
  }

  /**
   * Set the active schema (search_path).
   */
  public function setSchema(string $schema): void
  {
    $this->schema = $schema;
    $this->execute("SET search_path TO " . $this->quoteIdentifier($schema));
  }

  public function streamQuery(string $sql, array $params = []): \Generator
  {
    // PostgreSQL supports cursors for efficient streaming
    $cursorName = 'mygra_cursor_' . uniqid();

    $this->beginTransaction();

    try {
      // Declare cursor
      $this->execute("DECLARE {$cursorName} CURSOR FOR {$sql}", $params);

      // Fetch in batches
      $batchSize = 1000;
      while (true) {
        $rows = $this->query("FETCH {$batchSize} FROM {$cursorName}");

        if (empty($rows)) {
          break;
        }

        foreach ($rows as $row) {
          yield $row;
        }
      }

      $this->execute("CLOSE {$cursorName}");
      $this->commit();
    } catch (\Exception $e) {
      $this->rollback();
      throw $e;
    }
  }

  // =========================================================================
  // Schema Introspection
  // =========================================================================

  public function getTables(): array
  {
    $result = $this->query(
      "SELECT table_name 
             FROM information_schema.tables 
             WHERE table_schema = $1 
             AND table_type = 'BASE TABLE'
             ORDER BY table_name",
      [$this->schema]
    );

    return array_column($result, 'table_name');
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
                c.column_name,
                c.data_type,
                c.udt_name,
                c.is_nullable,
                c.column_default,
                c.character_maximum_length,
                c.numeric_precision,
                c.numeric_scale,
                CASE WHEN pk.column_name IS NOT NULL THEN 'YES' ELSE 'NO' END as is_primary_key,
                pgd.description as column_comment
             FROM information_schema.columns c
             LEFT JOIN (
                 SELECT kcu.column_name
                 FROM information_schema.table_constraints tc
                 JOIN information_schema.key_column_usage kcu 
                     ON tc.constraint_name = kcu.constraint_name
                 WHERE tc.table_schema = $1
                 AND tc.table_name = $2
                 AND tc.constraint_type = 'PRIMARY KEY'
             ) pk ON c.column_name = pk.column_name
             LEFT JOIN pg_catalog.pg_statio_all_tables st 
                 ON st.schemaname = c.table_schema 
                 AND st.relname = c.table_name
             LEFT JOIN pg_catalog.pg_description pgd 
                 ON pgd.objoid = st.relid 
                 AND pgd.objsubid = c.ordinal_position
             WHERE c.table_schema = $1 
             AND c.table_name = $2
             ORDER BY c.ordinal_position",
      [$this->schema, $table]
    );
  }

  public function getPrimaryKey(string $table): string|array|null
  {
    $result = $this->query(
      "SELECT kcu.column_name
             FROM information_schema.table_constraints tc
             JOIN information_schema.key_column_usage kcu 
                 ON tc.constraint_name = kcu.constraint_name
                 AND tc.table_schema = kcu.table_schema
             WHERE tc.table_schema = $1
             AND tc.table_name = $2
             AND tc.constraint_type = 'PRIMARY KEY'
             ORDER BY kcu.ordinal_position",
      [$this->schema, $table]
    );

    if (empty($result)) {
      return null;
    }

    $columns = array_column($result, 'column_name');
    return count($columns) === 1 ? $columns[0] : $columns;
  }

  public function getForeignKeys(string $table): array
  {
    $result = $this->query(
      "SELECT
                tc.constraint_name,
                kcu.column_name,
                ccu.table_name AS referenced_table,
                ccu.column_name AS referenced_column
             FROM information_schema.table_constraints tc
             JOIN information_schema.key_column_usage kcu
                 ON tc.constraint_name = kcu.constraint_name
                 AND tc.table_schema = kcu.table_schema
             JOIN information_schema.constraint_column_usage ccu
                 ON ccu.constraint_name = tc.constraint_name
                 AND ccu.table_schema = tc.table_schema
             WHERE tc.table_schema = $1
             AND tc.table_name = $2
             AND tc.constraint_type = 'FOREIGN KEY'",
      [$this->schema, $table]
    );

    $foreignKeys = [];
    foreach ($result as $row) {
      $foreignKeys[] = [
        'name' => $row['constraint_name'],
        'column' => $row['column_name'],
        'referencedTable' => $row['referenced_table'],
        'referencedColumn' => $row['referenced_column'],
      ];
    }

    return $foreignKeys;
  }

  public function getIndexes(string $table): array
  {
    $result = $this->query(
      "SELECT
                i.relname as index_name,
                a.attname as column_name,
                ix.indisunique as is_unique,
                ix.indisprimary as is_primary
             FROM pg_class t
             JOIN pg_index ix ON t.oid = ix.indrelid
             JOIN pg_class i ON i.oid = ix.indexrelid
             JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY(ix.indkey)
             JOIN pg_namespace n ON n.oid = t.relnamespace
             WHERE n.nspname = $1
             AND t.relname = $2
             ORDER BY i.relname, a.attnum",
      [$this->schema, $table]
    );

    $indexes = [];
    foreach ($result as $row) {
      $indexName = $row['index_name'];
      if (!isset($indexes[$indexName])) {
        $indexes[$indexName] = [
          'name' => $indexName,
          'columns' => [],
          'unique' => (bool) $row['is_unique'],
          'primary' => (bool) $row['is_primary'],
        ];
      }
      $indexes[$indexName]['columns'][] = $row['column_name'];
    }

    return array_values($indexes);
  }

  // =========================================================================
  // Batch Operations (PostgreSQL Optimized)
  // =========================================================================

  /**
   * Batch insert with ON CONFLICT (upsert) support.
   */
  public function batchUpsert(
    string $table,
    array $columns,
    array $rows,
    array $conflictColumns,
    array $updateColumns = []
  ): int {
    if (empty($rows)) {
      return 0;
    }

    $quotedTable = $this->quoteIdentifier($table);
    $quotedColumns = array_map([$this, 'quoteIdentifier'], $columns);
    $columnList = implode(', ', $quotedColumns);

    // PDO binds ? / :name only, not $1-style placeholders
    $placeholderRow = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
    $rowPlaceholders = array_fill(0, count($rows), $placeholderRow);
    $values = [];

    foreach ($rows as $row) {
      foreach ($columns as $col) {
        $values[] = $row[$col] ?? null;
      }
    }

    $conflictList = implode(', ', array_map([$this, 'quoteIdentifier'], $conflictColumns));

    $sql = "INSERT INTO {$quotedTable} ({$columnList}) VALUES " . implode(', ', $rowPlaceholders);
    $sql .= " ON CONFLICT ({$conflictList})";

    if (!empty($updateColumns)) {
      $updates = [];
      foreach ($updateColumns as $col) {
        $quoted = $this->quoteIdentifier($col);
        $updates[] = "{$quoted} = EXCLUDED.{$quoted}";
      }
      $sql .= " DO UPDATE SET " . implode(', ', $updates);
    } else {
      $sql .= " DO NOTHING";
    }

    return $this->execute($sql, $values);
  }

  // =========================================================================
  // Utility Methods
  // =========================================================================

  public function quoteIdentifier(string $identifier): string
  {
    // PostgreSQL uses double quotes for identifier quoting
    $identifier = str_replace('"', '""', $identifier);
    return '"' . $identifier . '"';
  }

  public function getPlaceholderStyle(): string
  {
    return 'numbered'; // PostgreSQL uses $1, $2, etc.
  }

  /**
   * Get the current schema.
   */
  public function getCurrentSchema(): string
  {
    return $this->schema;
  }

  /**
   * Get PostgreSQL server version.
   */
  public function getServerVersion(): string
  {
    $result = $this->query("SELECT version()");
    return $result[0]['version'] ?? '';
  }

  /**
   * Get all available schemas.
   */
  public function getSchemas(): array
  {
    $result = $this->query(
      "SELECT schema_name 
             FROM information_schema.schemata 
             WHERE schema_name NOT IN ('pg_catalog', 'information_schema')
             ORDER BY schema_name"
    );
    return array_column($result, 'schema_name');
  }

  // =========================================================================
  // Helper Methods
  // =========================================================================

  protected function buildColumnSchema(array $columnData): ColumnSchema
  {
    // Detect auto-increment (serial/identity)
    $default = $columnData['column_default'] ?? '';
    $isAutoIncrement = str_contains($default, 'nextval(')
      || str_contains($default, 'GENERATED');

    return new ColumnSchema(
      name: $columnData['column_name'],
      type: $this->normalizeType($columnData['data_type']),
      nativeType: $columnData['udt_name'] ?? $columnData['data_type'],
      nullable: ($columnData['is_nullable'] ?? 'YES') === 'YES',
      default: $isAutoIncrement ? null : $columnData['column_default'],
      length: isset($columnData['character_maximum_length'])
        ? (int) $columnData['character_maximum_length']
        : null,
      precision: isset($columnData['numeric_precision'])
        ? (int) $columnData['numeric_precision']
        : null,
      scale: isset($columnData['numeric_scale'])
        ? (int) $columnData['numeric_scale']
        : null,
      isPrimaryKey: ($columnData['is_primary_key'] ?? 'NO') === 'YES',
      isAutoIncrement: $isAutoIncrement,
      isUnique: false, // Would need separate query
      comment: $columnData['column_comment'] ?? null,
      extra: []
    );
  }

  /**
   * Normalize PostgreSQL data type to a standard type.
   */
  private function normalizeType(string $pgType): string
  {
    $typeMap = [
      'integer' => 'integer',
      'bigint' => 'bigint',
      'smallint' => 'smallint',
      'int4' => 'integer',
      'int8' => 'bigint',
      'int2' => 'smallint',
      'serial' => 'integer',
      'bigserial' => 'bigint',
      'real' => 'float',
      'double precision' => 'double',
      'numeric' => 'decimal',
      'decimal' => 'decimal',
      'money' => 'decimal',
      'character' => 'char',
      'character varying' => 'varchar',
      'text' => 'text',
      'bytea' => 'blob',
      'date' => 'date',
      'timestamp without time zone' => 'datetime',
      'timestamp with time zone' => 'datetime',
      'time without time zone' => 'time',
      'time with time zone' => 'time',
      'boolean' => 'boolean',
      'bool' => 'boolean',
      'json' => 'json',
      'jsonb' => 'json',
      'uuid' => 'uuid',
      'inet' => 'varchar',
      'cidr' => 'varchar',
      'macaddr' => 'varchar',
    ];

    return $typeMap[strtolower($pgType)] ?? $pgType;
  }
}
