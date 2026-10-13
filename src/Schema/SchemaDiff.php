<?php

declare(strict_types=1);

namespace Mygra\Schema;

/**
 * Schema Diff
 *
 * Compares two TableSchema objects and reports differences.
 */
class SchemaDiff
{
  private array $diffs = [];

  /**
   * Compare source table with target table.
   *
   * @param TableSchema $source
   * @param TableSchema $target
   * @param array $fieldMappings Map of source_field => target_field
   * @return array List of differences
   */
  public function compare(TableSchema $source, TableSchema $target, array $fieldMappings = []): array
  {
    $this->diffs = [];

    // If no field mappings provided, assume 1:1 mapping
    if (empty($fieldMappings)) {
      foreach ($source->getColumnNames() as $name) {
        $fieldMappings[$name] = $name;
      }
    }

    $this->compareColumns($source, $target, $fieldMappings);
    $this->comparePrimaryKey($source, $target, $fieldMappings);
    $this->compareIndexes($source, $target, $fieldMappings);

    return $this->diffs;
  }

  private function compareColumns(TableSchema $source, TableSchema $target, array $fieldMappings): void
  {
    foreach ($fieldMappings as $srcName => $targetName) {
      $srcCol = $source->getColumn($srcName);
      $targetCol = $target->getColumn($targetName);

      if (!$srcCol) {
        continue; // Should not happen if mappings are correct
      }

      if (!$targetCol) {
        $this->addDiff('column', $targetName, 'missing', "Column '{$targetName}' is missing in target table");
        continue;
      }

      // Compare types (normalization might be needed if they are from different drivers)
      // But TableSchema usually has abstracted types if fetched correctly
      if ($srcCol->type !== $targetCol->type) {
        $this->addDiff('column', $targetName, 'type_mismatch', "Type mismatch for '{$targetName}': source '{$srcCol->type}' vs target '{$targetCol->type}'");
      }

      if ($srcCol->nullable !== $targetCol->nullable) {
        $srcNull = $srcCol->nullable ? 'NULL' : 'NOT NULL';
        $targetNull = $targetCol->nullable ? 'NULL' : 'NOT NULL';
        $this->addDiff('column', $targetName, 'nullability_mismatch', "Nullability mismatch for '{$targetName}': source {$srcNull} vs target {$targetNull}");
      }

      if ($srcCol->default != $targetCol->default) {
        $srcDefault = $srcCol->default ?? 'NULL';
        $targetDefault = $targetCol->default ?? 'NULL';
        $this->addDiff('column', $targetName, 'default_mismatch', "Default mismatch for '{$targetName}': source '{$srcDefault}' vs target '{$targetDefault}'");
      }
    }

    // Check for extra columns in target
    foreach ($target->getColumnNames() as $targetName) {
      if (!in_array($targetName, $fieldMappings, true)) {
        $this->addDiff('column', $targetName, 'extra', "Extra column '{$targetName}' in target table");
      }
    }
  }

  private function comparePrimaryKey(TableSchema $source, TableSchema $target, array $fieldMappings): void
  {
    $srcPk = $source->getPrimaryKeyColumns();
    $targetPk = $target->getPrimaryKeyColumns();

    $mappedSrcPk = array_map(function ($col) use ($fieldMappings) {
      return $fieldMappings[$col] ?? $col;
    }, $srcPk);

    sort($mappedSrcPk);
    sort($targetPk);

    if ($mappedSrcPk !== $targetPk) {
      $srcPkStr = implode(', ', $mappedSrcPk);
      $targetPkStr = implode(', ', $targetPk);
      $this->addDiff('primary_key', 'pk', 'mismatch', "Primary key mismatch: source ({$srcPkStr}) vs target ({$targetPkStr})");
    }
  }

  private function compareIndexes(TableSchema $source, TableSchema $target, array $fieldMappings): void
  {
    // Simple comparison: check if target has at least the indexes on the mapped columns
    // This is simplified as index names might differ and multi-column indexes are tricky
    foreach ($source->indexes as $idxName => $srcIdx) {
      $srcCols = $srcIdx['columns'];
      $mappedCols = array_map(fn($c) => $fieldMappings[$c] ?? $c, $srcCols);

      $foundMatch = false;
      foreach ($target->indexes as $tIdxName => $targetIdx) {
        if ($targetIdx['columns'] === $mappedCols) {
          $foundMatch = true;
          break;
        }
      }

      if (!$foundMatch) {
        $colsStr = implode(', ', $mappedCols);
        $this->addDiff('index', $idxName, 'missing', "Index on ({$colsStr}) is missing in target table");
      }
    }
  }

  private function addDiff(string $category, mixed $item, string $type, string $message): void
  {
    $this->diffs[] = [
      'category' => $category,
      'item' => (string) $item,
      'type' => $type,
      'message' => $message,
    ];
  }
}
