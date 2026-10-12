<?php

declare(strict_types=1);

namespace Mygra\Tests\Unit\Migration;

use PHPUnit\Framework\TestCase;
use Mygra\Adapters\SQLiteAdapter;
use Mygra\Config\SchemaConfig;
use Mygra\Migration\MigrationExecutor;

class MigrationExecutorTest extends TestCase
{
  private SQLiteAdapter $source;
  private SQLiteAdapter $target;
  private MigrationExecutor $executor;

  protected function setUp(): void
  {
    $this->source = new SQLiteAdapter();
    $this->source->connect(['database' => ':memory:']);
    $this->source->execute("CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, pid INT)");
    $this->source->execute("INSERT INTO users VALUES (1, 'A@X.COM', 1), (2, 'bad', 1), (3, 'C@X.COM', 1)");
    $this->source->execute("CREATE TABLE profiles (id INT, bio TEXT)");
    $this->source->execute("INSERT INTO profiles VALUES (1, 'hello')");

    $this->target = new SQLiteAdapter();
    $this->target->connect(['database' => ':memory:']);
    $this->target->execute("CREATE TABLE app_users (id INTEGER PRIMARY KEY, email TEXT, bio TEXT)");

    $this->executor = new MigrationExecutor($this->source, $this->target);
  }

  private function schema(array $extra = []): SchemaConfig
  {
    return SchemaConfig::fromArray('users', $extra + [
      'source_table' => 'users',
      'target_table' => 'app_users',
      'fields' => ['id' => 'id', 'email' => 'email'],
      'validations' => ['email' => [['validator' => 'email']]],
    ]);
  }

  public function testSkipCountsEachBadRowOnceAndMapsJoinFields()
  {
    $result = $this->executor->execute($this->schema([
      'joins' => [['table' => 'profiles', 'source_key' => 'pid', 'target_key' => 'id', 'fields' => ['bio' => 'bio']]],
    ]));

    $this->assertTrue($result->isSuccess());
    $this->assertSame(1, $result->getErrorCount());
    $this->assertSame(
      [['id' => 1, 'bio' => 'hello'], ['id' => 3, 'bio' => 'hello']],
      $this->target->query("SELECT id, bio FROM app_users ORDER BY id")
    );
  }

  public function testSingleModeAbortRollsBackEverything()
  {
    // Batch size 1: row 1 is flushed before row 2 aborts, and must still be rolled back
    $result = $this->executor->setBatchSize(1)->execute($this->schema([
      'options' => ['on_error' => 'abort', 'transaction_mode' => 'single'],
    ]));

    $this->assertFalse($result->isSuccess());
    $this->assertSame(0, $this->target->getRowCount('app_users'));
  }

  public function testPerBatchFailureKeepsCommittedBatchesAndResumeFinishes()
  {
    $this->target->execute("INSERT INTO app_users (id) VALUES (3)");

    // Batch size 1 so the failing insert happens mid-stream, not in the final flush
    $result = $this->executor->setBatchSize(1)->execute($this->schema());

    $this->assertFalse($result->isSuccess());
    $this->assertSame([['id' => 1], ['id' => 3]], $this->target->query("SELECT id FROM app_users ORDER BY id"));

    // Fix the cause, then resume: only rows after id 1 are read, so no duplicate key on id 1
    $this->target->execute("DELETE FROM app_users WHERE id = 3");
    $result = $this->executor->setOptions(['resume' => true])->execute($this->schema());

    $this->assertTrue($result->isSuccess(), (string) $result->getError());
    $this->assertSame(2, $result->getTotalRows());
    $this->assertSame([['id' => 1], ['id' => 3]], $this->target->query("SELECT id FROM app_users ORDER BY id"));
  }

  public function testResumeRefusesTransformedPrimaryKey()
  {
    $result = $this->executor->setOptions(['resume' => true])->execute($this->schema([
      'transforms' => ['id' => [['transformer' => 'typecast', 'options' => ['type' => 'int']]]],
    ]));

    $this->assertFalse($result->isSuccess());
    $this->assertStringContainsString('Resume needs', (string) $result->getError());
  }

  public function testFieldTransformsDoNotLeakBetweenSchemas()
  {
    $this->executor->execute($this->schema([
      'transforms' => ['email' => [['transformer' => 'string', 'options' => ['case' => 'lower']]]],
    ]));
    $this->target->execute("DELETE FROM app_users");

    $this->executor->execute($this->schema());

    $this->assertSame('A@X.COM', $this->target->query("SELECT email FROM app_users WHERE id = 1")[0]['email']);
  }
}
