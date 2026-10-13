<?php

declare(strict_types=1);

namespace Mygra\Schema;

use Mygra\Config\SchemaConfig;

/**
 * Schema Converter
 *
 * Converts a source TableSchema into a target TableSchema
 * according to the provided SchemaConfig and field mappings.
 */
class SchemaConverter
{
  /**
   * Convert a source schema to a target schema.
   *
   * @param array<string, TableSchema> $joinSchemas Source schemas of joined tables, keyed by table name
   */
  public function convert(TableSchema $sourceSchema, SchemaConfig $config, array $joinSchemas = []): TableSchema
  {
    $targetColumns = [];
    $targetPrimaryKey = null;

    foreach ($config->fields as $sourceField => $targetField) {
      $sourceColumn = $sourceSchema->getColumn($sourceField);

      if (!$sourceColumn) {
        // Skip fields that don't exist in source (they might be added by row transformers later)
        continue;
      }

      // Create target column based on source column
      $targetColumns[$targetField] = new ColumnSchema(
        name: $targetField,
        type: $sourceColumn->type,
        nativeType: $sourceColumn->nativeType,
        nullable: $sourceColumn->nullable,
        default: $sourceColumn->default,
        length: $sourceColumn->length,
        precision: $sourceColumn->precision,
        scale: $sourceColumn->scale,
        isPrimaryKey: $sourceColumn->isPrimaryKey,
        isAutoIncrement: $sourceColumn->isAutoIncrement,
        isUnique: $sourceColumn->isUnique,
        comment: $sourceColumn->comment,
        extra: $sourceColumn->extra,
      );

      if ($sourceColumn->isPrimaryKey) {
        if ($targetPrimaryKey === null) {
          $targetPrimaryKey = $targetField;
        } elseif (is_array($targetPrimaryKey)) {
          $targetPrimaryKey[] = $targetField;
        } else {
          $targetPrimaryKey = [$targetPrimaryKey, $targetField];
        }
      }
    }

    $targetColumns += $this->joinColumns($config->joins, $joinSchemas);

    return new TableSchema(
      name: $config->targetTable,
      columns: $targetColumns,
      primaryKey: $targetPrimaryKey,
      foreignKeys: [], // Skip foreign keys for now to simplify initial creation
      indexes: [],     // Skip indexes for now
    );
  }

  /**
   * Target columns for join fields. Always nullable, since a join may not match.
   */
  private function joinColumns(array $joins, array $joinSchemas): array
  {
    $columns = [];

    foreach ($joins as $join) {
      foreach ($join['fields'] as $sourceField => $targetField) {
        $col = ($joinSchemas[$join['table']] ?? null)?->getColumn($sourceField);
        if (!$col) {
          continue;
        }

        $columns[$targetField] = new ColumnSchema(
          name: $targetField,
          type: $col->type,
          nativeType: $col->nativeType,
          length: $col->length,
          precision: $col->precision,
          scale: $col->scale,
        );
      }
      $columns += $this->joinColumns($join['join'], $joinSchemas);
    }

    return $columns;
  }
}
