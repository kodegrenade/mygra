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
    public readonly array $extra = []
  ) {}

  /**
   * Check if this column type is numeric.
   */
  public function isNumeric(): bool
  {
    $numericTypes = [
      'int',
      'integer',
      'bigint',
      'smallint',
      'tinyint',
      'mediumint',
      'decimal',
      'numeric',
      'float',
      'double',
      'real',
      'money'
    ];
    return in_array(strtolower($this->type), $numericTypes, true);
  }

  /**
   * Check if this column type is a string type.
   */
  public function isString(): bool
  {
    $stringTypes = [
      'char',
      'varchar',
      'text',
      'tinytext',
      'mediumtext',
      'longtext',
      'nchar',
      'nvarchar',
      'ntext',
      'character varying',
      'character'
    ];
    return in_array(strtolower($this->type), $stringTypes, true);
  }

  /**
   * Check if this column type is a date/time type.
   */
  public function isDateTime(): bool
  {
    $dateTypes = [
      'date',
      'datetime',
      'timestamp',
      'time',
      'year',
      'timestamptz',
      'timetz',
      'interval'
    ];
    return in_array(strtolower($this->type), $dateTypes, true);
  }

  /**
   * Check if this column type is a boolean type.
   */
  public function isBoolean(): bool
  {
    $boolTypes = ['bool', 'boolean', 'bit'];
    return in_array(strtolower($this->type), $boolTypes, true);
  }

  /**
   * Check if this column type is a binary/blob type.
   */
  public function isBinary(): bool
  {
    $binaryTypes = [
      'binary',
      'varbinary',
      'blob',
      'tinyblob',
      'mediumblob',
      'longblob',
      'bytea',
      'image'
    ];
    return in_array(strtolower($this->type), $binaryTypes, true);
  }

  /**
   * Convert to array representation.
   */
  public function toArray(): array
  {
    return [
      'name' => $this->name,
      'type' => $this->type,
      'nativeType' => $this->nativeType,
      'nullable' => $this->nullable,
      'default' => $this->default,
      'length' => $this->length,
      'precision' => $this->precision,
      'scale' => $this->scale,
      'isPrimaryKey' => $this->isPrimaryKey,
      'isAutoIncrement' => $this->isAutoIncrement,
      'isUnique' => $this->isUnique,
      'comment' => $this->comment,
      'extra' => $this->extra,
    ];
  }

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
      extra: $data['extra'] ?? []
    );
  }
}
