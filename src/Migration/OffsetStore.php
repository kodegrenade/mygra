<?php

declare(strict_types=1);

namespace Mygra\Migration;

use Mygra\Adapters\AdapterInterface;

/**
 * Mygra's ID bookkeeping in the target: per schema, the offset its IDs were shifted by and the
 * highest ID reserved for it. Kept until `mygra finish`, so resume, verify and --upsert reruns
 * keep using the same IDs. In a dry run nothing is written; values live in memory only.
 */
final class OffsetStore
{
  public const TABLE = 'mygra_offsets';

  /** @var array<string, array{offset: int, reserved_until: ?int}> */
  private array $cache = [];

  private ?bool $tableExists = null;

  /** Tables written by earlier versions have no reserved_until column until the next save() */
  private ?bool $hasReservedColumn = null;

  public function __construct(
    private readonly AdapterInterface $target,
    private readonly bool $dryRun = false,
  ) {}

  /**
   * @return array{offset: int, reserved_until: ?int}|null
   */
  public function get(string $schema): ?array
  {
    if (!isset($this->cache[$schema]) && $this->exists()) {
      $q = [$this->target, 'quoteIdentifier'];
      $reserved = $this->hasReservedColumn() ? $q('reserved_until') : 'NULL';
      $row = $this->target->query(
        "SELECT {$q('id_offset')} AS id_offset, {$reserved} AS reserved_until"
          . " FROM {$this->target->quoteTable(self::TABLE)} WHERE {$q('schema_name')} = ?",
        [$schema],
      )[0] ?? null;
      if ($row !== null) {
        $this->cache[$schema] = [
          'offset' => (int) $row['id_offset'],
          'reserved_until' => $row['reserved_until'] === null ? null : (int) $row['reserved_until'],
        ];
      }
    }
    return $this->cache[$schema] ?? null;
  }

  public function save(string $schema, int $offset, ?int $reservedUntil): void
  {
    $known = $this->get($schema) !== null;
    $this->cache[$schema] = ['offset' => $offset, 'reserved_until' => $reservedUntil];
    if ($this->dryRun) {
      return;
    }

    $this->ensureTable();
    $q = [$this->target, 'quoteIdentifier'];
    $table = $this->target->quoteTable(self::TABLE);
    $known
      ? $this->target->execute("UPDATE {$table} SET {$q('id_offset')} = ?, {$q('reserved_until')} = ? WHERE {$q('schema_name')} = ?", [$offset, $reservedUntil, $schema])
      : $this->target->execute("INSERT INTO {$table} ({$q('schema_name')}, {$q('id_offset')}, {$q('reserved_until')}) VALUES (?, ?, ?)", [$schema, $offset, $reservedUntil]);
  }

  /**
   * @return bool Whether there was anything to remove
   */
  public function drop(): bool
  {
    $this->cache = [];
    if (!$this->exists()) {
      return false;
    }
    $this->target->execute("DROP TABLE {$this->target->quoteTable(self::TABLE)}");
    $this->tableExists = false;
    return true;
  }

  private function exists(): bool
  {
    return $this->tableExists ??= in_array(self::TABLE, $this->target->getTables(), true);
  }

  private function hasReservedColumn(): bool
  {
    return $this->hasReservedColumn ??= $this->target->getTableSchema(self::TABLE)->hasColumn('reserved_until');
  }

  private function ensureTable(): void
  {
    $q = [$this->target, 'quoteIdentifier'];
    $table = $this->target->quoteTable(self::TABLE);

    if (!$this->exists()) {
      $this->target->execute(
        "CREATE TABLE {$table} ({$q('schema_name')} VARCHAR(255) NOT NULL PRIMARY KEY,"
          . " {$q('id_offset')} BIGINT NOT NULL, {$q('reserved_until')} BIGINT NULL)",
      );
      $this->tableExists = true;
      $this->hasReservedColumn = true;
    } elseif (!$this->hasReservedColumn()) {
      // Created by an earlier version; ADD without COLUMN is accepted by all four databases
      $this->target->execute("ALTER TABLE {$table} ADD {$q('reserved_until')} BIGINT NULL");
      $this->hasReservedColumn = true;
    }
  }
}
