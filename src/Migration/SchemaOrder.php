<?php

declare(strict_types=1);

namespace Mygra\Migration;

use Mygra\Adapters\AdapterInterface;
use Mygra\Config\SchemaConfig;

/**
 * Schema Order
 *
 * Orders schemas so parents migrate before children. A schema depends on another when
 * its source table has a foreign key to the other's source table, or when it lists the
 * other in `options.depends_on` (for sources without declared foreign keys, e.g. MyISAM)
 * or in `references`.
 */
class SchemaOrder
{
  /**
   * @param SchemaConfig[] $schemas In config order
   * @return array{0: SchemaConfig[], 1: string[], 2: array<string, string[]>}
   *   [ordered schemas, names of schemas in a dependency cycle, name => names it depends on]
   */
  public static function sort(array $schemas, AdapterInterface $source): array
  {
    $byName = [];
    $byTable = [];
    foreach ($schemas as $schema) {
      $byName[$schema->name] = $schema;
      $byTable[$schema->sourceTable][] = $schema->name;
    }

    // name => names it depends on
    $deps = [];
    foreach ($schemas as $schema) {
      $deps[$schema->name] = [];

      foreach ($source->getForeignKeys($schema->sourceTable) as $fk) {
        foreach ($byTable[$fk['referencedTable']] ?? [] as $parent) {
          $deps[$schema->name][] = $parent;
        }
      }

      $declared = [...(array) $schema->getOption('depends_on', []), ...array_column($schema->references, 'schema')];
      foreach ($declared as $parent) {
        if (!isset($byName[$parent])) {
          throw new \InvalidArgumentException("Schema '{$schema->name}' depends_on unknown schema '{$parent}'");
        }
        $deps[$schema->name][] = $parent;
      }

      // Self-references (e.g. parent_id) don't constrain order
      $deps[$schema->name] = array_diff(array_unique($deps[$schema->name]), [$schema->name]);
    }

    // Repeatedly take the first schema (in config order) whose parents are all placed
    $ordered = [];
    $pending = $deps;
    while ($pending) {
      $ready = array_key_first(array_filter($pending, fn($parents) => !array_diff($parents, array_keys($ordered))));
      if ($ready === null) {
        break;
      }
      $ordered[$ready] = $byName[$ready];
      unset($pending[$ready]);
    }

    // Whatever is left is in a cycle: keep config order, report it
    $cyclic = array_keys($pending);
    foreach ($cyclic as $name) {
      $ordered[$name] = $byName[$name];
    }

    return [array_values($ordered), $cyclic, $deps];
  }
}
