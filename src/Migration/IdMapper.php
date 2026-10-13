<?php

declare(strict_types=1);

namespace Mygra\Migration;

use Mygra\Adapters\AdapterInterface;
use Mygra\Config\SchemaConfig;
use Mygra\Support\Uuid;
use Psr\Log\LoggerInterface;

/**
 * New IDs and the references that must follow them.
 *
 *   remap_ids: true / offset   new ID = old ID + an offset fixed on the schema's first run
 *   remap_ids: uuid            new ID = UUIDv5 of "<source table>:<old id>"
 *
 * References are checked against their parent and rewritten per batch, with one lookup query
 * per reference rather than per row.
 */
final class IdMapper
{
  /**
   * @param array<string, SchemaConfig> $schemas All schemas, so references can find their parent
   */
  public function __construct(
    private readonly AdapterInterface $source,
    private readonly AdapterInterface $target,
    private readonly SourceReader $reader,
    private readonly OffsetStore $offsets,
    private readonly array $schemas,
    private readonly LoggerInterface $logger,
    private readonly bool $dryRun = false,
  ) {}

  /**
   * remap_ids: true / 'offset' or 'uuid'; null = IDs are kept.
   */
  public static function kind(SchemaConfig $schema): ?string
  {
    return match ($schema->getOption('remap_ids', false)) {
      false, null => null,
      true, 'offset' => 'offset',
      'uuid' => 'uuid',
      default => throw new \InvalidArgumentException("Schema '{$schema->name}': remap_ids must be true, offset or uuid"),
    };
  }

  /**
   * @param bool $prepare true while migrating: fix the offset on the first run and reserve the ID range
   */
  public function context(SchemaConfig $schema, bool $prepare): IdContext
  {
    $kind = self::kind($schema);
    $key = $kind ? $this->targetKeyColumn($schema) : null;
    $offset = $kind === 'offset'
      ? ($prepare ? $this->prepareOffset($schema, $key) : $this->requireOffset($schema->name, 'run migrate first'))
      : null;
    $map = $this->map($schema, $offset);

    $references = [];
    foreach ($schema->references as $name => $ref) {
      $self = $ref['schema'] === $schema->name;
      $parent = $self ? $schema : ($this->schemas[$ref['schema']]
        ?? throw new \InvalidArgumentException("Schema '{$schema->name}' references unknown schema '{$ref['schema']}'"));

      $parentOffset = null;
      if (self::kind($parent) === 'offset') {
        $parentOffset = $self ? $offset : $this->requireOffset($parent->name, "migrate it before '{$schema->name}'");
      }

      if ($ref['on_missing'] === 'replace') {
        $this->assertReplacementMatchesNothing($name, $ref['replacement'], $parent, $parentOffset);
      }

      // A self-reference may point at a row later in this same run, so check the source instead
      $db = $self ? $this->source : $this->target;
      $table = $self ? $parent->sourceTable : $parent->targetTable;
      $references[] = new Reference(
        name: $name,
        column: $ref['column'],
        schema: $ref['schema'],
        onMissing: $ref['on_missing'],
        pattern: $ref['pattern'],
        replacement: $ref['replacement'],
        map: $self ? $map : $this->map($parent, $parentOffset),
        self: $self,
        db: $db,
        table: $table,
        key: Keys::requireSingle($db, $table, 'A reference'),
      );
    }

    return new IdContext($kind, $offset, $key, $map, $references);
  }

  /**
   * Check each reference against its parent and rewrite it to the parent's new ID.
   * A reference with no parent makes the row an orphan (on_missing: error), becomes NULL
   * (on_missing: null), or, inside text, is swapped for a value that matches nothing (on_missing: replace).
   *
   * @return array{0: array, 1: list<array{0: array, 1: string}>, 2: int}
   *   [rows to load, orphans with messages, dead IDs replaced inside text]
   */
  public function resolve(array $rows, IdContext $ids): array
  {
    $orphans = [];
    $replaced = 0;

    foreach ($ids->references as $ref) {
      $column = $ref->column;
      $toNew = $ref->map;

      // The IDs a row holds: the column itself, or every ID the pattern finds in its text
      $idsIn = fn($value) => match (true) {
        $value === null => [],
        $ref->pattern === null => [$value],
        default => self::idsInText($ref->pattern, (string) $value),
      };
      $old = array_values(array_unique(array_merge([], ...array_map(fn($row) => $idsIn($row[$column] ?? null), $rows))));
      $found = $this->existing($ref, $old);

      foreach ($rows as $i => $row) {
        $value = $row[$column] ?? null;
        if ($value === null) {
          continue;
        }

        if ($ref->pattern === null) {
          if (isset($found[(string) $value])) {
            $rows[$i][$column] = $toNew($value);
          } elseif ($ref->onMissing === 'null') {
            $rows[$i][$column] = null;
          } else {
            $orphans[] = [$row, "{$column} {$value} has no matching row in {$ref->schema}"];
            unset($rows[$i]);
          }
          continue;
        }

        $dead = [];
        $rows[$i][$column] = self::rewriteIdsInText($ref->pattern, (string) $value, function (string $id) use ($found, $toNew, $ref, &$dead): string {
          if (isset($found[$id])) {
            return (string) $toNew($id);
          }
          $dead[] = $id;
          return $ref->replacement;
        });

        if ($dead && $ref->onMissing === 'replace') {
          $replaced += count($dead);
        } elseif ($dead) {
          $orphans[] = [$row, "{$column} contains IDs with no matching row in {$ref->schema}: " . implode(', ', $dead)];
          unset($rows[$i]);
        }
      }
    }

    return [array_values($rows), $orphans, $replaced];
  }

  /**
   * Target columns that will hold UUIDs: this schema's key with remap_ids: uuid, and plain
   * references to such schemas. Tables Mygra creates give them a UUID type.
   *
   * @return string[]
   */
  public function uuidColumns(SchemaConfig $schema): array
  {
    $columns = self::kind($schema) === 'uuid' ? [$this->targetKeyColumn($schema)] : [];
    foreach ($schema->references as $ref) {
      $parent = $ref['schema'] === $schema->name ? $schema : ($this->schemas[$ref['schema']] ?? null);
      if ($ref['pattern'] === null && $parent && self::kind($parent) === 'uuid') {
        $columns[] = $ref['column'];
      }
    }
    return $columns;
  }

  /**
   * The highest old ID already migrated, for resume. With remapped IDs only Mygra's own range
   * counts: rows the application inserted meanwhile sit outside it.
   */
  public function lastMigratedKey(SchemaConfig $schema, IdContext $ids): mixed
  {
    if ($ids->kind === 'uuid') {
      throw new \InvalidArgumentException(
        "Resume needs ordered IDs; '{$schema->name}' uses UUIDs. Rerun with --upsert instead: the UUIDs are deterministic, so it is safe",
      );
    }

    $sourceKey = Keys::single($this->source, $schema->sourceTable);
    $targetKey = $sourceKey === null ? null : ($schema->fields[$sourceKey] ?? null);
    if ($targetKey === null || isset($schema->transforms[$targetKey])) {
      throw new \InvalidArgumentException(
        "Resume needs a single-column source primary key mapped unchanged to the target (schema '{$schema->name}')",
      );
    }

    $q = $this->target->quoteIdentifier($targetKey);
    $sql = "SELECT MAX({$q}) AS last_key FROM {$this->target->quoteTable($schema->targetTable)}";
    $params = [];
    if ($ids->offset !== null) {
      $range = $this->reader->keyRange($schema);
      $sql .= " WHERE {$q} BETWEEN ? AND ?";
      $params = [(int) $range['lo'] + $ids->offset, (int) $range['hi'] + $ids->offset];
    }

    $last = $this->target->query($sql, $params)[0]['last_key'] ?? null;
    return $last !== null && $ids->offset !== null ? (int) $last - $ids->offset : $last;
  }

  /**
   * Fix the schema's offset on its first run (new IDs start right after the target's highest),
   * and reserve its ID range so a live application cannot take IDs Mygra will insert.
   * Source rows added after the first run extend the range, but only into IDs that are still free.
   */
  private function prepareOffset(SchemaConfig $schema, string $targetKey): int
  {
    $range = $this->reader->keyRange($schema);
    $stored = $this->offsets->get($schema->name);
    $q = $this->target->quoteIdentifier($targetKey);
    $table = $this->target->quoteTable($schema->targetTable);

    if ($stored === null) {
      $targetMax = $this->target->query("SELECT MAX({$q}) AS m FROM {$table}")[0]['m'] ?? null;
      $offset = $range['lo'] === null ? 0 : (int) ($targetMax ?? 0) - (int) $range['lo'] + 1;
      $this->offsets->save($schema->name, $offset, $range['hi'] === null ? null : (int) $range['hi'] + $offset);
      $stored = $this->offsets->get($schema->name);
    }
    $offset = $stored['offset'];

    if ($range['hi'] === null) {
      return $offset;
    }

    $needed = (int) $range['hi'] + $offset;
    $reserved = $stored['reserved_until'];
    if ($reserved !== null && $needed > $reserved) {
      $taken = (int) ($this->target->query("SELECT COUNT(*) AS n FROM {$table} WHERE {$q} > ? AND {$q} <= ?", [$reserved, $needed])[0]['n'] ?? 0);
      if ($taken > 0) {
        throw new \RuntimeException(
          "Rows were added to {$schema->sourceTable} since the first run. Their new IDs (" . ($reserved + 1) . "–{$needed}) "
          . "overlap {$taken} row(s) the application created in {$schema->targetTable} meanwhile, so they cannot be migrated with the same offset",
        );
      }
    }
    if ($reserved === null || $needed > $reserved) {
      $this->offsets->save($schema->name, $offset, $needed);
    }

    if (!$this->dryRun) {
      $this->target->reserveIds($schema->targetTable, $needed + 1);
    }
    $this->logger->info("{$schema->name}: new ID = old ID + {$offset}");

    return $offset;
  }

  private function requireOffset(string $schema, string $hint): int
  {
    return $this->offsets->get($schema)['offset']
      ?? throw new \RuntimeException("Schema '{$schema}' has no ID offset yet; {$hint}");
  }

  /**
   * Old ID -> new ID for a schema. Identity when its IDs are kept.
   */
  private function map(SchemaConfig $schema, ?int $offset): \Closure
  {
    if (self::kind($schema) === 'uuid') {
      $namespace = $schema->getOption('uuid_namespace', Uuid::MYGRA_NAMESPACE);
      if (!Uuid::isValid($namespace)) {
        throw new \InvalidArgumentException("Schema '{$schema->name}': uuid_namespace must be a UUID");
      }
      // Source table, not schema name: the same row keeps its UUID even if the config key is renamed
      return fn($old) => Uuid::v5($namespace, "{$schema->sourceTable}:{$old}");
    }
    // Offset 0 = parent keeps its IDs; leave values (possibly string keys) untouched
    return $offset ? fn($old) => (int) $old + $offset : fn($old) => $old;
  }

  /**
   * Old IDs whose parent row exists, as a set. One query per 1000 IDs (SQL Server's parameter limit).
   *
   * @return array<string, true>
   */
  private function existing(Reference $ref, array $old): array
  {
    $found = [];
    $key = $ref->db->quoteIdentifier($ref->key);
    foreach (array_chunk($old, 1000) as $chunk) {
      $lookup = $ref->self ? $chunk : array_map($ref->map, $chunk);
      // Looked-up value -> old ID; lowercased because SQL Server returns UUIDs in upper case
      $oldOf = array_combine(array_map(fn($v) => strtolower((string) $v), $lookup), $chunk);
      $placeholders = implode(', ', array_fill(0, count($lookup), '?'));
      $sql = "SELECT {$key} AS k FROM {$ref->db->quoteTable($ref->table)} WHERE {$key} IN ({$placeholders})";
      foreach ($ref->db->query($sql, $lookup) as $hit) {
        $found[(string) $oldOf[strtolower((string) $hit['k'])]] = true;
      }
    }
    return $found;
  }

  /**
   * on_missing: replace must never write a value that points at a real parent row,
   * neither one already in the target nor one this migration will create.
   */
  private function assertReplacementMatchesNothing(string $name, string $replacement, SchemaConfig $parent, ?int $offset): void
  {
    $candidates = [];
    if (self::kind($parent) === 'uuid') {
      // Only a UUID can match a UUID key; one Mygra derives can't be predicted, so check the target only
      if (Uuid::isValid($replacement)) {
        $candidates[] = [$this->target, $parent->targetTable, $replacement];
      }
    } else {
      $candidates[] = [$this->target, $parent->targetTable, $replacement];
      if (is_numeric($replacement)) {
        $candidates[] = [$this->source, $parent->sourceTable, (string) ((int) $replacement - (int) $offset)];
      }
    }

    foreach ($candidates as [$db, $table, $value]) {
      if (!in_array($table, $db->getTables(), true)) {
        continue;
      }
      $key = $db->quoteIdentifier(Keys::requireSingle($db, $table, 'on_missing: replace'));
      if ($db->query("SELECT {$key} AS k FROM {$db->quoteTable($table)} WHERE {$key} = ?", [$value])) {
        throw new \InvalidArgumentException(
          "references.{$name}: replacement '{$replacement}' would point at a real {$parent->name} row; choose another replacement",
        );
      }
    }
  }

  /**
   * The target column holding this schema's single-column source primary key.
   */
  private function targetKeyColumn(SchemaConfig $schema): string
  {
    $pk = Keys::requireSingle($this->source, $schema->sourceTable, 'remap_ids');
    return $schema->fields[$pk]
      ?? throw new \InvalidArgumentException("remap_ids needs the primary key '{$pk}' in the fields of '{$schema->name}'");
  }

  /**
   * IDs a reference pattern finds in text: capture group 1 holds one ID or a comma-separated list.
   *
   * @return string[]
   */
  private static function idsInText(string $pattern, string $text): array
  {
    preg_match_all($pattern, $text, $matches);
    $ids = [];
    foreach ($matches[1] as $list) {
      foreach (explode(',', $list) as $id) {
        if (($id = trim($id)) !== '') {
          $ids[] = $id;
        }
      }
    }
    return $ids;
  }

  /**
   * Replace each ID inside the pattern's capture group with $map(id), leaving all other text as it was.
   */
  private static function rewriteIdsInText(string $pattern, string $text, callable $map): string
  {
    return preg_replace_callback($pattern, function (array $m) use ($map): string {
      // PHPStan's preg_replace_callback stub ignores PREG_OFFSET_CAPTURE, which makes each group [text, byte offset]
      /** @var array<int, array{0: string, 1: int}> $m */ // @phpstan-ignore varTag.nativeType
      [$full, $fullAt] = $m[0];
      [$group, $groupAt] = $m[1];
      if ($groupAt < 0) {
        return $full; // group did not take part in this match
      }

      $parts = array_map(function (string $part) use ($map): string {
        $id = trim($part);
        return $id === '' ? $part : substr_replace($part, $map($id), strpos($part, $id), strlen($id));
      }, explode(',', $group));

      return substr_replace($full, implode(',', $parts), $groupAt - $fullAt, strlen($group));
    }, $text, -1, $count, PREG_OFFSET_CAPTURE);
  }
}
