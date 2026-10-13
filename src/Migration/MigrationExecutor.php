<?php

declare(strict_types=1);

namespace Mygra\Migration;

use Mygra\Adapters\AdapterInterface;
use Mygra\Config\SchemaConfig;
use Mygra\Schema\ColumnSchema;
use Mygra\Schema\SchemaConverter;
use Mygra\Schema\TableSchema;
use Mygra\Transform\TransformPipeline;
use Mygra\Validation\ValidationRunner;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Migrates one schema at a time: read source rows (SourceReader), prepare them (RowPreparer),
 * map IDs and references (IdMapper), and write them in batches.
 *
 * Transaction modes:
 *   per_batch (default): each batch commits on its own; a rerun can resume after the last one
 *   single: the whole schema is one transaction; any failure leaves nothing behind
 */
final class MigrationExecutor
{
  private int $batchSize = 1000;
  private bool $dryRun = false;
  private RunOptions $options;

  /** @var array<string, SchemaConfig> All schemas, so references can find their parent */
  private array $schemas = [];

  private readonly RowPreparer $preparer;
  private readonly LoggerInterface $logger;

  /** Built on first use from the current settings; reset when they change */
  private ?OffsetStore $offsets = null;
  private ?IdMapper $mapper = null;

  /** @var (callable(int $processed, int $total): void)|null */
  private $progress = null;

  public function __construct(
    private readonly AdapterInterface $source,
    private readonly AdapterInterface $target,
    ?TransformPipeline $pipeline = null,
    ?ValidationRunner $validator = null,
    ?LoggerInterface $logger = null,
  ) {
    $this->preparer = new RowPreparer($pipeline ?? TransformPipeline::withDefaults(), $validator ?? ValidationRunner::withDefaults());
    $this->logger = $logger ?? new NullLogger();
    $this->options = new RunOptions();
  }

  public function setBatchSize(int $size): self
  {
    $this->batchSize = max(1, $size);
    return $this->reset();
  }

  public function setDryRun(bool $dryRun): self
  {
    $this->dryRun = $dryRun;
    return $this->reset();
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
    return $this->reset();
  }

  /**
   * @param array<string, mixed> $options create_target, on_error, transaction_mode, resume, upsert
   */
  public function setOptions(array $options): self
  {
    $this->options = RunOptions::fromArray($options, $this->options);
    return $this;
  }

  /**
   * @param callable(int $processed, int $total): void $callback Called after each batch
   */
  public function onProgress(callable $callback): self
  {
    $this->progress = $callback;
    return $this;
  }

  public function execute(SchemaConfig $schema): MigrationResult
  {
    $result = new MigrationResult($schema->name);
    $startTime = microtime(true);
    $options = $this->options->forSchema($schema);
    $errorCount = 0;

    $this->logger->info("Migrating {$schema->name}: {$schema->sourceTable} -> {$schema->targetTable}");

    try {
      if ($options->createTarget) {
        $this->createTargetTable($schema);
      }

      // New IDs and references must be settled before anything is read or written
      $ids = $this->mapper()->context($schema, true);
      if ($ids->offset !== null) {
        $result->setMetadata('id_offset', $ids->offset);
      }
      if ($ids->kind === 'uuid') {
        $result->setMetadata('id_uuid', "UUIDv5 of '{$schema->sourceTable}:<old id>'");
      }

      $after = $options->resume ? $this->mapper()->lastMigratedKey($schema, $ids) : null;
      if ($after !== null) {
        $this->logger->info("Resuming {$schema->name} after key {$after}");
      }

      $reader = $this->reader();
      $totalRows = $reader->count($schema, $after);
      $result->setTotalRows($totalRows);
      $this->preparer->use($schema);

      // Upsert (final sync): rows already in the target are updated instead of failing on their key
      $upsertKeys = $options->upsert
        ? (array) ($this->target->getPrimaryKey($schema->targetTable)
          ?? throw new \InvalidArgumentException("Upsert needs a primary key on target table '{$schema->targetTable}'"))
        : null;

      if ($options->transactionMode === 'single' && !$this->dryRun) {
        $this->target->beginTransaction();
      }

      $written = $processed = $replaced = 0;

      // Row errors (transform, validation, missing reference) skip the row or abort the schema;
      // write errors always fail it
      $rowError = function (array $row, string $message) use (&$errorCount, $result, $options): void {
        $errorCount++;
        $result->addError(['row' => $row, 'error' => $message]);
        $this->logger->warning("Row error: {$message}");
        if ($options->abortsOnRowError()) {
          throw new \RuntimeException("Migration aborted on row error: {$message}");
        }
      };

      $load = function (array $batch) use ($schema, $ids, $rowError, $upsertKeys, &$written, &$processed, &$replaced): void {
        [$batch, $orphans, $replacedInBatch] = $this->mapper()->resolve($batch, $ids);
        $replaced += $replacedInBatch;
        foreach ($orphans as [$row, $message]) {
          $rowError($row, $message);
        }
        $written += $this->write($schema->targetTable, $batch, $upsertKeys);
        $processed += count($batch);
      };

      $batch = [];
      foreach ($reader->rows($schema, $after) as $row) {
        [$prepared, $error] = $this->preparer->prepare($row, $ids);
        if ($error !== null) {
          $rowError($row, $error);
          continue;
        }

        $batch[] = $prepared;
        if (count($batch) >= $this->batchSize) {
          $load($batch);
          $batch = [];
          if ($this->progress) {
            ($this->progress)($processed, $totalRows);
          }
        }
      }
      if ($batch) {
        $load($batch);
      }

      if ($this->target->inTransaction()) {
        $this->target->commit();
      }
      if (!$this->dryRun) {
        $this->target->syncSequences($schema->targetTable);
      }

      if ($replaced > 0) {
        $result->addWarning("Dead IDs inside text replaced: {$replaced} (on_missing: replace)");
      }

      $result->setSuccess(true);
      $result->setInsertedRows($written);
    } catch (\Throwable $e) {
      // single: undo the schema. per_batch: undo the failed batch; committed batches stay (rerun with --resume)
      if ($this->target->inTransaction()) {
        $this->target->rollback();
      }

      // per_batch keeps committed rows; the application must still be able to insert after them
      if (!$this->dryRun) {
        try {
          $this->target->syncSequences($schema->targetTable);
        } catch (\Throwable $syncError) {
          $this->logger->warning("Could not sync sequences: {$syncError->getMessage()}");
        }
      }

      $result->setSuccess(false);
      $result->setError($e->getMessage());
      $this->logger->error("{$schema->name} failed: {$e->getMessage()}");
    }

    $result->setErrorCount($errorCount);
    $result->setDuration(microtime(true) - $startTime);
    $this->logger->info(sprintf(
      '%s %s: %d written, %d row errors, %.2fs',
      $schema->name,
      $result->isSuccess() ? 'done' : 'failed',
      $result->getInsertedRows(),
      $errorCount,
      $result->getDuration(),
    ));

    return $result;
  }

  public function verify(SchemaConfig $schema): VerificationResult
  {
    return (new Verifier($this->target, $this->reader(), $this->preparer, $this->mapper(), $this->batchSize))->verify($schema);
  }

  /**
   * Remove Mygra's ID bookkeeping from the target (`mygra finish`).
   *
   * @return bool Whether there was anything to remove
   */
  public function dropOffsets(): bool
  {
    return $this->offsets()->drop();
  }

  /**
   * @param string[]|null $upsertKeys Key columns to upsert on; null = plain insert
   * @return int Rows written (inserted, or inserted/updated when upserting)
   */
  private function write(string $table, array $rows, ?array $upsertKeys): int
  {
    if (!$rows) {
      return 0;
    }
    if ($this->dryRun) {
      return count($rows);
    }

    $columns = array_keys($rows[0]);
    $write = function () use ($table, $columns, $rows, $upsertKeys): int {
      if ($upsertKeys === null) {
        return $this->target->batchInsert($table, $columns, $rows);
      }
      // Drivers count upserted rows differently (MySQL counts an update as 2); report rows written
      $this->target->batchUpsert($table, $columns, $rows, $upsertKeys);
      return count($rows);
    };

    // Inside a single-mode transaction already
    if ($this->target->inTransaction()) {
      return $write();
    }

    // per_batch: the batch commits atomically, so resume never sees half a batch
    $this->target->beginTransaction();
    $written = $write();
    $this->target->commit();
    return $written;
  }

  private function createTargetTable(SchemaConfig $schema): void
  {
    if (in_array($schema->targetTable, $this->target->getTables(), true)) {
      return;
    }
    if ($this->dryRun) {
      $this->logger->info("Dry run: would create table '{$schema->targetTable}'");
      return;
    }

    $joinSchemas = [];
    foreach (self::joinTables($schema->joins) as $table) {
      $joinSchemas[$table] = $this->source->getTableSchema($table);
    }
    $table = (new SchemaConverter())->convert($this->source->getTableSchema($schema->sourceTable), $schema, $joinSchemas);
    $table = self::withUuidColumns($table, $this->mapper()->uuidColumns($schema));

    $sql = $table->toCreateTableSQL($this->target);
    $this->logger->debug("Create SQL: {$sql}");
    $this->target->execute($sql);
    $this->logger->info("Created target table '{$schema->targetTable}'");
  }

  /**
   * Columns that will hold UUIDs get a UUID type instead of the source's integer type.
   *
   * @param string[] $uuidColumns
   */
  private static function withUuidColumns(TableSchema $table, array $uuidColumns): TableSchema
  {
    if (!$uuidColumns) {
      return $table;
    }
    $columns = $table->columns;
    foreach ($uuidColumns as $name) {
      if ($col = $columns[$name] ?? null) {
        $columns[$name] = new ColumnSchema(name: $col->name, type: 'uuid', nullable: $col->nullable, isPrimaryKey: $col->isPrimaryKey);
      }
    }
    return new TableSchema($table->name, $columns, $table->primaryKey, $table->foreignKeys, $table->indexes);
  }

  /**
   * @return string[] Every table referenced by (nested) joins
   */
  private static function joinTables(array $joins): array
  {
    $tables = [];
    foreach ($joins as $join) {
      $tables = [...$tables, $join['table'], ...self::joinTables($join['join'] ?? [])];
    }
    return array_unique($tables);
  }

  private function reader(): SourceReader
  {
    return new SourceReader($this->source, $this->batchSize);
  }

  private function offsets(): OffsetStore
  {
    return $this->offsets ??= new OffsetStore($this->target, $this->dryRun);
  }

  private function mapper(): IdMapper
  {
    return $this->mapper ??= new IdMapper($this->source, $this->target, $this->reader(), $this->offsets(), $this->schemas, $this->logger, $this->dryRun);
  }

  private function reset(): self
  {
    $this->offsets = null;
    $this->mapper = null;
    return $this;
  }
}
