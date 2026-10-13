<?php

declare(strict_types=1);

namespace Mygra\Tests\Unit\Migration;

use Mygra\Adapters\SQLiteAdapter;
use Mygra\Config\SchemaConfig;
use Mygra\Migration\MigrationExecutor;
use Mygra\Support\Uuid;
use PHPUnit\Framework\TestCase;

/**
 * Integer IDs become UUIDv5 of "<source table>:<old id>"; references follow.
 */
class UuidKeysTest extends TestCase
{
  private SQLiteAdapter $source;
  private SQLiteAdapter $target;
  private array $schemas;

  protected function setUp(): void
  {
    $this->source = new SQLiteAdapter();
    $this->source->connect(['database' => ':memory:']);
    $this->source->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
    $this->source->execute("INSERT INTO users VALUES (1, 'ada'), (2, 'bo')");
    $this->source->execute('CREATE TABLE posts (id INTEGER PRIMARY KEY, author_id INT)');
    $this->source->execute('INSERT INTO posts VALUES (10, 2), (11, 1)');

    $this->target = new SQLiteAdapter();
    $this->target->connect(['database' => ':memory:']);

    $this->schemas = [
      'users' => SchemaConfig::fromArray('users', [
        'source_table' => 'users', 'target_table' => 'app_users',
        'fields' => ['id' => 'id', 'name' => 'name'],
        'options' => ['remap_ids' => 'uuid', 'create_target' => true],
      ]),
      'posts' => SchemaConfig::fromArray('posts', [
        'source_table' => 'posts', 'target_table' => 'app_posts',
        'fields' => ['id' => 'id', 'author_id' => 'author_id'],
        'references' => ['author_id' => 'users'],
        'options' => ['remap_ids' => 'uuid', 'create_target' => true],
      ]),
    ];
  }

  private function executor(array $options = []): MigrationExecutor
  {
    return (new MigrationExecutor($this->source, $this->target))->setSchemas($this->schemas)->setOptions($options);
  }

  private function uuid(string $name): string
  {
    return Uuid::v5(Uuid::MYGRA_NAMESPACE, $name);
  }

  public function testIdsAndReferencesBecomeDeterministicUuids()
  {
    $executor = $this->executor();
    foreach ($this->schemas as $schema) {
      $result = $executor->execute($schema);
      $this->assertTrue($result->isSuccess(), (string) $result->getError());
    }

    $this->assertSame(
      [['id' => $this->uuid('posts:10'), 'author_id' => $this->uuid('users:2')], ['id' => $this->uuid('posts:11'), 'author_id' => $this->uuid('users:1')]],
      $this->target->query("SELECT id, author_id FROM app_posts ORDER BY author_id = '{$this->uuid('users:1')}'"),
    );

    // An app row Mygra didn't write is not counted by verify
    $this->target->execute("INSERT INTO app_users VALUES ('" . $this->uuid('elsewhere') . "', 'app')");
    foreach ($this->schemas as $schema) {
      $this->assertTrue($executor->verify($schema)->matches(), $schema->name);
    }

    // No offsets to keep: nothing for verify to clean up
    $this->assertFalse($executor->dropOffsets());
  }

  public function testUpsertRerunIsSafeAndResumeIsRefused()
  {
    $this->executor()->execute($this->schemas['users']);
    $this->source->execute("UPDATE users SET name = 'ada2' WHERE id = 1");

    $rerun = $this->executor(['upsert' => true])->execute($this->schemas['users']);
    $this->assertTrue($rerun->isSuccess(), (string) $rerun->getError());
    $this->assertSame(2, $this->target->getRowCount('app_users'));
    $this->assertSame('ada2', $this->target->query('SELECT name FROM app_users WHERE id = ?', [$this->uuid('users:1')])[0]['name']);

    $resume = $this->executor(['resume' => true])->execute($this->schemas['users']);
    $this->assertFalse($resume->isSuccess());
    $this->assertStringContainsString('Rerun with --upsert instead', (string) $resume->getError());
  }

  public function testNamespaceSeparatesSourcesWithTheSameTableNames()
  {
    $other = SchemaConfig::fromArray('users', [
      'source_table' => 'users', 'target_table' => 'app_users',
      'fields' => ['id' => 'id', 'name' => 'name'],
      'options' => ['remap_ids' => 'uuid', 'uuid_namespace' => '0d6c6a0e-2b1f-4f6a-8e8e-5f1c2b7d9a10'],
    ]);
    $this->executor()->execute($this->schemas['users']);
    $result = (new MigrationExecutor($this->source, $this->target))->setSchemas(['users' => $other])->execute($other);

    $this->assertTrue($result->isSuccess(), (string) $result->getError());
    $this->assertSame(4, $this->target->getRowCount('app_users'));
  }
}
