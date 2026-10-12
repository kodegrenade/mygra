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

  private int $batchSize = 1000;
  private bool $dryRun = false;
  private array $options = [];

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

      $where = $schema->where;
      if ($this->options['resume'] ?? false) {
        $where = $this->resumeWhere($schema, $where);
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

      // Stream rows from source
      foreach ($this->source->streamQuery($query) as $row) {
        // Only transform/validate errors are row-level; insert errors fail the migration
        [$transformed, $errorMsg] = $this->prepareRow($row, $fieldMap, $schema);

        if ($errorMsg !== null) {
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

          continue; // Skip this row
        }

        $batch[] = $transformed;

        // Flush batch when full
        if (count($batch) >= $this->batchSize) {
          $inserted = $this->flushBatch($schema->targetTable, $batch);
          $insertedCount += $inserted;
          $processedCount += count($batch);
          $batch = [];

          // Report progress
          $this->reportProgress($processedCount, $totalRows, "Processing...");
        }
      }

      // Flush remaining batch
      if (!empty($batch)) {
        $inserted = $this->flushBatch($schema->targetTable, $batch);
        $insertedCount += $inserted;
        $processedCount += count($batch);
      }

      if ($this->target->inTransaction()) {
        $this->target->commit();
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

    $sourceRows = $invalidRows = 0;
    $sourceSum = 0;
    foreach ($this->source->streamQuery($this->buildSelectQuery($schema, $schema->where)) as $row) {
      $sourceRows++;
      [$transformed, $errorMsg] = $this->prepareRow($row, $fieldMap, $schema);
      if ($errorMsg !== null) {
        $invalidRows++;
        continue;
      }
      $sourceSum = $this->addRowHash($sourceSum, $transformed, $columns);
    }

    $quotedColumns = implode(', ', array_map([$this->target, 'quoteIdentifier'], $columns));
    $sql = "SELECT {$quotedColumns} FROM {$this->target->quoteIdentifier($schema->targetTable)}";
    // SQL Server's OFFSET paging requires an ORDER BY
    $pk = $this->target->getPrimaryKey($schema->targetTable);
    if ($pk) {
      $sql .= " ORDER BY " . implode(', ', array_map([$this->target, 'quoteIdentifier'], (array) $pk));
    }

    $targetRows = 0;
    $targetSum = 0;
    foreach ($this->target->streamQuery($sql) as $row) {
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
  private function prepareRow(array $row, array $fieldMap, SchemaConfig $schema): array
  {
    try {
      $transformed = $this->pipeline->process($row, $fieldMap);

      if (!empty($schema->validations)) {
        $validation = $this->validator->validateRow($transformed, $schema->validations);
        if (!$validation->isValid()) {
          return [null, "Validation failed: " . $validation->getFormattedErrors()];
        }
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
  private function resumeWhere(SchemaConfig $schema, ?string $where): ?string
  {
    $pk = $this->source->getPrimaryKey($schema->sourceTable);
    $targetPk = is_string($pk) ? ($schema->fields[$pk] ?? null) : null;

    if ($targetPk === null || isset($schema->transforms[$targetPk])) {
      throw new \RuntimeException(
        "Resume needs a single-column source primary key mapped unchanged to the target (schema '{$schema->name}')"
      );
    }

    $last = $this->target->query(
      "SELECT MAX({$this->target->quoteIdentifier($targetPk)}) AS last_key FROM {$this->target->quoteIdentifier($schema->targetTable)}"
    )[0]['last_key'] ?? null;

    if ($last === null) {
      return $where;
    }

    $this->logger->info("Resuming {$schema->name} after {$pk} = {$last}");
    $condition = "{$schema->sourceTable}.{$pk} > " . (is_int($last) ? $last : $this->source->quote((string) $last));

    return $where ? "({$where}) AND {$condition}" : $condition;
  }
}
