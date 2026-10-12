<?php

declare(strict_types=1);

namespace Mygra\Tests\Unit\Migration;

use PHPUnit\Framework\TestCase;
use Mygra\Adapters\SQLiteAdapter;
use Mygra\Config\SchemaConfig;
use Mygra\Migration\MigrationExecutor;

/**
 * Migrating into an app that already has users: new IDs = old + offset, references follow.
 */
class IdMappingTest extends TestCase
{
  private SQLiteAdapter $source;
  private SQLiteAdapter $target;
  private MigrationExecutor $executor;
  private array $schemas;

  protected function setUp(): void
  {
    $this->source = new SQLiteAdapter();
    $this->source->connect(['database' => ':memory:']);
    $this->source->execute("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)");
    $this->source->execute("INSERT INTO users VALUES (1, 'ada'), (2, 'bo'), (3, 'cy')");
    $this->source->execute("CREATE TABLE posts (id INTEGER PRIMARY KEY, author_id INT)");
    $this->source->execute("INSERT INTO posts VALUES (1, 1), (2, 3), (3, 99), (4, NULL)"); // 99: deleted user
    $this->source->execute("CREATE TABLE categories (id INTEGER PRIMARY KEY, parent_id INT)");
    $this->source->execute("INSERT INTO categories VALUES (1, NULL), (2, 1), (3, 2), (4, 50)"); // 50: gone

    // The app already has two users and one category
    $this->target = new SQLiteAdapter();
    $this->target->connect(['database' => ':memory:']);
    $this->target->execute("CREATE TABLE app_users (id INTEGER PRIMARY KEY, name TEXT)");
    $this->target->execute("INSERT INTO app_users VALUES (1, 'x'), (2, 'y')");
    $this->target->execute("CREATE TABLE app_posts (id INTEGER PRIMARY KEY, author_id INT)");
    $this->target->execute("CREATE TABLE app_categories (id INTEGER PRIMARY KEY, parent_id INT)");
    $this->target->execute("INSERT INTO app_categories VALUES (1, NULL)");

    $this->schemas = [
      'users' => SchemaConfig::fromArray('users', [
        'source_table' => 'users', 'target_table' => 'app_users',
        'fields' => ['id' => 'id', 'name' => 'name'],
        'options' => ['remap_ids' => true],
      ]),
      'posts' => SchemaConfig::fromArray('posts', [
        'source_table' => 'posts', 'target_table' => 'app_posts',
        'fields' => ['id' => 'id', 'author_id' => 'author_id'],
        'references' => ['author_id' => 'users'],
        'options' => ['remap_ids' => true],
      ]),
      'categories' => SchemaConfig::fromArray('categories', [
        'source_table' => 'categories', 'target_table' => 'app_categories',
        'fields' => ['id' => 'id', 'parent_id' => 'parent_id'],
        'references' => ['parent_id' => ['schema' => 'categories', 'on_missing' => 'null']],
        'options' => ['remap_ids' => true],
      ]),
    ];

    $this->executor = (new MigrationExecutor($this->source, $this->target))->setSchemas($this->schemas);
  }

  private function rows(string $sql): array
  {
    return $this->target->query($sql);
  }

  public function testReferencesFollowTheirParentsNewIds()
  {
    $users = $this->executor->execute($this->schemas['users']);
    $this->assertSame(2, $users->getMetadata('id_offset'));
    $this->assertSame(
      [['id' => 1, 'name' => 'x'], ['id' => 2, 'name' => 'y'], ['id' => 3, 'name' => 'ada'], ['id' => 4, 'name' => 'bo'], ['id' => 5, 'name' => 'cy']],
      $this->rows("SELECT id, name FROM app_users ORDER BY id")
    );

    // Empty target table: offset 0. Post 2 was by cy (old 3, new 5); post 3's author is gone
    $posts = $this->executor->execute($this->schemas['posts']);
    $this->assertSame(0, $posts->getMetadata('id_offset'));
    $this->assertSame(1, $posts->getErrorCount());
    $this->assertStringContainsString('author_id 99 has no matching row in users', $posts->getErrors()[0]['error']);
    $this->assertSame(
      [['id' => 1, 'author_id' => 3], ['id' => 2, 'author_id' => 5], ['id' => 4, 'author_id' => null]],
      $this->rows("SELECT id, author_id FROM app_posts ORDER BY id")
    );

    // Self-reference: parents shift with their children; the missing one becomes NULL (on_missing: null)
    $this->executor->execute($this->schemas['categories']);
    $this->assertSame(
      [['id' => 1, 'parent_id' => null], ['id' => 2, 'parent_id' => null], ['id' => 3, 'parent_id' => 2], ['id' => 4, 'parent_id' => 3], ['id' => 5, 'parent_id' => null]],
      $this->rows("SELECT id, parent_id FROM app_categories ORDER BY id")
    );

    // verify applies the same offsets and orphan rules, so it confirms the relationships
    foreach ($this->schemas as $schema) {
      $this->assertTrue($this->executor->verify($schema)['match'], $schema->name);
    }
    $this->target->execute("UPDATE app_posts SET author_id = 4 WHERE id = 2"); // now points at bo
    $this->assertFalse($this->executor->verify($this->schemas['posts'])['match']);

    $this->assertTrue($this->executor->dropOffsets());
    $this->assertNotContains('mygra_offsets', $this->target->getTables());
  }

  public function testChildBeforeParentIsRefused()
  {
    $result = $this->executor->execute($this->schemas['posts']);

    $this->assertFalse($result->isSuccess());
    $this->assertStringContainsString("Schema 'users' has no ID offset yet", (string) $result->getError());
  }

  public function testResumeIgnoresRowsTheAppInsertedMeanwhile()
  {
    $this->executor->execute($this->schemas['users']);

    // Interrupted before cy (new ID 5) landed, and the app inserted user 100 meanwhile
    $this->target->execute("DELETE FROM app_users WHERE id = 5");
    $this->target->execute("INSERT INTO app_users VALUES (100, 'app')");

    // A fresh executor: the offset comes from mygra_offsets, not from memory
    $result = (new MigrationExecutor($this->source, $this->target))->setSchemas($this->schemas)
      ->setOptions(['resume' => true])->execute($this->schemas['users']);

    $this->assertTrue($result->isSuccess(), (string) $result->getError());
    $this->assertSame(1, $result->getTotalRows());
    $this->assertSame([['name' => 'cy']], $this->rows("SELECT name FROM app_users WHERE id = 5"));
  }
}
