<?php

declare(strict_types=1);

namespace Mygra\Migration;

use Mygra\Config\SchemaConfig;
use Mygra\Transform\TransformPipeline;
use Mygra\Validation\ValidationRunner;

/**
 * Turns one source row into a target row: field mapping and transforms, validation, then the
 * schema's own key mapped to its new ID. Shared by migrate and verify, so verify expects exactly
 * what migrate writes.
 */
final class RowPreparer
{
  private ?SchemaConfig $schema = null;

  /** @var array<string, string> source column (or join alias) => target column */
  private array $fieldMap = [];

  public function __construct(
    private readonly TransformPipeline $pipeline,
    private readonly ValidationRunner $validator,
  ) {}

  /**
   * Configure the transforms for one schema; the pipeline is shared across schemas.
   */
  public function use(SchemaConfig $schema): self
  {
    $this->schema = $schema;
    $this->pipeline->clearFieldTransformers();
    foreach ($schema->transforms as $field => $transforms) {
      $this->pipeline->forField($field, $transforms);
    }
    $this->fieldMap = $schema->fields + self::joinFieldMap($schema->joins);
    return $this;
  }

  /**
   * @return array{0: ?array, 1: ?string} [target row, or null with the reason it was rejected]
   */
  public function prepare(array $row, IdContext $ids): array
  {
    try {
      $prepared = $this->pipeline->process($row, $this->fieldMap);

      if ($this->schema->validations) {
        $validation = $this->validator->validateRow($prepared, $this->schema->validations);
        if (!$validation->isValid()) {
          return [null, 'Validation failed: ' . $validation->getFormattedErrors()];
        }
      }

      return [$ids->mapKey($prepared), null];
    } catch (\Throwable $e) {
      // TypeErrors from a transformer are row errors too, not a reason to kill the whole run
      return [null, $e->getMessage()];
    }
  }

  /**
   * @return string[] Target columns, in order
   */
  public function columns(): array
  {
    return array_values($this->fieldMap);
  }

  /**
   * Join fields are selected AS their target name, so they pass through unchanged.
   */
  private static function joinFieldMap(array $joins): array
  {
    $map = [];
    foreach ($joins as $join) {
      foreach ($join['fields'] as $targetField) {
        $map[$targetField] = $targetField;
      }
      $map += self::joinFieldMap($join['join'] ?? []);
    }
    return $map;
  }
}
