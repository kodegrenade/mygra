<?php

declare(strict_types=1);

namespace Mygra\Migration;

/**
 * What verify found for one schema.
 */
final class VerificationResult
{
  public function __construct(
    public readonly int $sourceRows,
    /** Source rows that fail validation or reference a missing parent: not expected in the target */
    public readonly int $invalidRows,
    public readonly int $targetRows,
    public readonly bool $checksumMatch,
  ) {}

  public function expectedRows(): int
  {
    return $this->sourceRows - $this->invalidRows;
  }

  public function matches(): bool
  {
    return $this->checksumMatch && $this->expectedRows() === $this->targetRows;
  }

  /**
   * @return array{source_rows: int, invalid_rows: int, expected_rows: int, target_rows: int, checksum_match: bool, match: bool}
   */
  public function toArray(): array
  {
    return [
      'source_rows' => $this->sourceRows,
      'invalid_rows' => $this->invalidRows,
      'expected_rows' => $this->expectedRows(),
      'target_rows' => $this->targetRows,
      'checksum_match' => $this->checksumMatch,
      'match' => $this->matches(),
    ];
  }
}
