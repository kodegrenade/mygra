<?php

declare(strict_types=1);

namespace Mygra\Migration;

use Mygra\Adapters\AdapterInterface;
use Mygra\Config\SchemaConfig;
use Mygra\Transform\TransformPipeline;
use Mygra\Logger\LoggerInterface;
use Mygra\Logger\NullLogger;
use Mygra\Schema\SchemaConverter;
use Mygra\Validation\ValidationRunner;

/**
 * Migration Executor
 * 
 * The core engine that executes database migrations.
 * Handles data extraction, transformation, and loading with
 * support for batching, streaming, resuming, and transaction modes:
 *   per_batch (default): each batch commits on its own; reruns can resume
 *   single: the whole schema is one transaction; any failure leaves nothing behind
 */
class MigrationExecutor
{
  private AdapterInterface $source;
  private AdapterInterface $target;
  private TransformPipeline $pipeline;
  private ValidationRunner $validator;
  private LoggerInterface $logger;

  private const OFFSETS_TABLE = 'mygra_offsets';

  private int $batchSize = 1000;
  private bool $dryRun = false;
  private array $options = [];

  /** @var array<string, SchemaConfig> All schemas, so references can find their parent */
  private array $schemas = [];

  /** @var array<string, int> schema name => ID offset (cache; also holds dry-run offsets) */
  private array $offsets = [];

  /** @var callable|null */
  private $progressCallback = null;
  public function __construct(
    AdapterInterface $source,
    AdapterInterface $target,
    ?TransformPipeline $pipeline = null,
    ?ValidationRunner $validator = null,
    ?LoggerInterface $logger = null
  ) {
    $this->source = $source;
    $this->target = $target;
    $this->pipeline = $pipeline ?? TransformPipeline::withDefaults();
    $this->validator = $validator ?? ValidationRunner::withDefaults();
    $this->logger = $logger ?? new NullLogger();
  }

  /**
   * Set batch size for processing.
   */
  public function setBatchSize(int $size): self
  {
    $this->batchSize = max(1, $size);
    return $this;
  }

  /**
   * Enable/disable dry run mode.
   */
  public function setDryRun(bool $dryRun): self
  {
    $this->dryRun = $dryRun;
    return $this;
  }

  /**
   * Make all configured schemas known, so `references` can resolve their parent schema.
   *
   * @param SchemaConfig[] $schemas
   */
  public function setSchemas(array $schemas): self
  {
    foreach ($schemas as $schema) {
      $this->schemas[$schema->name] = $schema;
    }
    return $this;
  }

  /**
   * Set progress callback for tracking.
   *
   * @param callable $callback Function(int $processed, int $total, string $status)
   */
  public function onProgress(callable $callback): self
  {
    $this->progressCallback = $callback;
    return $this;
  }

  /**
   * Get the transform pipeline.
   */
  public function getPipeline(): TransformPipeline
  {
    return $this->pipeline;
  }

  /**
   * Execute the migration for a schema.
   */
  public function execute(SchemaConfig $schema): MigrationResult
  {
    $result = new MigrationResult($schema->name);
    $startTime = microtime(true);

    $this->logger->info("Starting migration: {$schema->name}");
    $this->logger->info("Source: {$schema->sourceTable} -> Target: {$schema->targetTable}");

    try {
      $mode = $schema->getOption('transaction_mode', $this->options['transaction_mode'] ?? 'per_batch');
      if (!in_array($mode, ['per_batch', 'single'], true)) {
        throw new \InvalidArgumentException("Unknown transaction_mode '{$mode}' (expected per_batch or single)");
      }

      // Ensure target table exists
      if ($this->shouldCreateTarget($schema)) {
        $this->ensureTargetTableExists($schema);
      }

      // New IDs (remap_ids) and references must be settled before anything is read or written
      $ids = $this->idContext($schema, true);
      if ($ids['offset'] !== null) {
        $result->setMetadata('id_offset', $ids['offset']);
        $this->logger->info("{$schema->name}: new ID = old ID + {$ids['offset']}");
      }

      $where = $schema->where;
      if ($this->options['resume'] ?? false) {
        $where = $this->resumeWhere($schema, $where, $ids['offset']);
      }

      // Build the SELECT query
      $query = $this->buildSelectQuery($schema, $where);
      $this->logger->debug("Query: {$query}");

      // Get total count for progress tracking
      $totalRows = $this->source->getRowCount($schema->sourceTable, $where);
      $this->logger->info("Total rows to migrate: {$totalRows}");
      $result->setTotalRows($totalRows);

      // Configure field transformations
      $this->configureTransforms($schema);

      // single: one transaction for the schema; flushBatch commits per batch otherwise
      if ($mode === 'single' && !$this->dryRun) {
        $this->target->beginTransaction();
      }

      $processedCount = 0;
      $insertedCount = 0;
      $errorCount = 0;
      $batch = [];

      $onError = $schema->getOption('on_error', $this->options['on_error'] ?? 'skip');
      $fieldMap = $schema->fields + $this->joinFieldMap($schema->joins);

      // Row-level errors (transform, validation, missing reference) skip the row or abort the schema;
      // insert errors always fail the migration
      $rowError = function (array $row, string $errorMsg) use (&$errorCount, $result, $onError): void {
        $errorCount++;
        $result->addError([
          'row' => $row,
          'error' => $errorMsg,
        ]);
        $this->logger->warning("Row error: {$errorMsg}");

        // 'rollback' is the legacy name for abort, kept for existing configs
        if ($onError === 'abort' || $onError === 'rollback') {
          throw new \RuntimeException("Migration aborted on row error: {$errorMsg}");
        }
      };

      $load = function (array $batch) use ($schema, $ids, $rowError, &$insertedCount, &$processedCount): void {
        [$batch, $orphans] = $this->resolveReferences($batch, $ids['refs']);
        foreach ($orphans as [$row, $errorMsg]) {
          $rowError($row, $errorMsg);
        }
        $insertedCount += $this->flushBatch($schema->targetTable, $batch);
        $processedCount += count($batch);
      };

      // Stream rows from source
      foreach ($this->source->streamQuery($query) as $row) {
        [$transformed, $errorMsg] = $this->prepareRow($row, $fieldMap, $schema, $ids);

        if ($errorMsg !== null) {
          $rowError($row, $errorMsg);
          continue; // Skip this row
        }

        $batch[] = $transformed;

        // Flush batch when full
        if (count($batch) >= $this->batchSize) {
          $load($batch);
          $batch = [];

          // Report progress
          $this->reportProgress($processedCount, $totalRows, "Processing...");
        }
      }

      // Flush remaining batch
      if (!empty($batch)) {
        $load($batch);
      }

      if ($this->target->inTransaction()) {
        $this->target->commit();
      }

      if (!$this->dryRun) {
        $this->target->syncSequences($schema->targetTable);
      }

      $result->setSuccess(true);
      $result->setProcessedRows($processedCount);
      $result->setInsertedRows($insertedCount);
      $result->setErrorCount($errorCount);
    } catch (\Exception $e) {
      // single: undo the schema. per_batch: undo the failed batch; committed batches stay (rerun with resume)
      if ($this->target->inTransaction()) {
        $this->target->rollback();
      }

      // per_batch keeps committed rows; the app must still be able to insert after them
      if (!$this->dryRun) {
        try {
          $this->target->syncSequences($schema->targetTable);
        } catch (\Exception $syncError) {
          $this->logger->warning("Could not sync sequences: {$syncError->getMessage()}");
        }
      }

      $result->setSuccess(false);
      $result->setError($e->getMessage());
      $result->setErrorCount($errorCount ?? 0);
      $this->logger->error("Migration failed: {$e->getMessage()}");
    }

    $duration = microtime(true) - $startTime;
    $result->setDuration($duration);

    $this->logger->info(sprintf(
      "Migration %s. Processed: %d, Inserted: %d, Errors: %d, Duration: %.2fs",
      $result->isSuccess() ? 'completed' : 'failed',
      $result->getProcessedRows(),
      $result->getInsertedRows(),
      $result->getErrorCount(),
      $duration
    ));

    return $result;
  }

  /**
   * Compare source and target after a migration.
   *
   * The source side runs through the same query, transforms and validation as execute(),
   * so "expected" is exactly the rows a migration writes. Both sides are reduced to a row
   * count and an order-independent checksum of the mapped columns.
   *
   * @return array{source_rows: int, invalid_rows: int, expected_rows: int, target_rows: int, checksum_match: bool, match: bool}
   */
  public function verify(SchemaConfig $schema): array
  {
    $this->configureTransforms($schema);
    $fieldMap = $schema->fields + $this->joinFieldMap($schema->joins);
    $columns = array_values($fieldMap);
    $ids = $this->idContext($schema, false);

    $sourceRows = $invalidRows = 0;
    $sourceSum = 0;
    $pending = [];
    $hashPending = function () use (&$pending, &$invalidRows, &$sourceSum, $ids, $columns): void {
      [$kept, $orphans] = $this->resolveReferences($pending, $ids['refs']);
      $invalidRows += count($orphans);
      foreach ($kept as $row) {
        $sourceSum = $this->addRowHash($sourceSum, $row, $columns);
      }
      $pending = [];
    };

    foreach ($this->source->streamQuery($this->buildSelectQuery($schema, $schema->where)) as $row) {
      $sourceRows++;
      [$transformed, $errorMsg] = $this->prepareRow($row, $fieldMap, $schema, $ids);
      if ($errorMsg !== null) {
        $invalidRows++;
        continue;
      }
      $pending[] = $transformed;
      if (count($pending) >= $this->batchSize) {
        $hashPending();
      }
    }
    $hashPending();

    $quotedColumns = implode(', ', array_map([$this->target, 'quoteIdentifier'], $columns));
    $sql = "SELECT {$quotedColumns} FROM {$this->target->quoteIdentifier($schema->targetTable)}";
    $params = [];
    if ($ids['offset'] !== null) {
      // Merged into a table the app already used: only Mygra's ID range is ours to compare
      $range = $this->sourceKeyRange($schema);
      $sql .= " WHERE {$this->target->quoteIdentifier($ids['pk'])} BETWEEN ? AND ?";
      $params = [(int) $range['lo'] + $ids['offset'], (int) $range['hi'] + $ids['offset']];
    }
    // SQL Server's OFFSET paging requires an ORDER BY
    $pk = $this->target->getPrimaryKey($schema->targetTable);
    if ($pk) {
      $sql .= " ORDER BY " . implode(', ', array_map([$this->target, 'quoteIdentifier'], (array) $pk));
    }

    $targetRows = 0;
    $targetSum = 0;
    foreach ($this->target->streamQuery($sql, $params) as $row) {
      $targetRows++;
      $targetSum = $this->addRowHash($targetSum, $row, $columns);
    }

    $expectedRows = $sourceRows - $invalidRows;
    $checksumMatch = $sourceSum === $targetSum;

    return [
      'source_rows' => $sourceRows,
      'invalid_rows' => $invalidRows,
      'expected_rows' => $expectedRows,
      'target_rows' => $targetRows,
      'checksum_match' => $checksumMatch,
      'match' => $checksumMatch && $expectedRows === $targetRows,
    ];
  }

  /**
   * Transform and validate one source row.
   *
   * @return array{0: ?array, 1: ?string} [transformed row, error message]
   */
  private function prepareRow(array $row, array $fieldMap, SchemaConfig $schema, array $ids): array
  {
    try {
      $transformed = $this->pipeline->process($row, $fieldMap);

      if (!empty($schema->validations)) {
        $validation = $this->validator->validateRow($transformed, $schema->validations);
        if (!$validation->isValid()) {
          return [null, "Validation failed: " . $validation->getFormattedErrors()];
        }
      }

      if ($ids['offset'] !== null && $transformed[$ids['pk']] !== null) {
        $transformed[$ids['pk']] = (int) $transformed[$ids['pk']] + $ids['offset'];
      }

      return [$transformed, null];
    } catch (\Exception $e) {
      return [null, $e->getMessage()];
    }
  }

  /**
   * Add a row's hash to an order-independent checksum (sum of 60-bit hashes, mod 2^61).
   */
  private function addRowHash(int $sum, array $row, array $columns): int
  {
    $values = array_map(fn($col) => self::normalizeValue($row[$col] ?? null), $columns);
    $hash = hexdec(substr(md5(json_encode($values)), 0, 15));
    return ($sum + $hash) % (1 << 61);
  }

  /**
   * Canonical string form, so drivers' formatting differences don't fail verification:
   * sqlsrv returns numbers as strings, pgsql returns real booleans, DATETIME2 pads ".0000000".
   */
  private static function normalizeValue(mixed $value): ?string
  {
    return match (true) {
      $value === null => null,
      is_bool($value) => $value ? '1' : '0',
      is_int($value), is_float($value), is_numeric($value) => (string) (0 + $value),
      default => preg_replace('/^(\d{4}-\d\d-\d\d[ T]\d\d:\d\d:\d\d)\.0*$/', '$1', (string) $value),
    };
  }

  /**
   * Build the SELECT query for extracting data.
   */
  private function buildSelectQuery(SchemaConfig $schema, ?string $where): string
  {
    $sourceTable = $schema->sourceTable;
    $fields = array_keys($schema->fields);

    // Build SELECT clause
    $selectFields = array_map(
      fn($field) => "{$sourceTable}.{$field}",
      $fields
    );

    // Add join fields
    $joinClauses = [];
    if ($schema->hasJoins()) {
      $this->buildJoins($sourceTable, $schema->joins, $selectFields, $joinClauses);
    }

    $selectClause = implode(', ', $selectFields);
    $joinClause = implode(' ', $joinClauses);

    $sql = "SELECT {$selectClause} FROM {$sourceTable} {$joinClause}";

    // Add WHERE clause
    if ($where) {
      $sql .= " WHERE {$where}";
    }

    // Deterministic order; SQL Server's OFFSET paging requires it
    $pk = $this->source->getPrimaryKey($sourceTable);
    if ($pk) {
      $sql .= " ORDER BY " . implode(', ', array_map(fn($col) => "{$sourceTable}.{$col}", (array) $pk));
    }

    return $sql;
  }

  /**
   * Build JOIN clauses recursively.
   */
  private function buildJoins(
    string $baseTable,
    array $joins,
    array &$selectFields,
    array &$joinClauses,
    int &$aliasCounter = 0
  ): void {
    foreach ($joins as $join) {
      $alias = $join['table'] . '_' . (++$aliasCounter);
      $joinType = $join['type'];
      $sourceKey = $join['source_key'];
      $targetKey = $join['target_key'];

      $clause = "{$joinType} JOIN {$join['table']} AS {$alias} " .
        "ON {$baseTable}.{$sourceKey} = {$alias}.{$targetKey}";

      if (!empty($join['where'])) {
        $clause .= " AND {$join['where']}";
      }

      $joinClauses[] = $clause;

      // Add fields from this join
      foreach ($join['fields'] as $srcField => $targetField) {
        $selectFields[] = "{$alias}.{$srcField} AS {$targetField}";
      }

      // Handle nested joins
      if (!empty($join['join'])) {
        $this->buildJoins($alias, $join['join'], $selectFields, $joinClauses, $aliasCounter);
      }
    }
  }

  /**
   * Configure transformations from schema config.
   */
  private function configureTransforms(SchemaConfig $schema): void
  {
    // Pipeline is shared across schemas; drop the previous schema's field transforms
    $this->pipeline->clearFieldTransformers();
    foreach ($schema->transforms as $field => $transforms) {
      $this->pipeline->forField($field, $transforms);
    }
  }

  /**
   * All table names referenced by (nested) joins.
   */
  private function joinTables(array $joins): array
  {
    $tables = [];
    foreach ($joins as $join) {
      $tables = [...$tables, $join['table'], ...$this->joinTables($join['join'])];
    }
    return array_unique($tables);
  }

  /**
   * Map join fields (selected AS their target name) through the pipeline unchanged.
   */
  private function joinFieldMap(array $joins): array
  {
    $map = [];
    foreach ($joins as $join) {
      foreach ($join['fields'] as $targetField) {
        $map[$targetField] = $targetField;
      }
      $map += $this->joinFieldMap($join['join']);
    }
    return $map;
  }

  /**
   * Flush a batch of rows to the target database.
   */
  private function flushBatch(string $table, array $rows): int
  {
    if (empty($rows)) {
      return 0;
    }

    if ($this->dryRun) {
      $this->logger->debug("Dry run: Would insert " . count($rows) . " rows");
      return count($rows);
    }

    $columns = array_keys($rows[0]);

    // Inside a single-mode transaction already
    if ($this->target->inTransaction()) {
      return $this->target->batchInsert($table, $columns, $rows);
    }

    // per_batch: the batch commits atomically, so resume never sees half a batch
    $this->target->beginTransaction();
    $inserted = $this->target->batchInsert($table, $columns, $rows);
    $this->target->commit();
    return $inserted;
  }

  /**
   * Report progress to callback if set.
   */
  private function reportProgress(int $processed, int $total, string $status): void
  {
    if ($this->progressCallback !== null) {
      ($this->progressCallback)($processed, $total, $status);
    }
  }

  /**
   * Should the target table be created if missing?
   */
  private function shouldCreateTarget(SchemaConfig $schema): bool
  {
    return $schema->getOption('create_target', false) || ($this->options['auto_create'] ?? false);
  }

  /**
   * Ensure the target table exists, creating it if necessary.
   */
  private function ensureTargetTableExists(SchemaConfig $schema): void
  {
    $targetTables = $this->target->getTables();
    if (in_array($schema->targetTable, $targetTables)) {
      return;
    }

    $this->logger->info("Target table '{$schema->targetTable}' missing. Attempting to create...");

    if ($this->dryRun) {
      $this->logger->info("Dry run: Would create table '{$schema->targetTable}'");
      return;
    }

    // Get source schema
    $sourceTableSchema = $this->source->getTableSchema($schema->sourceTable);

    // Convert to target schema
    $converter = new SchemaConverter();
    $joinSchemas = [];
    foreach ($this->joinTables($schema->joins) as $table) {
      $joinSchemas[$table] = $this->source->getTableSchema($table);
    }
    $targetTableSchema = $converter->convert($sourceTableSchema, $schema, $joinSchemas);

    // Generate SQL
    $driver = $this->target->getPdo()->getAttribute(\PDO::ATTR_DRIVER_NAME);
    $sql = $targetTableSchema->toCreateTableSQL($driver);

    $this->logger->debug("Create SQL: {$sql}");

    // Execute
    $this->target->execute($sql);
    $this->logger->info("Target table '{$schema->targetTable}' created successfully.");
  }

  /**
   * Set global options for the executor.
   */
  public function setOptions(array $options): self
  {
    $this->options = array_merge($this->options ?? [], $options);
    return $this;
  }

  /**
   * Restrict the source query to rows after the highest key already in the target.
   *
   * The target data is the checkpoint: batches commit atomically, so MAX(pk) is exactly
   * where the previous run stopped.
   */
  private function resumeWhere(SchemaConfig $schema, ?string $where, ?int $offset): ?string
  {
    $pk = $this->source->getPrimaryKey($schema->sourceTable);
    $targetPk = is_string($pk) ? ($schema->fields[$pk] ?? null) : null;

    if ($targetPk === null || isset($schema->transforms[$targetPk])) {
      throw new \RuntimeException(
        "Resume needs a single-column source primary key mapped unchanged to the target (schema '{$schema->name}')"
      );
    }

    $sql = "SELECT MAX({$this->target->quoteIdentifier($targetPk)}) AS last_key FROM {$this->target->quoteIdentifier($schema->targetTable)}";
    $params = [];
    if ($offset !== null) {
      // Only Mygra's own range counts: rows the app inserted meanwhile sit above it
      $range = $this->sourceKeyRange($schema);
      $sql .= " WHERE {$this->target->quoteIdentifier($targetPk)} BETWEEN ? AND ?";
      $params = [(int) $range['lo'] + $offset, (int) $range['hi'] + $offset];
    }
    $last = $this->target->query($sql, $params)[0]['last_key'] ?? null;
    if ($last !== null && $offset !== null) {
      $last = (int) $last - $offset;
    }

    if ($last === null) {
      return $where;
    }

    $this->logger->info("Resuming {$schema->name} after {$pk} = {$last}");
    $condition = "{$schema->sourceTable}.{$pk} > " . (is_int($last) ? $last : $this->source->quote((string) $last));

    return $where ? "({$where}) AND {$condition}" : $condition;
  }

  /**
   * Everything needed to give this schema new IDs and to rewrite its references.
   *
   * @param bool $prepare true while migrating: fix the offset on the first run and reserve the ID range
   * @return array{offset: ?int, pk: ?string, refs: array<string, array>}
   */
  private function idContext(SchemaConfig $schema, bool $prepare): array
  {
    $offset = null;
    $pk = null;
    if ($schema->getOption('remap_ids', false)) {
      $pk = $this->targetKeyColumn($schema);
      $offset = $prepare ? $this->prepareOffset($schema, $pk) : $this->requireOffset($schema->name, 'run migrate first');
    }

    $refs = [];
    foreach ($schema->references as $column => $ref) {
      $self = $ref['schema'] === $schema->name;
      $parent = $self ? $schema : ($this->schemas[$ref['schema']]
        ?? throw new \InvalidArgumentException("Schema '{$schema->name}' references unknown schema '{$ref['schema']}'"));

      $parentOffset = 0;
      if ($parent->getOption('remap_ids', false)) {
        $parentOffset = $self ? $offset : $this->requireOffset($parent->name, "migrate it before '{$schema->name}'");
      }

      // A self-reference may point at a row later in this same run, so check the source instead
      $refs[$column] = $ref + [
        'offset' => $parentOffset,
        'self' => $self,
        'db' => $self ? $this->source : $this->target,
        'table' => $self ? $parent->sourceTable : $parent->targetTable,
        'key' => $self
          ? $this->singleKey($this->source, $parent->sourceTable)
          : $this->singleKey($this->target, $parent->targetTable),
      ];
    }

    return ['offset' => $offset, 'pk' => $pk, 'refs' => $refs];
  }

  /**
   * Check each reference against its parent and rewrite it to the parent's new ID.
   * Rows whose reference has no parent are returned as orphans (on_missing: error)
   * or kept with the reference set to NULL (on_missing: null).
   *
   * @return array{0: array, 1: list<array{0: array, 1: string}>} [rows to load, orphans with messages]
   */
  private function resolveReferences(array $rows, array $refs): array
  {
    $orphans = [];

    foreach ($refs as $column => $ref) {
      // Offset 0 = parent keeps its IDs; leave values (possibly string keys) untouched
      $toNew = fn($v) => $ref['offset'] ? (int) $v + $ref['offset'] : $v;
      $toOld = fn($v) => $ref['offset'] ? (int) $v - $ref['offset'] : $v;
      $old = array_values(array_unique(array_filter(array_column($rows, $column), fn($v) => $v !== null)));

      // Old IDs whose parent exists; looked up per batch, in chunks below SQL Server's 2100-parameter limit
      $found = [];
      foreach (array_chunk($old, 1000) as $chunk) {
        $lookup = $ref['self'] ? $chunk : array_map($toNew, $chunk);
        $placeholders = implode(', ', array_fill(0, count($lookup), '?'));
        $key = $ref['db']->quoteIdentifier($ref['key']);
        $sql = "SELECT {$key} AS k FROM {$ref['db']->quoteIdentifier($ref['table'])} WHERE {$key} IN ({$placeholders})";
        foreach ($ref['db']->query($sql, $lookup) as $hit) {
          $found[(string) ($ref['self'] ? $hit['k'] : $toOld($hit['k']))] = true;
        }
      }

      foreach ($rows as $i => $row) {
        $value = $row[$column] ?? null;
        if ($value === null) {
          continue;
        }
        if (isset($found[(string) $value])) {
          $rows[$i][$column] = $toNew($value);
        } elseif ($ref['on_missing'] === 'null') {
          $rows[$i][$column] = null;
        } else {
          $orphans[] = [$row, "{$column} {$value} has no matching row in {$ref['schema']}"];
          unset($rows[$i]);
        }
      }
    }

    return [array_values($rows), $orphans];
  }

  /**
   * Fix this schema's offset on its first run (new IDs start right after the target's highest),
   * store it, and reserve the ID range so a live app cannot take IDs Mygra will insert.
   */
  private function prepareOffset(SchemaConfig $schema, string $targetPk): int
  {
    $range = $this->sourceKeyRange($schema);
    $offset = $this->storedOffset($schema->name);

    if ($offset === null) {
      $targetMax = $this->target->query(
        "SELECT MAX({$this->target->quoteIdentifier($targetPk)}) AS m FROM {$this->target->quoteIdentifier($schema->targetTable)}"
      )[0]['m'] ?? null;
      $offset = $range['lo'] === null ? 0 : (int) ($targetMax ?? 0) - (int) $range['lo'] + 1;
      $this->offsets[$schema->name] = $offset;

      if (!$this->dryRun) {
        $this->ensureOffsetsTable();
        $this->target->execute(
          "INSERT INTO {$this->target->quoteIdentifier(self::OFFSETS_TABLE)} ({$this->target->quoteIdentifier('schema_name')}, {$this->target->quoteIdentifier('id_offset')}) VALUES (?, ?)",
          [$schema->name, $offset]
        );
      }
    }

    if (!$this->dryRun && $range['hi'] !== null) {
      $this->target->reserveIds($schema->targetTable, (int) $range['hi'] + $offset + 1);
    }

    return $offset;
  }

  private function storedOffset(string $schemaName): ?int
  {
    if (!isset($this->offsets[$schemaName]) && in_array(self::OFFSETS_TABLE, $this->target->getTables(), true)) {
      $row = $this->target->query(
        "SELECT {$this->target->quoteIdentifier('id_offset')} AS id_offset FROM {$this->target->quoteIdentifier(self::OFFSETS_TABLE)} WHERE {$this->target->quoteIdentifier('schema_name')} = ?",
        [$schemaName]
      )[0] ?? null;
      if ($row !== null) {
        $this->offsets[$schemaName] = (int) $row['id_offset'];
      }
    }
    return $this->offsets[$schemaName] ?? null;
  }

  private function requireOffset(string $schemaName, string $hint): int
  {
    return $this->storedOffset($schemaName)
      ?? throw new \RuntimeException("Schema '{$schemaName}' has no ID offset yet; {$hint}");
  }

  private function ensureOffsetsTable(): void
  {
    if (in_array(self::OFFSETS_TABLE, $this->target->getTables(), true)) {
      return;
    }
    $q = fn($name) => $this->target->quoteIdentifier($name);
    $this->target->execute(
      "CREATE TABLE {$q(self::OFFSETS_TABLE)} ({$q('schema_name')} VARCHAR(255) NOT NULL PRIMARY KEY, {$q('id_offset')} BIGINT NOT NULL)"
    );
  }

  /**
   * Remove Mygra's ID bookkeeping from the target. Call only once every schema has verified.
   *
   * @return bool Whether there was anything to remove
   */
  public function dropOffsets(): bool
  {
    $this->offsets = [];
    if (!in_array(self::OFFSETS_TABLE, $this->target->getTables(), true)) {
      return false;
    }
    $this->target->execute("DROP TABLE {$this->target->quoteIdentifier(self::OFFSETS_TABLE)}");
    return true;
  }

  /**
   * The target column holding this schema's single-column source primary key.
   */
  private function targetKeyColumn(SchemaConfig $schema): string
  {
    $pk = $this->singleKey($this->source, $schema->sourceTable);
    return $schema->fields[$pk]
      ?? throw new \InvalidArgumentException("remap_ids needs the primary key '{$pk}' in the fields of '{$schema->name}'");
  }

  private function singleKey(AdapterInterface $db, string $table): string
  {
    $pk = $db->getPrimaryKey($table);
    if (!is_string($pk)) {
      throw new \InvalidArgumentException("ID mapping needs a single-column primary key on '{$table}'");
    }
    return $pk;
  }

  /**
   * @return array{lo: mixed, hi: mixed} lowest and highest source primary key (NULL when empty)
   */
  private function sourceKeyRange(SchemaConfig $schema): array
  {
    $pk = $this->source->quoteIdentifier($this->singleKey($this->source, $schema->sourceTable));
    return $this->source->query(
      "SELECT MIN({$pk}) AS lo, MAX({$pk}) AS hi FROM {$this->source->quoteIdentifier($schema->sourceTable)}"
    )[0];
  }
}
