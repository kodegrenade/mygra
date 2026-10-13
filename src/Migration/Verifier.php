<?php

declare(strict_types=1);

namespace Mygra\Migration;

use Mygra\Adapters\AdapterInterface;
use Mygra\Config\SchemaConfig;
use Mygra\Support\Uuid;

/**
 * Compares source and target after a migration. The source side runs through the same reader,
 * transforms, validation and ID mapping as migrate, so "expected" is exactly the rows a migration
 * writes. Both sides are reduced to a row count and an order-independent checksum of the mapped
 * columns, so no order or memory is needed beyond one batch.
 */
final class Verifier
{
  public function __construct(
    private readonly AdapterInterface $target,
    private readonly SourceReader $reader,
    private readonly RowPreparer $preparer,
    private readonly IdMapper $mapper,
    private readonly int $batchSize = 1000,
  ) {}

  public function verify(SchemaConfig $schema): VerificationResult
  {
    $this->preparer->use($schema);
    $columns = $this->preparer->columns();
    $ids = $this->mapper->context($schema, false);

    $q = [$this->target, 'quoteIdentifier'];
    $targetSelect = 'SELECT ' . implode(', ', array_map($q, $columns)) . " FROM {$this->target->quoteTable($schema->targetTable)}";

    // UUID keys have no range to scan: fetch exactly the target rows Mygra wrote, by key, as source rows pass
    $byKey = $ids->kind === 'uuid';

    $sourceRows = $invalidRows = $targetRows = 0;
    $sourceSum = $targetSum = 0;
    $pending = [];
    $flush = function () use (&$pending, &$invalidRows, &$sourceSum, &$targetRows, &$targetSum, $ids, $columns, $byKey, $targetSelect, $q): void {
      [$kept, $orphans] = $this->mapper->resolve($pending, $ids);
      $invalidRows += count($orphans);
      foreach ($kept as $row) {
        $sourceSum = self::addRowHash($sourceSum, $row, $columns);
      }
      if ($byKey) {
        foreach (array_chunk(array_column($kept, $ids->key), 1000) as $keys) {
          $placeholders = implode(', ', array_fill(0, count($keys), '?'));
          foreach ($this->target->query("{$targetSelect} WHERE {$q($ids->key)} IN ({$placeholders})", $keys) as $row) {
            $targetRows++;
            $targetSum = self::addRowHash($targetSum, $row, $columns);
          }
        }
      }
      $pending = [];
    };

    foreach ($this->reader->rows($schema) as $row) {
      $sourceRows++;
      [$prepared, $error] = $this->preparer->prepare($row, $ids);
      if ($error !== null) {
        $invalidRows++;
        continue;
      }
      $pending[] = $prepared;
      if (count($pending) >= $this->batchSize) {
        $flush();
      }
    }
    $flush();

    if (!$byKey) {
      $sql = $targetSelect;
      $params = [];
      if ($ids->offset !== null) {
        // Merged into a table the application already used: only Mygra's ID range is compared
        $range = $this->reader->keyRange($schema);
        $sql .= " WHERE {$q($ids->key)} BETWEEN ? AND ?";
        $params = [(int) $range['lo'] + $ids->offset, (int) $range['hi'] + $ids->offset];
      }
      // SQL Server's streaming needs an ORDER BY
      $pk = $this->target->getPrimaryKey($schema->targetTable);
      if ($pk) {
        $sql .= ' ORDER BY ' . implode(', ', array_map($q, (array) $pk));
      }
      foreach ($this->target->streamQuery($sql, $params) as $row) {
        $targetRows++;
        $targetSum = self::addRowHash($targetSum, $row, $columns);
      }
    }

    return new VerificationResult($sourceRows, $invalidRows, $targetRows, $sourceSum === $targetSum);
  }

  /**
   * Add a row's hash to an order-independent checksum (sum of 60-bit hashes, mod 2^61).
   */
  private static function addRowHash(int $sum, array $row, array $columns): int
  {
    $values = array_map(fn($col) => self::normalize($row[$col] ?? null), $columns);
    return ($sum + hexdec(substr(md5(json_encode($values)), 0, 15))) % (1 << 61);
  }

  /**
   * Canonical string form, so drivers' formatting differences don't fail verification:
   * sqlsrv returns numbers as strings and UUIDs in upper case, pgsql returns real booleans,
   * DATETIME2 pads ".0000000".
   */
  private static function normalize(mixed $value): ?string
  {
    return match (true) {
      $value === null => null,
      is_bool($value) => $value ? '1' : '0',
      is_int($value), is_float($value), is_numeric($value) => (string) (0 + $value),
      Uuid::isValid((string) $value) => strtolower((string) $value),
      default => preg_replace('/^(\d{4}-\d\d-\d\d[ T]\d\d:\d\d:\d\d)\.0*$/', '$1', (string) $value),
    };
  }
}
