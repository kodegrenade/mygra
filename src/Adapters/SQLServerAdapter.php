<?php

declare(strict_types=1);

namespace Mygra\Adapters;

use Mygra\Schema\TableSchema;
use Mygra\Schema\ColumnSchema;

/**
 * SQL Server Database Adapter
 * 
 * Full-featured Microsoft SQL Server adapter with schema introspection,
 * transaction support with savepoints, and T-SQL compatibility.
 */
class SQLServerAdapter extends AbstractAdapter
{
  protected string $driver = 'sqlsrv';
  private string $schema = 'dbo';

  /** Documented limit is 2100, but a request with exactly 2100 is rejected; keep a margin */
  protected int $maxParams = 2000;

  /** Most rows a single INSERT ... VALUES may hold */
  protected int $maxRowsPerInsert = 1000;

  // =========================================================================
  // Connection Management
  // =========================================================================

  public function connect(array $config): void
  {
    $this->config = $config;

    $host = $config['host'] ?? 'localhost';
    $port = $config['port'] ?? 1433;
    $database = $config['database'] ?? '';
    $this->schema = $config['schema'] ?? 'dbo';

    // Check for PDO driver (sqlsrv or dblib)
    $driver = $config['pdo_driver'] ?? $this->detectDriver();

    if ($driver === 'sqlsrv') {
      // Native Windows driver
      $dsn = "sqlsrv:Server={$host},{$port};Database={$database}";
      // ODBC Driver 18 encrypts by default and rejects self-signed certificates
      if (!empty($config['trust_server_certificate'])) {
        $dsn .= ";TrustServerCertificate=1";
      }
    } else {
      // FreeTDS/dblib for Linux/Mac
      $dsn = "dblib:host={$host}:{$port};dbname={$database}";
    }

    $options = [
      \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
      \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
    ];

    if (isset($config['options']) && is_array($config['options'])) {
      $options = array_merge($options, $config['options']);
    }

    $this->pdo = new \PDO(
      $dsn,
      $config['user'] ?? 'sa',
      $config['password'] ?? '',
      $options
    );
  }

  /**
   * Detect available SQL Server PDO driver.
   */
  private function detectDriver(): string
  {
    $drivers = \PDO::getAvailableDrivers();

    if (in_array('sqlsrv', $drivers)) {
      return 'sqlsrv';
    }
    if (in_array('dblib', $drivers)) {
      return 'dblib';
    }

    throw new \RuntimeException(
      'No SQL Server PDO driver available. Install sqlsrv (Windows) or dblib (Linux/Mac).'
    );
  }

  /**
   * Set the active schema.
   */
  public function setSchema(string $schema): void
  {
    $this->schema = $schema;
  }

  // =========================================================================
  // Schema Introspection
  // =========================================================================

  public function getTables(): array
  {
    $result = $this->query(
      "SELECT TABLE_NAME 
             FROM INFORMATION_SCHEMA.TABLES 
             WHERE TABLE_SCHEMA = ? 
             AND TABLE_TYPE = 'BASE TABLE'
             ORDER BY TABLE_NAME",
      [$this->schema]
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
                c.COLUMN_NAME,
                c.DATA_TYPE,
                c.IS_NULLABLE,
                c.COLUMN_DEFAULT,
                c.CHARACTER_MAXIMUM_LENGTH,
                c.NUMERIC_PRECISION,
                c.NUMERIC_SCALE,
                CASE WHEN pk.COLUMN_NAME IS NOT NULL THEN 'YES' ELSE 'NO' END as IS_PRIMARY_KEY,
                COLUMNPROPERTY(OBJECT_ID(?), c.COLUMN_NAME, 'IsIdentity') as IS_IDENTITY
             FROM INFORMATION_SCHEMA.COLUMNS c
             LEFT JOIN (
                 SELECT ku.COLUMN_NAME
                 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS tc
                 JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE ku 
                     ON tc.CONSTRAINT_NAME = ku.CONSTRAINT_NAME
                 WHERE tc.TABLE_SCHEMA = ?
                 AND tc.TABLE_NAME = ?
                 AND tc.CONSTRAINT_TYPE = 'PRIMARY KEY'
             ) pk ON c.COLUMN_NAME = pk.COLUMN_NAME
             WHERE c.TABLE_SCHEMA = ?
             AND c.TABLE_NAME = ?
             ORDER BY c.ORDINAL_POSITION",
      [$this->schema . '.' . $table, $this->schema, $table, $this->schema, $table]
    );
  }

  public function getPrimaryKey(string $table): string|array|null
  {
    $result = $this->query(
      "SELECT ku.COLUMN_NAME
             FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS tc
             JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE ku 
                 ON tc.CONSTRAINT_NAME = ku.CONSTRAINT_NAME
             WHERE tc.TABLE_SCHEMA = ?
             AND tc.TABLE_NAME = ?
             AND tc.CONSTRAINT_TYPE = 'PRIMARY KEY'
             ORDER BY ku.ORDINAL_POSITION",
      [$this->schema, $table]
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
                fk.name as CONSTRAINT_NAME,
                cp.name as COLUMN_NAME,
                OBJECT_NAME(fk.referenced_object_id) as REFERENCED_TABLE,
                cr.name as REFERENCED_COLUMN
             FROM sys.foreign_keys fk
             JOIN sys.foreign_key_columns fkc ON fk.object_id = fkc.constraint_object_id
             JOIN sys.columns cp ON fkc.parent_object_id = cp.object_id 
                 AND fkc.parent_column_id = cp.column_id
             JOIN sys.columns cr ON fkc.referenced_object_id = cr.object_id 
                 AND fkc.referenced_column_id = cr.column_id
             WHERE OBJECT_SCHEMA_NAME(fk.parent_object_id) = ?
             AND OBJECT_NAME(fk.parent_object_id) = ?",
      [$this->schema, $table]
    );

    $foreignKeys = [];
    foreach ($result as $row) {
      $foreignKeys[] = [
        'name' => $row['CONSTRAINT_NAME'],
        'column' => $row['COLUMN_NAME'],
        'referencedTable' => $row['REFERENCED_TABLE'],
        'referencedColumn' => $row['REFERENCED_COLUMN'],
      ];
    }

    return $foreignKeys;
  }

  public function getIndexes(string $table): array
  {
    $result = $this->query(
      "SELECT 
                i.name as INDEX_NAME,
                c.name as COLUMN_NAME,
                i.is_unique as IS_UNIQUE,
                i.is_primary_key as IS_PRIMARY
             FROM sys.indexes i
             JOIN sys.index_columns ic ON i.object_id = ic.object_id AND i.index_id = ic.index_id
             JOIN sys.columns c ON ic.object_id = c.object_id AND ic.column_id = c.column_id
             WHERE OBJECT_SCHEMA_NAME(i.object_id) = ?
             AND OBJECT_NAME(i.object_id) = ?
             AND i.name IS NOT NULL
             ORDER BY i.name, ic.key_ordinal",
      [$this->schema, $table]
    );

    $indexes = [];
    foreach ($result as $row) {
      $indexName = $row['INDEX_NAME'];
      if (!isset($indexes[$indexName])) {
        $indexes[$indexName] = [
          'name' => $indexName,
          'columns' => [],
          'unique' => (bool) $row['IS_UNIQUE'],
          'primary' => (bool) $row['IS_PRIMARY'],
        ];
      }
      $indexes[$indexName]['columns'][] = $row['COLUMN_NAME'];
    }

    return array_values($indexes);
  }

  // =========================================================================
  // Transaction Support
  // =========================================================================

  public function savepoint(string $name): void
  {
    $this->execute("SAVE TRANSACTION {$name}");
  }

  public function rollbackToSavepoint(string $name): void
  {
    $this->execute("ROLLBACK TRANSACTION {$name}");
  }

  public function releaseSavepoint(string $name): void
  {
    // SQL Server doesn't have RELEASE SAVEPOINT - savepoints are released on commit
  }

  // =========================================================================
  // Batch Operations
  // =========================================================================

  public function batchInsert(string $table, array $columns, array $rows): int
  {
    if (empty($rows)) {
      return 0;
    }

    $totalInserted = 0;

    // Explicit values for an IDENTITY column need IDENTITY_INSERT
    $qualified = $this->quoteIdentifier($this->schema) . '.' . $this->quoteIdentifier($table);
    $identity = $this->query(
      "SELECT name FROM sys.identity_columns WHERE object_id = OBJECT_ID(?)",
      [$qualified]
    );
    $identityInsert = !empty($identity) && in_array($identity[0]['name'], $columns, true);
    if ($identityInsert) {
      $this->enableIdentityInsert($table);
    }

    $columnList = implode(', ', array_map([$this, 'quoteIdentifier'], $columns));
    $placeholderRow = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';

    try {
      foreach (array_chunk($rows, $this->insertChunkSize(count($columns))) as $batch) {
        $placeholders = implode(', ', array_fill(0, count($batch), $placeholderRow));
        $sql = "INSERT INTO {$qualified} ({$columnList}) VALUES {$placeholders}";

        $values = [];
        foreach ($batch as $row) {
          foreach ($columns as $col) {
            $values[] = $row[$col] ?? null;
          }
        }

        $totalInserted += $this->execute($sql, $values);
      }
    } finally {
      if ($identityInsert) {
        $this->disableIdentityInsert($table);
      }
    }

    return $totalInserted;
  }

  /**
   * Batch insert with MERGE (upsert) support.
   */
  public function batchUpsert(
    string $table,
    array $columns,
    array $rows,
    array $keyColumns,
    array $updateColumns = []
  ): int {
    if (empty($rows)) {
      return 0;
    }

    $quotedTable = $this->quoteIdentifier($this->schema) . '.' . $this->quoteIdentifier($table);
    $quotedColumns = array_map([$this, 'quoteIdentifier'], $columns);

    $totalAffected = 0;

    foreach ($rows as $row) {
      // Build MERGE statement for each row
      $matchConditions = [];
      foreach ($keyColumns as $col) {
        $matchConditions[] = "target." . $this->quoteIdentifier($col) . " = source." . $this->quoteIdentifier($col);
      }

      $sourceValues = [];
      $sourceColumns = [];
      $paramIndex = 0;
      foreach ($columns as $col) {
        $sourceColumns[] = "? as " . $this->quoteIdentifier($col);
        $sourceValues[] = $row[$col] ?? null;
      }

      $insertColumns = implode(', ', $quotedColumns);
      $insertValues = 'source.' . implode(', source.', $quotedColumns);

      $sql = "MERGE {$quotedTable} AS target
                    USING (SELECT " . implode(', ', $sourceColumns) . ") AS source
                    ON " . implode(' AND ', $matchConditions) . "
                    WHEN MATCHED THEN UPDATE SET ";

      $updates = [];
      $updateCols = empty($updateColumns) ? array_diff($columns, $keyColumns) : $updateColumns;
      foreach ($updateCols as $col) {
        $quoted = $this->quoteIdentifier($col);
        $updates[] = "target.{$quoted} = source.{$quoted}";
      }
      $sql .= implode(', ', $updates);

      $sql .= " WHEN NOT MATCHED THEN INSERT ({$insertColumns}) VALUES ({$insertValues});";

      $totalAffected += $this->execute($sql, $sourceValues);
    }

    return $totalAffected;
  }

  // =========================================================================
  // Streaming (SQL Server specific)
  // =========================================================================

  public function streamQuery(string $sql, array $params = []): \Generator
  {
    // SQL Server doesn't support cursor-based streaming via PDO like PostgreSQL
    // We'll use OFFSET/FETCH paging for memory efficiency
    $pageSize = 1000;
    $offset = 0;

    // Ensure the query has an ORDER BY for consistent paging
    if (stripos($sql, 'ORDER BY') === false) {
      throw new \RuntimeException(
        'Streaming queries in SQL Server require an ORDER BY clause for consistent results.'
      );
    }

    while (true) {
      $pagedSql = $sql . " OFFSET {$offset} ROWS FETCH NEXT {$pageSize} ROWS ONLY";
      $rows = $this->query($pagedSql, $params);

      if (empty($rows)) {
        break;
      }

      foreach ($rows as $row) {
        yield $row;
      }

      if (count($rows) < $pageSize) {
        break;
      }

      $offset += $pageSize;
    }
  }

  // =========================================================================
  // Utility Methods
  // =========================================================================

  public function quoteIdentifier(string $identifier): string
  {
    // SQL Server uses square brackets for identifier quoting
    $identifier = str_replace(']', ']]', $identifier);
    return '[' . $identifier . ']';
  }

  public function getPlaceholderStyle(): string
  {
    return 'positional'; // SQL Server uses ? placeholders with PDO
  }

  /**
   * Get the current schema.
   */
  public function getCurrentSchema(): string
  {
    return $this->schema;
  }

  /**
   * Get SQL Server version.
   */
  public function getServerVersion(): string
  {
    $result = $this->query("SELECT @@VERSION as version");
    return $result[0]['version'] ?? '';
  }

  /**
   * Get all available schemas.
   */
  public function getSchemas(): array
  {
    $result = $this->query(
      "SELECT SCHEMA_NAME 
             FROM INFORMATION_SCHEMA.SCHEMATA 
             WHERE SCHEMA_NAME NOT IN ('guest', 'INFORMATION_SCHEMA', 'sys')
             ORDER BY SCHEMA_NAME"
    );
    return array_column($result, 'SCHEMA_NAME');
  }

  public function reserveIds(string $table, int $next): void
  {
    $qualified = $this->quoteIdentifier($this->schema) . '.' . $this->quoteIdentifier($table);
    $identity = $this->query("SELECT IDENT_CURRENT(?) AS cur, IDENT_SEED(?) AS seed", [$qualified, $qualified])[0];
    if ($identity['cur'] === null) {
      return; // No identity column
    }

    // RESEED n: the next ID is n + 1 once the column has handed out a value, but n itself while
    // it never has. sys.identity_columns.last_value would say which, but lags behind recent inserts;
    // "no rows and still at the seed" means never used (a table emptied by DELETE wastes one ID, harmless)
    $current = (int) $identity['cur'];
    $neverUsed = $current === (int) $identity['seed']
      && !$this->query("SELECT TOP 1 1 AS found FROM {$qualified}");

    $nextId = $neverUsed ? $current : $current + 1;
    if ($nextId >= $next) {
      return; // Already far enough; RESEED could move it backwards
    }

    $reseed = $neverUsed ? $next : $next - 1;
    $this->getPdo()->exec("DBCC CHECKIDENT ({$this->quote($qualified)}, RESEED, {$reseed})");
  }

  /**
   * Enable IDENTITY_INSERT for a table.
   *
   * Uses PDO::exec (direct execution): through a prepared statement (sp_prepexec)
   * the SET reverts as soon as that call ends.
   */
  public function enableIdentityInsert(string $table): void
  {
    $quotedTable = $this->quoteIdentifier($this->schema) . '.' . $this->quoteIdentifier($table);
    $this->getPdo()->exec("SET IDENTITY_INSERT {$quotedTable} ON");
  }

  /**
   * Disable IDENTITY_INSERT for a table.
   */
  public function disableIdentityInsert(string $table): void
  {
    $quotedTable = $this->quoteIdentifier($this->schema) . '.' . $this->quoteIdentifier($table);
    $this->getPdo()->exec("SET IDENTITY_INSERT {$quotedTable} OFF");
  }

  // =========================================================================
  // Helper Methods
  // =========================================================================

  protected function buildColumnSchema(array $columnData): ColumnSchema
  {
    return new ColumnSchema(
      name: $columnData['COLUMN_NAME'],
      type: $this->normalizeType($columnData['DATA_TYPE']),
      nativeType: $columnData['DATA_TYPE'],
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
      isPrimaryKey: ($columnData['IS_PRIMARY_KEY'] ?? 'NO') === 'YES',
      isAutoIncrement: ($columnData['IS_IDENTITY'] ?? 0) == 1,
      isUnique: false,
      comment: null,
      extra: []
    );
  }

  /**
   * Normalize SQL Server data type to a standard type.
   */
  private function normalizeType(string $sqlsrvType): string
  {
    $typeMap = [
      'int' => 'integer',
      'bigint' => 'bigint',
      'smallint' => 'smallint',
      'tinyint' => 'tinyint',
      'bit' => 'boolean',
      'decimal' => 'decimal',
      'numeric' => 'decimal',
      'money' => 'decimal',
      'smallmoney' => 'decimal',
      'float' => 'float',
      'real' => 'float',
      'char' => 'char',
      'varchar' => 'varchar',
      'nchar' => 'char',
      'nvarchar' => 'varchar',
      'text' => 'text',
      'ntext' => 'text',
      'binary' => 'blob',
      'varbinary' => 'blob',
      'image' => 'blob',
      'date' => 'date',
      'datetime' => 'datetime',
      'datetime2' => 'datetime',
      'smalldatetime' => 'datetime',
      'datetimeoffset' => 'datetime',
      'time' => 'time',
      'uniqueidentifier' => 'uuid',
      'xml' => 'text',
    ];

    return $typeMap[strtolower($sqlsrvType)] ?? $sqlsrvType;
  }
}
