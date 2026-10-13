<?php

declare(strict_types=1);

namespace Mygra\Config;

/**
 * Schema Configuration
 * 
 * Represents the configuration for migrating a single table/entity.
 */
class SchemaConfig
{
  public function __construct(
    public readonly string $name,
    public readonly string $sourceTable,
    public readonly string $targetTable,
    public readonly array $fields,
    public readonly array $transforms = [],
    public readonly array $validations = [],
    public readonly ?string $where = null,
    public readonly array $joins = [],
    public readonly array $options = [],
    /** @var array<string, array{column: string, schema: string, on_missing: string, pattern: ?string, replacement: ?string}> name => parent */
    public readonly array $references = []
  ) {}

  /**
   * Create from configuration array.
   */
  public static function fromArray(string $name, array $config): self
  {
    return new self(
      name: $name,
      sourceTable: $config['source_table'] ?? $config['source'] ?? '',
      targetTable: $config['target_table'] ?? $config['target'] ?? '',
      fields: $config['fields'] ?? [],
      transforms: $config['transforms'] ?? [],
      validations: $config['validations'] ?? [],
      where: $config['where'] ?? null,
      joins: self::normalizeJoins($config['joins'] ?? $config['join'] ?? []),
      options: $config['options'] ?? [],
      references: self::normalizeReferences($config['references'] ?? [])
    );
  }

  /**
   * Get source field names.
   */
  public function getSourceFields(): array
  {
    return array_keys($this->fields);
  }

  /**
   * Get target field names.
   */
  public function getTargetFields(): array
  {
    return array_values($this->fields);
  }

  /**
   * Get the target field name for a source field.
   */
  public function mapField(string $sourceField): ?string
  {
    return $this->fields[$sourceField] ?? null;
  }

  /**
   * Check if a field has transformations.
   */
  public function hasTransform(string $field): bool
  {
    return isset($this->transforms[$field]);
  }

  /**
   * Get transformations for a field.
   */
  public function getTransforms(string $field): array
  {
    return $this->transforms[$field] ?? [];
  }

  /**
   * Check if a field has validations.
   */
  public function hasValidation(string $field): bool
  {
    return isset($this->validations[$field]);
  }

  /**
   * Get validations for a field.
   */
  public function getValidations(string $field): array
  {
    return $this->validations[$field] ?? [];
  }

  /**
   * Get an option value.
   */
  public function getOption(string $key, mixed $default = null): mixed
  {
    return $this->options[$key] ?? $default;
  }

  /**
   * Check if this schema has joins.
   */
  public function hasJoins(): bool
  {
    return !empty($this->joins);
  }

  /**
   * Convert to array.
   */
  public function toArray(): array
  {
    return [
      'name' => $this->name,
      'source_table' => $this->sourceTable,
      'target_table' => $this->targetTable,
      'fields' => $this->fields,
      'transforms' => $this->transforms,
      'validations' => $this->validations,
      'where' => $this->where,
      'joins' => $this->joins,
      'options' => $this->options,
      'references' => $this->references,
    ];
  }

  /**
   * Normalize references:
   *   `author_id: users`                                  a column holding the parent's ID
   *   `author_id: { schema: users, on_missing: null }`
   *   `content: { schema: posts, pattern: '/\?p=(\d+)/' }`  IDs inside text: capture group 1 holds
   *                                                        one ID or a comma-separated list
   *   `gallery: { column: content, schema: media, pattern: ... }`  a second reference in the same column
   */
  private static function normalizeReferences(array $references): array
  {
    $normalized = [];
    foreach ($references as $column => $ref) {
      $ref = is_string($ref) ? ['schema' => $ref] : $ref;
      $pattern = $ref['pattern'] ?? null;
      $onMissing = $ref['on_missing'] ?? 'error';

      if ($pattern !== null) {
        // preg_match_all fills one array per group even without a match, so this also counts groups
        if (@preg_match_all($pattern, '', $groups) === false || count($groups) < 2) {
          throw new \InvalidArgumentException("references.{$column}.pattern must be a valid regex with a capture group, got '{$pattern}'");
        }
        // NULL would wipe the whole text; replace swaps only the dead ID for one that matches nothing
        $allowed = ['error', 'replace'];
      } else {
        $allowed = ['error', 'null'];
      }
      if (!in_array($onMissing, $allowed, true)) {
        throw new \InvalidArgumentException(
          "references.{$column}.on_missing must be " . implode(' or ', $allowed) . ", got '{$onMissing}'"
        );
      }

      $normalized[$column] = [
        'column' => $ref['column'] ?? $column,
        'schema' => $ref['schema'],
        'on_missing' => $onMissing,
        'pattern' => $pattern,
        'replacement' => $pattern !== null ? (string) ($ref['replacement'] ?? '0') : null,
      ];
    }
    return $normalized;
  }

  /**
   * Normalize joins configuration to consistent format.
   */
  private static function normalizeJoins(array $joins): array
  {
    $normalized = [];

    foreach ($joins as $key => $join) {
      // Handle both indexed array and associative array formats
      if (is_string($key)) {
        // Associative format: table_name => config
        $join['table'] = $key;
      }

      if (!isset($join['table'])) {
        continue;
      }

      $normalized[] = [
        'table' => $join['table'],
        'type' => strtoupper($join['type'] ?? 'LEFT'),
        'source_key' => $join['source_key'] ?? 'id',
        'target_key' => $join['target_key'] ?? $join['source_key'] ?? 'id',
        'fields' => $join['fields'] ?? [],
        'where' => $join['where'] ?? null,
        'join' => isset($join['join']) ? self::normalizeJoins($join['join']) : [],
      ];
    }

    return $normalized;
  }
}
