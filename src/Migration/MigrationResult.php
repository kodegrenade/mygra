<?php

declare(strict_types=1);

namespace Mygra\Migration;

/**
 * Migration Result
 *
 * Contains the results of a migration execution.
 */
class MigrationResult
{
  public const MAX_KEPT_ERRORS = 100;

  private string $schemaName;
  private bool $success = false;
  private int $totalRows = 0;
  private int $insertedRows = 0;
  private int $errorCount = 0;
  private float $duration = 0.0;
  private ?string $error = null;
  private array $errors = [];
  private array $warnings = [];
  private array $metadata = [];

  public function __construct(string $schemaName)
  {
    $this->schemaName = $schemaName;
  }

  // Setters
  public function setSuccess(bool $success): void
  {
    $this->success = $success;
  }

  public function setTotalRows(int $total): void
  {
    $this->totalRows = $total;
  }

  public function setInsertedRows(int $inserted): void
  {
    $this->insertedRows = $inserted;
  }

  public function setErrorCount(int $count): void
  {
    $this->errorCount = $count;
  }

  public function setDuration(float $duration): void
  {
    $this->duration = $duration;
  }

  public function setError(string $error): void
  {
    $this->error = $error;
  }

  /**
   * Keeps the first MAX_KEPT_ERRORS with their rows; the error count stays exact and every error
   * is in the log, so a run with millions of bad rows does not hold them all in memory.
   */
  public function addError(array $error): void
  {
    if (count($this->errors) < self::MAX_KEPT_ERRORS) {
      $this->errors[] = $error;
    }
  }

  public function addWarning(string $warning): void
  {
    $this->warnings[] = $warning;
  }

  public function setMetadata(string $key, mixed $value): void
  {
    $this->metadata[$key] = $value;
  }

  // Getters
  public function getSchemaName(): string
  {
    return $this->schemaName;
  }

  public function isSuccess(): bool
  {
    return $this->success;
  }

  public function getTotalRows(): int
  {
    return $this->totalRows;
  }

  public function getInsertedRows(): int
  {
    return $this->insertedRows;
  }

  public function getErrorCount(): int
  {
    return $this->errorCount;
  }

  public function getDuration(): float
  {
    return $this->duration;
  }

  public function getError(): ?string
  {
    return $this->error;
  }

  public function getErrors(): array
  {
    return $this->errors;
  }

  public function getWarnings(): array
  {
    return $this->warnings;
  }

  public function getMetadata(?string $key = null): mixed
  {
    if ($key === null) {
      return $this->metadata;
    }
    return $this->metadata[$key] ?? null;
  }
}
