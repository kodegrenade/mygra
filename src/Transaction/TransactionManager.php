<?php

declare(strict_types=1);

namespace Mygra\Transaction;

use Mygra\Adapters\AdapterInterface;
use Mygra\Logger\LoggerInterface;
use Mygra\Logger\NullLogger;

/**
 * Transaction Manager
 * 
 * Orchestrates database transactions and rollback journals.
 */
class TransactionManager
{
  private AdapterInterface $adapter;
  private ?RollbackJournal $journal;
  private LoggerInterface $logger;
  private int $savepointCounter = 0;

  public function __construct(
    AdapterInterface $adapter,
    ?RollbackJournal $journal = null,
    ?LoggerInterface $logger = null
  ) {
    $this->adapter = $adapter;
    $this->journal = $journal;
    $this->logger = $logger ?? new NullLogger();
  }

  /**
   * Begin a transaction session.
   */
  public function begin(): void
  {
    $this->adapter->beginTransaction();
    if ($this->journal) {
      $this->journal->start();
    }
    $this->logger->debug("Transaction and journal started");
  }

  /**
   * Create a checkpoint/savepoint.
   */
  public function checkpoint(?string $name = null): string
  {
    $name = $name ?? 'sp_' . (++$this->savepointCounter);
    $this->adapter->savepoint($name);

    if ($this->journal) {
      $this->journal->recordSavepoint($name);
    }

    $this->logger->debug("Checkpoint created: {$name}");
    return $name;
  }

  /**
   * Commit the transaction.
   */
  public function commit(): void
  {
    $this->adapter->commit();
    if ($this->journal) {
      $this->journal->complete();
    }
    $this->logger->debug("Transaction committed and journal completed");
  }

  /**
   * Rollback the transaction.
   */
  public function rollback(?string $toSavepoint = null): void
  {
    if ($toSavepoint) {
      $this->adapter->rollbackToSavepoint($toSavepoint);
      $this->logger->info("Rolled back to savepoint: {$toSavepoint}");
    } elseif ($this->adapter->inTransaction()) {
      // Failures before begin() have nothing to roll back; don't mask the original error
      $this->adapter->rollback();
      $this->logger->info("Full transaction rollback executed");
    }
  }

  /**
   * Record a batch of insert operations in the journal.
   */
  public function recordBatch(string $table, array $primaryKeys): void
  {
    if ($this->journal) {
      $this->journal->recordBatch($table, $primaryKeys);
    }
  }

  /**
   * Check if a journal exists and needs recovery.
   */
  public function needsRecovery(): bool
  {
    return $this->journal !== null && !empty($this->journal->readEntries());
  }

  /**
   * Get the journal instance.
   */
  public function getJournal(): ?RollbackJournal
  {
    return $this->journal;
  }
}
