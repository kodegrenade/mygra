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
    private string $schemaName;
    private bool $success = false;
    private int $totalRows = 0;
    private int $processedRows = 0;
    private int $insertedRows = 0;
    private int $updatedRows = 0;
    private int $skippedRows = 0;
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

    public function setProcessedRows(int $processed): void
    {
        $this->processedRows = $processed;
    }

    public function setInsertedRows(int $inserted): void
    {
        $this->insertedRows = $inserted;
    }

    public function setUpdatedRows(int $updated): void
    {
        $this->updatedRows = $updated;
    }

    public function setSkippedRows(int $skipped): void
    {
        $this->skippedRows = $skipped;
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

    public function addError(array $error): void
    {
        $this->errors[] = $error;
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

    public function getProcessedRows(): int
    {
        return $this->processedRows;
    }

    public function getInsertedRows(): int
    {
        return $this->insertedRows;
    }

    public function getUpdatedRows(): int
    {
        return $this->updatedRows;
    }

    public function getSkippedRows(): int
    {
        return $this->skippedRows;
    }

    public function getErrorCount(): int
    {
        return $this->errorCount;
    }

    public function getDuration(): float
    {
        return $this->duration;
    }

    public function getFormattedDuration(): string
    {
        if ($this->duration < 1) {
            return round($this->duration * 1000) . 'ms';
        }
        if ($this->duration < 60) {
            return round($this->duration, 2) . 's';
        }
        $minutes = floor($this->duration / 60);
        $seconds = $this->duration % 60;
        return "{$minutes}m " . round($seconds) . 's';
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

    public function getMetadata(string $key = null): mixed
    {
        if ($key === null) {
            return $this->metadata;
        }
        return $this->metadata[$key] ?? null;
    }

    /**
     * Get rows processed per second.
     */
    public function getRowsPerSecond(): float
    {
        if ($this->duration <= 0) {
            return 0;
        }
        return $this->processedRows / $this->duration;
    }

    /**
     * Get completion percentage.
     */
    public function getProgressPercent(): float
    {
        if ($this->totalRows <= 0) {
            return 0;
        }
        return ($this->processedRows / $this->totalRows) * 100;
    }

    /**
     * Convert to array.
     */
    public function toArray(): array
    {
        return [
            'schema' => $this->schemaName,
            'success' => $this->success,
            'total_rows' => $this->totalRows,
            'processed_rows' => $this->processedRows,
            'inserted_rows' => $this->insertedRows,
            'updated_rows' => $this->updatedRows,
            'skipped_rows' => $this->skippedRows,
            'error_count' => $this->errorCount,
            'duration' => $this->duration,
            'formatted_duration' => $this->getFormattedDuration(),
            'rows_per_second' => round($this->getRowsPerSecond(), 2),
            'error' => $this->error,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * Create a summary string.
     */
    public function getSummary(): string
    {
        $status = $this->success ? '✓ Success' : '✗ Failed';

        return sprintf(
            "%s | Processed: %d/%d | Inserted: %d | Errors: %d | Duration: %s",
            $status,
            $this->processedRows,
            $this->totalRows,
            $this->insertedRows,
            $this->errorCount,
            $this->getFormattedDuration()
        );
    }
}
