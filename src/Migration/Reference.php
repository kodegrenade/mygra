<?php

declare(strict_types=1);

namespace Mygra\Migration;

use Mygra\Adapters\AdapterInterface;

/**
 * One resolved `references` entry: where its IDs are (a column, or inside its text via a pattern),
 * how to map them to the parent's new IDs, and where to check that the parent row exists.
 */
final class Reference
{
  public function __construct(
    public readonly string $name,
    public readonly string $column,
    public readonly string $schema,
    /** error | null | replace */
    public readonly string $onMissing,
    public readonly ?string $pattern,
    public readonly ?string $replacement,
    /** Old ID -> new ID */
    public readonly \Closure $map,
    /** Points at its own schema: existence is checked in the source, the parent may come later in the run */
    public readonly bool $self,
    public readonly AdapterInterface $db,
    public readonly string $table,
    public readonly string $key,
  ) {}
}
