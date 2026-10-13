<?php

declare(strict_types=1);

namespace Mygra\Migration;

/**
 * How one schema's IDs are mapped while it migrates or verifies.
 */
final class IdContext
{
  /**
   * @param ?string $kind null (IDs kept), 'offset' or 'uuid'
   * @param ?string $key Target column holding the schema's own key, when it is remapped
   * @param Reference[] $references
   */
  public function __construct(
    public readonly ?string $kind,
    public readonly ?int $offset,
    public readonly ?string $key,
    public readonly \Closure $map,
    public readonly array $references,
  ) {}

  /**
   * The row with its own key mapped to the new ID.
   */
  public function mapKey(array $row): array
  {
    if ($this->kind !== null && $row[$this->key] !== null) {
      $row[$this->key] = ($this->map)($row[$this->key]);
    }
    return $row;
  }
}
