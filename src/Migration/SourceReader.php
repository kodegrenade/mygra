<?php

declare(strict_types=1);

namespace Mygra\Migration;

use Mygra\Adapters\AdapterInterface;
use Mygra\Config\SchemaConfig;

/**
 * Reads a schema's rows from the source.
 *
 * Tables with a single-column primary key are read in key order, one page at a time
 * (WHERE key > last ORDER BY key LIMIT n). Nothing stays open between pages, so the same
 * connection can serve lookups mid-read (self-references on MySQL), a live source cannot
 * shift rows between pages, and resuming is just a starting key. Other tables fall back to
 * the driver's streaming query.
 */
final class SourceReader
{
  /** Column alias carrying the paging key through the SELECT */
  private const KEY = '__mygra_key';

  public function __construct(
    private readonly AdapterInterface $source,
    private readonly int $pageSize = 1000,
  ) {}

  /**
   * @param mixed $after Only rows whose key is greater (resume); requires a single-column key
   * @return \Generator<array<string, mixed>>
   */
  public function rows(SchemaConfig $schema, mixed $after = null): \Generator
  {
    [$select, $from, $where, $key] = $this->parts($schema);

    if ($key === null) {
      $pk = (array) $this->source->getPrimaryKey($schema->sourceTable);
      $order = $pk ? ' ORDER BY ' . implode(', ', array_map(fn($c) => $this->column($schema->sourceTable, $c), $pk)) : '';
      yield from $this->source->streamQuery('SELECT ' . implode(', ', $select) . " {$from}" . self::whereSql($where) . $order);
      return;
    }

    $select[] = "{$key} AS {$this->source->quoteIdentifier(self::KEY)}";
    $last = $after;
    do {
      $conditions = $where;
      $params = [];
      if ($last !== null) {
        $conditions[] = "{$key} > ?";
        $params[] = $last;
      }
      $sql = 'SELECT ' . implode(', ', $select) . " {$from}" . self::whereSql($conditions) . " ORDER BY {$key}";
      $rows = $this->source->query($this->source->limit($sql, $this->pageSize), $params);

      foreach ($rows as $row) {
        $last = $row[self::KEY];
        unset($row[self::KEY]);
        yield $row;
      }
    } while (count($rows) === $this->pageSize);
  }

  /**
   * How many rows rows() yields, joins and filters included.
   */
  public function count(SchemaConfig $schema, mixed $after = null): int
  {
    [, $from, $where, $key] = $this->parts($schema);
    $params = [];
    if ($after !== null && $key !== null) {
      $where[] = "{$key} > ?";
      $params[] = $after;
    }
    return (int) ($this->source->query('SELECT COUNT(*) AS cnt ' . $from . self::whereSql($where), $params)[0]['cnt'] ?? 0);
  }

  /**
   * @return array{lo: mixed, hi: mixed} Lowest and highest primary key in the source table (NULL when empty)
   */
  public function keyRange(SchemaConfig $schema): array
  {
    $pk = $this->source->quoteIdentifier(Keys::requireSingle($this->source, $schema->sourceTable, 'ID mapping'));
    return $this->source->query("SELECT MIN({$pk}) AS lo, MAX({$pk}) AS hi FROM {$this->source->quoteTable($schema->sourceTable)}")[0];
  }

  /**
   * @return array{0: string[], 1: string, 2: string[], 3: ?string} [select list, FROM ... JOIN ..., conditions, quoted paging key]
   */
  private function parts(SchemaConfig $schema): array
  {
    $q = [$this->source, 'quoteIdentifier'];
    $table = $q($schema->sourceTable);

    $select = array_map(fn($field) => "{$table}.{$q($field)}", array_keys($schema->fields));
    $from = "FROM {$this->source->quoteTable($schema->sourceTable)} AS {$table}";
    $aliases = [$schema->sourceTable => true];
    $from .= $this->joins($table, $schema->joins, $select, $aliases);

    $key = Keys::single($this->source, $schema->sourceTable);

    return [$select, $from, $schema->where ? ["({$schema->where})"] : [], $key === null ? null : "{$table}.{$q($key)}"];
  }

  /**
   * JOIN clauses, recursively. A joined table is aliased as itself, so a join's `where` can say
   * `categories.visible = 1`; joining the same table again gets `categories_2`, and so on.
   *
   * @param array<array-key, true> $aliases Aliases already in use
   */
  private function joins(string $quotedBase, array $joins, array &$select, array &$aliases): string
  {
    $q = [$this->source, 'quoteIdentifier'];
    $sql = '';

    foreach ($joins as $join) {
      $type = strtoupper($join['type']);
      if (!in_array($type, ['INNER', 'LEFT', 'RIGHT', 'FULL', 'LEFT OUTER', 'RIGHT OUTER', 'FULL OUTER'], true)) {
        throw new \InvalidArgumentException("Unknown join type '{$join['type']}' for table '{$join['table']}'");
      }

      $alias = $join['table'];
      for ($n = 2; isset($aliases[$alias]); $n++) {
        $alias = "{$join['table']}_{$n}";
      }
      $aliases[$alias] = true;
      $quotedAlias = $q($alias);

      $sql .= " {$type} JOIN {$this->source->quoteTable($join['table'])} AS {$quotedAlias}"
        . " ON {$quotedBase}.{$q($join['source_key'])} = {$quotedAlias}.{$q($join['target_key'])}"
        . (!empty($join['where']) ? " AND ({$join['where']})" : '');

      foreach ($join['fields'] as $srcField => $targetField) {
        $select[] = "{$quotedAlias}.{$q($srcField)} AS {$q($targetField)}";
      }

      $sql .= $this->joins($quotedAlias, $join['join'] ?? [], $select, $aliases);
    }

    return $sql;
  }

  private function column(string $table, string $column): string
  {
    return $this->source->quoteIdentifier($table) . '.' . $this->source->quoteIdentifier($column);
  }

  private static function whereSql(array $conditions): string
  {
    return $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
  }
}
