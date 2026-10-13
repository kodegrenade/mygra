<?php

declare(strict_types=1);

namespace Mygra\Tests\Unit\Schema;

use Mygra\Schema\ColumnSchema;
use Mygra\Schema\SchemaDiff;
use Mygra\Schema\TableSchema;
use PHPUnit\Framework\TestCase;

class SchemaDiffTest extends TestCase
{
  public function testDiffDetectsMissingColumn()
  {
    $source = new TableSchema('users', [
      'id' => new ColumnSchema('id', 'int'),
      'name' => new ColumnSchema('name', 'string'),
    ]);

    $target = new TableSchema('users', [
      'id' => new ColumnSchema('id', 'int'),
    ]);

    $diff = new SchemaDiff();
    $results = $diff->compare($source, $target, ['id' => 'id', 'name' => 'name']);

    $this->assertCount(1, $results);
    $this->assertEquals('column', $results[0]['category']);
    $this->assertEquals('name', $results[0]['item']);
    $this->assertEquals('missing', $results[0]['type']);
  }

  public function testDiffDetectsTypeMismatch()
  {
    $source = new TableSchema('users', [
      'id' => new ColumnSchema('id', 'int'),
    ]);

    $target = new TableSchema('users', [
      'id' => new ColumnSchema('id', 'string'),
    ]);

    $diff = new SchemaDiff();
    $results = $diff->compare($source, $target, ['id' => 'id']);

    $this->assertCount(1, $results);
    $this->assertEquals('type_mismatch', $results[0]['type']);
  }
}
