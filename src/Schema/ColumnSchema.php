<?php

declare(strict_types=1);

namespace Mygra\Schema;

/**
 * Column Schema
 *
 * Represents the schema/metadata of a single database column.
 */
class ColumnSchema
{
  public function __construct(
    public readonly string $name,
    public readonly string $type,
    public readonly ?string $nativeType = null,
    public readonly bool $nullable = true,
    public readonly mixed $default = null,
    public readonly ?int $length = null,
    public readonly ?int $precision = null,
    public readonly ?int $scale = null,
    public readonly bool $isPrimaryKey = false,
    public readonly bool $isAutoIncrement = false,
    public readonly bool $isUnique = false,
    public readonly ?string $comment = null,
    public readonly array $extra = [],
  ) {}

  /**
   * Create a ColumnSchema from array data.
   */
  public static function fromArray(array $data): self
  {
    return new self(
      name: $data['name'],
      type: $data['type'],
      nativeType: $data['nativeType'] ?? null,
      nullable: $data['nullable'] ?? true,
      default: $data['default'] ?? null,
      length: $data['length'] ?? null,
      precision: $data['precision'] ?? null,
      scale: $data['scale'] ?? null,
      isPrimaryKey: $data['isPrimaryKey'] ?? false,
      isAutoIncrement: $data['isAutoIncrement'] ?? false,
      isUnique: $data['isUnique'] ?? false,
      comment: $data['comment'] ?? null,
      extra: $data['extra'] ?? [],
    );
  }
}
