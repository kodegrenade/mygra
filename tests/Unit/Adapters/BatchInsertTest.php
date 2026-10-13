<?php

declare(strict_types=1);

namespace Mygra\Tests\Unit\Adapters;

use Mygra\Adapters\SQLiteAdapter;
use PHPUnit\Framework\TestCase;

class BatchInsertTest extends TestCase
{
  public function testWideTableStaysUnderTheParameterLimit()
  {
    // 1000 rows x 70 columns = 70,000 parameters, over SQLite's 32,766
    $columns = array_map(fn($i) => "c{$i}", range(0, 69));
    $rows = array_map(fn($r) => array_fill_keys($columns, $r), range(1, 1000));

    $db = new SQLiteAdapter();
    $db->connect(['database' => ':memory:']);
    $db->execute('CREATE TABLE wide (' . implode(', ', array_map(fn($c) => "{$c} INT", $columns)) . ')');

    $this->assertSame(1000, $db->batchInsert('wide', $columns, $rows));
    $this->assertSame(1000, $db->getRowCount('wide'));
  }
}
