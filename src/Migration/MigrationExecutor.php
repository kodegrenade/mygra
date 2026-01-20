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
use Mygra\Transaction\TransactionManager;

/**
 * Migration Executor
 * 
 * The core engine that executes database migrations.
 * Handles data extraction, transformation, and loading with
 * support for batching, streaming, and transaction management.
 */
class MigrationExecutor
{
  private AdapterInterface $source;
  private AdapterInterface $target;
  private TransformPipeline $pipeline;
  private ValidationRunner $validator;
  private ?TransactionManager $transactionManager = null;
  private LoggerInterface $logger;

  private int $batchSize = 1000;
  private bool $dryRun = false;
  private bool $useTransactions = true;
  private int $savepointInterval = 10000;
  private array $options = [];

  /** @var callable|null */
  private $progressCallback = null;
  public function __construct(
    AdapterInterface $source,
    AdapterInterface $target,
    ?TransformPipeline $pipeline = null,
    ?ValidationRunner $validator = null,
    ?TransactionManager $transactionManager = null,
    ?LoggerInterface $logger = null
  ) {
    $this->source = $source;
    $this->target = $target;
    $this->pipeline = $pipeline ?? TransformPipeline::withDefaults();
    $this->validator = $validator ?? ValidationRunner::withDefaults();
    $this->transactionManager = $transactionManager ?? new TransactionManager($this->target, null, $logger);
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
   * Enable/disable transaction wrapping.
   */
  public function setUseTransactions(bool $use): self
  {
    $this->useTransactions = $use;
    return $this;
  }

  /**
   * Set the interval for creating savepoints (in rows).
   */
  public function setSavepointInterval(int $interval): self
  {
    $this->savepointInterval = $interval;
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
      // Ensure target table exists
      if ($this->shouldCreateTarget($schema)) {
        $this->ensureTargetTableExists($schema);
      }

      // Build the SELECT query
      $query = $this->buildSelectQuery($schema);
      $this->logger->debug("Query: {$query}");

      // Get total count for progress tracking
      $totalRows = $this->source->getRowCount($schema->sourceTable, $schema->where);
      $this->logger->info("Total rows to migrate: {$totalRows}");
      $result->setTotalRows($totalRows);

      // Configure field transformations
      $this->configureTransforms($schema);

      // Begin transaction if enabled
      if ($this->useTransactions && !$this->dryRun) {
        $this->transactionManager->begin();
      }

      $processedCount = 0;
      $insertedCount = 0;
      $errorCount = 0;
      $batch = [];
      $savepointCount = 0;

      // Stream rows from source
      foreach ($this->source->streamQuery($query) as $row) {
        try {
          // Transform the row
          $transformed = $this->pipeline->process($row, $schema->fields);

          // Validate the transformed row
          if (!empty($schema->validations)) {
            $validation = $this->validator->validateRow($transformed, $schema->validations);
            if (!$validation->isValid()) {
              $errorCount++;
              $errorMsg = "Validation failed: " . $validation->getFormattedErrors();
              $result->addError([
                'row' => $row,
                'error' => $errorMsg,
              ]);
              $this->logger->warning("Row validation error: {$errorMsg}");

              $onError = $schema->getOption('on_error', $this->options['on_error'] ?? 'skip');
              if ($onError === 'abort') {
                throw new \Exception("Migration aborted due to validation error: {$errorMsg}");
              }

              continue; // Skip this row
            }
          }

          $batch[] = $transformed;

          // Flush batch when full
          if (count($batch) >= $this->batchSize) {
            $inserted = $this->flushBatch($schema->targetTable, $batch);

            // Record batch in journal if primary key is available
            $this->recordBatchInJournal($schema, $batch);

            $insertedCount += $inserted;
            $processedCount += count($batch);
            $batch = [];

            // Report progress
            $this->reportProgress($processedCount, $totalRows, "Processing...");

            // Create savepoint at intervals
            if (
              $this->useTransactions && !$this->dryRun &&
              $processedCount % $this->savepointInterval === 0
            ) {
              $this->transactionManager->checkpoint();
            }
          }
        } catch (\Exception $e) {
          $errorCount++;
          $result->addError([
            'row' => $row,
            'error' => $e->getMessage(),
          ]);
          $this->logger->warning("Row error: {$e->getMessage()}");
        }
      }

      // Flush remaining batch
      if (!empty($batch)) {
        $inserted = $this->flushBatch($schema->targetTable, $batch);
        $this->recordBatchInJournal($schema, $batch);
        $insertedCount += $inserted;
        $processedCount += count($batch);
      }

      // Commit transaction
      if ($this->useTransactions && !$this->dryRun) {
        $this->transactionManager->commit();
      }

      $result->setSuccess(true);
      $result->setProcessedRows($processedCount);
      $result->setInsertedRows($insertedCount);
      $result->setErrorCount($errorCount);
    } catch (\Exception $e) {
      // Rollback on error
      if ($this->useTransactions && !$this->dryRun) {
        $this->transactionManager->rollback();
      }

      $result->setSuccess(false);
      $result->setError($e->getMessage());
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
   * Build the SELECT query for extracting data.
   */
  private function buildSelectQuery(SchemaConfig $schema): string
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
    if ($schema->where) {
      $sql .= " WHERE {$schema->where}";
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
    foreach ($schema->transforms as $field => $transforms) {
      $this->pipeline->forField($field, $transforms);
    }
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
    return $this->target->batchInsert($table, $columns, $rows);
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
    $targetTableSchema = $converter->convert($sourceTableSchema, $schema);

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
   * Record a batch of inserts in the transaction journal.
   */
  private function recordBatchInJournal(SchemaConfig $schema, array $batch): void
  {
    if (!$this->transactionManager->getJournal() || empty($batch)) {
      return;
    }

    // Try to determine primary key from target table
    $targetSchema = $this->target->getTableSchema($schema->targetTable);
    $pk = $targetSchema->primaryKey;

    if (!$pk) {
      return; // No primary key, can't reliably journal for rollback
    }

    $pkCols = is_array($pk) ? $pk : [$pk];
    $primaryKeys = [];

    foreach ($batch as $row) {
      $keyData = [];
      foreach ($pkCols as $col) {
        if (!isset($row[$col])) {
          return; // PK column missing in row, abort journaling for this batch
        }
        $keyData[$col] = $row[$col];
      }
      $primaryKeys[] = $keyData;
    }

    $this->transactionManager->recordBatch($schema->targetTable, $primaryKeys);
  }

  /**
   * Recover from a failed/interrupted migration using the journal.
   */
  public function recovery(): void
  {
    if (!$this->transactionManager->needsRecovery()) {
      $this->logger->info("No recovery needed.");
      return;
    }

    $entries = $this->transactionManager->getJournal()->readEntries();
    $this->logger->info("Starting recovery from journal (" . count($entries) . " entries)...");

    foreach (array_reverse($entries) as $entry) {
      if ($entry['type'] === 'BATCH_INSERT') {
        $table = $entry['table'];
        $keys = $entry['keys'];

        $this->logger->info("Undoing batch insert in table '{$table}' (" . count($keys) . " rows)...");

        foreach ($keys as $keyData) {
          $conditions = [];
          foreach ($keyData as $col => $val) {
            $quotedVal = is_string($val) ? "'{$val}'" : $val;
            $conditions[] = "{$this->target->quoteIdentifier($col)} = {$quotedVal}";
          }
          $sql = "DELETE FROM {$this->target->quoteIdentifier($table)} WHERE " . implode(' AND ', $conditions);
          $this->target->execute($sql);
        }
      }
    }

    $this->transactionManager->getJournal()->complete();
    $this->logger->info("Recovery completed successfully.");
  }
}
