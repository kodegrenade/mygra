<?php

declare(strict_types=1);

namespace Mygra\Transaction;

/**
 * Rollback Journal
 * 
 * Persistent log of database operations that can be used to
 * recover from failed or interrupted migrations.
 */
class RollbackJournal
{
  private string $filePath;
  private bool $isActive = false;

  public function __construct(string $filePath)
  {
    $this->filePath = $filePath;
    $this->ensureDirectoryExists();
  }

  /**
   * Start a new journal session.
   */
  public function start(): void
  {
    if (file_exists($this->filePath)) {
      unlink($this->filePath);
    }
    $this->isActive = true;
    $this->log(['type' => 'METADATA', 'action' => 'START', 'timestamp' => microtime(true)]);
  }

  /**
   * Record an insert operation.
   * 
   * @param string $table Table name
   * @param array $primaryKey Primary key values: ['id' => 123]
   */
  public function recordInsert(string $table, array $primaryKey): void
  {
    if (!$this->isActive) return;

    $this->log([
      'type' => 'INSERT',
      'table' => $table,
      'key' => $primaryKey,
      'timestamp' => microtime(true)
    ]);
  }

  /**
   * Record a savepoint/checkpoint.
   */
  public function recordSavepoint(string $name): void
  {
    if (!$this->isActive) return;

    $this->log([
      'type' => 'SAVEPOINT',
      'name' => $name,
      'timestamp' => microtime(true)
    ]);
  }

  /**
   * Record a batch of insert operations.
   * 
   * @param string $table Table name
   * @param array $primaryKeys Array of primary key values
   */
  public function recordBatch(string $table, array $primaryKeys): void
  {
    if (!$this->isActive) return;

    $this->log([
      'type' => 'BATCH_INSERT',
      'table' => $table,
      'keys' => $primaryKeys,
      'timestamp' => microtime(true)
    ]);
  }

  /**
   * Complete the journal session (successful commit).
   */
  public function complete(): void
  {
    if (!$this->isActive) return;

    $this->log(['type' => 'METADATA', 'action' => 'COMPLETE', 'timestamp' => microtime(true)]);
    $this->isActive = false;

    // Optionally keep the file or delete it
    if (file_exists($this->filePath)) {
      unlink($this->filePath);
    }
  }

  /**
   * Read journal entries for recovery.
   */
  public function readEntries(): array
  {
    if (!file_exists($this->filePath)) {
      return [];
    }

    $entries = [];
    $handle = fopen($this->filePath, 'r');
    if ($handle) {
      while (($line = fgets($handle)) !== false) {
        $entry = json_decode($line, true);
        if ($entry) {
          $entries[] = $entry;
        }
      }
      fclose($handle);
    }
    return $entries;
  }

  private function log(array $entry): void
  {
    file_put_contents($this->filePath, json_encode($entry) . PHP_EOL, FILE_APPEND | LOCK_EX);
  }

  private function ensureDirectoryExists(): void
  {
    $dir = dirname($this->filePath);
    if (!is_dir($dir)) {
      mkdir($dir, 0755, true);
    }
  }
}
