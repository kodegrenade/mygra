<?php

declare(strict_types=1);

namespace Mygra\Tests\Unit\Migration;

use PHPUnit\Framework\TestCase;
use Mygra\Adapters\SQLiteAdapter;
use Mygra\Config\SchemaConfig;
use Mygra\Migration\MigrationExecutor;

/**
 * IDs inside text (links, ID lists) follow their rows to new IDs.
 */
class TextReferencesTest extends TestCase
{
  private SQLiteAdapter $source;
  private SQLiteAdapter $target;

  protected function setUp(): void
  {
    $this->source = new SQLiteAdapter();
    $this->source->connect(['database' => ':memory:']);
    $this->source->execute("CREATE TABLE posts (id INTEGER PRIMARY KEY, body TEXT)");
    $this->source->execute("INSERT INTO posts VALUES (1, 'Read /?p=2 next'), (2, 'Back to /?p=1, see ids=\"1, 2\"'), (3, 'Dead /?p=99 and /?p=1')");

    // The app already has post 1, so migrated posts shift by 1
    $this->target = new SQLiteAdapter();
    $this->target->connect(['database' => ':memory:']);
    $this->target->execute("CREATE TABLE app_posts (id INTEGER PRIMARY KEY, body TEXT)");
    $this->target->execute("INSERT INTO app_posts VALUES (1, 'existing')");
  }

  private function migrate(array $linkRef): array
  {
    $schema = SchemaConfig::fromArray('posts', [
      'source_table' => 'posts',
      'target_table' => 'app_posts',
      'fields' => ['id' => 'id', 'body' => 'body'],
      'options' => ['remap_ids' => true],
      'references' => [
        'links' => ['column' => 'body', 'schema' => 'posts', 'pattern' => '/\?p=(\d+)/'] + $linkRef,
        'lists' => ['column' => 'body', 'schema' => 'posts', 'pattern' => '/ids="([^"]*)"/'],
      ],
    ]);
    $executor = (new MigrationExecutor($this->source, $this->target))->setSchemas([$schema]);
    return [$executor->execute($schema), $executor, $schema];
  }

  private function bodies(): array
  {
    return array_column($this->target->query("SELECT body FROM app_posts WHERE id > 1 ORDER BY id"), 'body', null);
  }

  public function testDeadIdFailsItsRowByDefault()
  {
    [$result] = $this->migrate([]);

    $this->assertSame(1, $result->getErrorCount());
    $this->assertStringContainsString('body contains IDs with no matching row in posts: 99', $result->getErrors()[0]['error']);
    // Two references in one column, a list keeps its spacing
    $this->assertSame(['Read /?p=3 next', 'Back to /?p=2, see ids="2, 3"'], $this->bodies());
  }

  public function testReplaceSwapsOnlyTheDeadIdAndVerifyAgrees()
  {
    [$result, $executor, $schema] = $this->migrate(['on_missing' => 'replace']);

    $this->assertSame(0, $result->getErrorCount());
    $this->assertSame(['Dead IDs inside text replaced: 1 (on_missing: replace)'], $result->getWarnings());
    $this->assertSame(['Read /?p=3 next', 'Back to /?p=2, see ids="2, 3"', 'Dead /?p=0 and /?p=2'], $this->bodies());
    $this->assertTrue($executor->verify($schema)['match']);
  }

  public function testReplacementThatCouldMatchARealRowIsRefused()
  {
    // Post 1 already exists in the target, so a dead link rewritten to 1 would point at it
    [$result] = $this->migrate(['on_missing' => 'replace', 'replacement' => '1']);

    $this->assertFalse($result->isSuccess());
    $this->assertStringContainsString("replacement '1' would point at a real posts row", (string) $result->getError());
  }

  public function testInvalidPatternIsRejectedWhenTheConfigLoads()
  {
    $this->expectExceptionMessage('must be a valid regex with a capture group');
    SchemaConfig::fromArray('posts', ['references' => ['body' => ['schema' => 'posts', 'pattern' => '/\?p=\d+/']]]);
  }
}
