<?php

declare(strict_types=1);

namespace Mygra\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Mygra\Adapters\AdapterFactory;
use Mygra\Adapters\AdapterInterface;
use Mygra\Config\SchemaConfig;
use Mygra\Migration\MigrationExecutor;

/**
 * SQLite -> real database -> SQLite round trip.
 *
 * Each database is enabled by an env var holding its connection config as JSON, e.g.
 *   MYGRA_TEST_MYSQL='{"driver":"mysql","host":"127.0.0.1","database":"mygra_it","user":"root"}'
 * Unset databases are skipped.
 */
class RoundTripTest extends TestCase
{
  private const EXPECTED = [
    ['id' => 1, 'name' => "O'Brien", 'email' => 'ob@x.com', 'bio' => 'hello', 'note' => null],
    ['id' => 2, 'name' => 'Zoë', 'email' => 'zoe@x.com', 'bio' => null, 'note' => 'multi; statement --'],
  ];

  public static function databases(): array
  {
    return [
      'mysql' => ['MYGRA_TEST_MYSQL'],
      'pgsql' => ['MYGRA_TEST_PGSQL'],
      'sqlsrv' => ['MYGRA_TEST_SQLSRV'],
    ];
  }

  #[DataProvider('databases')]
  public function testRoundTrip(string $envVar)
  {
    $config = json_decode((string) getenv($envVar), true);
    if (!$config) {
      $this->markTestSkipped("{$envVar} not set");
    }

    $db = AdapterFactory::create($config);
    $db->execute("DROP TABLE IF EXISTS " . $db->quoteIdentifier('mygra_it_users'));

    $sqlite = AdapterFactory::create(['driver' => 'sqlite', 'database' => ':memory:']);
    $sqlite->execute("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT, pid INT, note TEXT)");
    $sqlite->execute("CREATE TABLE profiles (id INT, bio TEXT)");
    $sqlite->execute("INSERT INTO profiles VALUES (10, 'hello')");
    $insert = "INSERT INTO users VALUES (?, ?, ?, ?, ?)";
    $sqlite->execute($insert, [1, "O'Brien", 'OB@X.COM', 10, null]);
    $sqlite->execute($insert, [2, 'Zoë', 'zoe@x.com', null, 'multi; statement --']);

    // SQLite -> database: create target, transform, join
    $toDb = [
      'source_table' => 'users',
      'target_table' => 'mygra_it_users',
      'fields' => ['id' => 'id', 'name' => 'name', 'email' => 'email', 'note' => 'note'],
      'transforms' => ['email' => [['transformer' => 'string', 'options' => ['case' => 'lower']]]],
      'joins' => [['table' => 'profiles', 'source_key' => 'pid', 'target_key' => 'id', 'fields' => ['bio' => 'bio']]],
      'options' => ['create_target' => true, 'on_error' => 'abort'],
    ];
    $this->migrate($sqlite, $db, $toDb);

    // Resuming a finished migration reads the target's MAX(id) and finds nothing left
    $resumed = (new MigrationExecutor($sqlite, $db))->setOptions(['resume' => true])
      ->execute(SchemaConfig::fromArray('it', $toDb));
    $this->assertTrue($resumed->isSuccess(), (string) $resumed->getError());
    $this->assertSame(0, $resumed->getTotalRows());

    // Verify normalizes each driver's value formatting (sqlsrv strings, pgsql booleans, ...)
    $verify = (new MigrationExecutor($sqlite, $db))->verify(SchemaConfig::fromArray('it', $toDb));
    $this->assertTrue($verify['match'], json_encode($verify));

    // database -> SQLite: read back through the database's streaming path
    $this->migrate($db, $sqlite, [
      'source_table' => 'mygra_it_users',
      'target_table' => 'back',
      'fields' => array_combine(array_keys(self::EXPECTED[0]), array_keys(self::EXPECTED[0])),
      'options' => ['create_target' => true, 'on_error' => 'abort'],
    ]);

    // Loose compare: some drivers (sqlsrv) return numbers as strings
    $this->assertEquals(self::EXPECTED, $sqlite->query("SELECT id, name, email, bio, note FROM back ORDER BY id"));
  }

  private function migrate(AdapterInterface $source, AdapterInterface $target, array $schema): void
  {
    $result = (new MigrationExecutor($source, $target))->execute(SchemaConfig::fromArray('it', $schema));

    $this->assertTrue($result->isSuccess(), (string) $result->getError());
    $this->assertSame(0, $result->getErrorCount(), json_encode($result->getErrors()));
    $this->assertSame(2, $result->getInsertedRows());
  }
}
