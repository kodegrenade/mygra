<?php

declare(strict_types=1);

namespace Mygra\Migration;

use Mygra\Adapters\AdapterInterface;

/**
 * Primary-key lookups shared by reading, resuming and ID mapping.
 */
final class Keys
{
  /**
   * The table's single-column primary key, or null when it has none or a composite one.
   */
  public static function single(AdapterInterface $db, string $table): ?string
  {
    $pk = $db->getPrimaryKey($table);
    return is_string($pk) ? $pk : null;
  }

  /**
   * @param string $purpose What needs the key, for the error message
   */
  public static function requireSingle(AdapterInterface $db, string $table, string $purpose): string
  {
    return self::single($db, $table)
      ?? throw new \InvalidArgumentException("{$purpose} needs a single-column primary key on '{$table}'");
  }
}
