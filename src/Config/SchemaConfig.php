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
    public readonly array $references = [],
  ) {}

  /**
   * Create from configuration array.
   */
  public static function fromArray(string $name, array $config): self
  {
    return new self(
      name: $name,
      sourceTable: $config['source_table'] ?? '',
      targetTable: $config['target_table'] ?? '',
      fields: $config['fields'] ?? [],
      transforms: $config['transforms'] ?? [],
      validations: $config['validations'] ?? [],
      where: $config['where'] ?? null,
      joins: self::normalizeJoins($config['joins'] ?? []),
      options: $config['options'] ?? [],
      references: self::normalizeReferences($config['references'] ?? []),
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
   * Get an option value.
   */
  public function getOption(string $key, mixed $default = null): mixed
  {
    return $this->options[$key] ?? $default;
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
      foreach (self::referenceProblems($ref) as $key => $problem) {
        throw new \InvalidArgumentException("references.{$column}.{$key}: {$problem}");
      }
      $pattern = $ref['pattern'] ?? null;
      $onMissing = self::onMissing($ref);

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
   * Problems in one reference's pattern and on_missing, as key => message (shared with ConfigValidator).
   *
   * @return array<string, string>
   */
  public static function referenceProblems(array $ref): array
  {
    $problems = [];
    $pattern = $ref['pattern'] ?? null;
    $onMissing = self::onMissing($ref);

    if ($pattern !== null) {
      // preg_match_all fills one array per group even without a match, so this also counts groups
      if (!is_string($pattern) || @preg_match_all($pattern, '', $groups) === false || count($groups) < 2) {
        $problems['pattern'] = 'must be a valid regex with a capture group, got ' . var_export($pattern, true);
      }
      // NULL would wipe the whole text; replace swaps only the dead ID for one that matches nothing
      $allowed = ['error', 'replace'];
    } else {
      $allowed = ['error', 'null'];
    }
    if (!in_array($onMissing, $allowed, true)) {
      $problems['on_missing'] = 'must be ' . implode(' or ', $allowed) . ($pattern !== null ? ' with a pattern' : '')
        . ', got ' . var_export($onMissing, true);
    }

    return $problems;
  }

  /**
   * on_missing, defaulting to error; a bare YAML `on_missing: null` parses to PHP null and means 'null'.
   */
  private static function onMissing(array $ref): mixed
  {
    return array_key_exists('on_missing', $ref) ? ($ref['on_missing'] ?? 'null') : 'error';
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
        throw new \InvalidArgumentException("joins.{$key}.table: required");
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
