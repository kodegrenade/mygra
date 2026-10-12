<?php

declare(strict_types=1);

namespace Mygra\Tests\Unit\Migration;

use PHPUnit\Framework\TestCase;
use Mygra\Adapters\SQLiteAdapter;
use Mygra\Config\SchemaConfig;
use Mygra\Migration\SchemaOrder;

class SchemaOrderTest extends TestCase
{
  private SQLiteAdapter $source;

  protected function setUp(): void
  {
    $this->source = new SQLiteAdapter();
    $this->source->connect(['database' => ':memory:']);
    $this->source->execute("CREATE TABLE users (id INTEGER PRIMARY KEY)");
    $this->source->execute("CREATE TABLE posts (id INTEGER PRIMARY KEY, author_id INT REFERENCES users(id))");
    $this->source->execute("CREATE TABLE comments (id INTEGER PRIMARY KEY, post_id INT REFERENCES posts(id))");
    $this->source->execute("CREATE TABLE categories (id INTEGER PRIMARY KEY, parent_id INT REFERENCES categories(id))");
    $this->source->execute("CREATE TABLE tags (id INTEGER PRIMARY KEY)");
  }

  private function schema(string $table, array $options = []): SchemaConfig
  {
    return SchemaConfig::fromArray($table, ['source_table' => $table, 'target_table' => $table, 'options' => $options]);
  }

  private function names(array $schemas): array
  {
    return array_map(fn($s) => $s->name, $schemas);
  }

  public function testForeignKeysAndDependsOnPutParentsFirst()
  {
    [$ordered, $cyclic] = SchemaOrder::sort([
      $this->schema('comments'),
      $this->schema('tags', ['depends_on' => ['comments']]), // no FK declared, explicit dependency
      $this->schema('posts'),
      $this->schema('categories'), // self-reference must not block it
      $this->schema('users'),
    ], $this->source);

    $this->assertSame(['categories', 'users', 'posts', 'comments', 'tags'], $this->names($ordered));
    $this->assertSame([], $cyclic);
  }

  public function testCycleIsReportedAndKeptInConfigOrder()
  {
    [$ordered, $cyclic] = SchemaOrder::sort([
      $this->schema('users', ['depends_on' => ['posts']]), // posts -> users FK closes the loop
      $this->schema('posts'),
      $this->schema('tags'),
    ], $this->source);

    $this->assertSame(['tags', 'users', 'posts'], $this->names($ordered));
    $this->assertSame(['users', 'posts'], $cyclic);
  }

  public function testUnknownDependsOnFails()
  {
    $this->expectExceptionMessage("depends_on unknown schema 'user'");
    SchemaOrder::sort([$this->schema('posts', ['depends_on' => ['user']])], $this->source);
  }
}
