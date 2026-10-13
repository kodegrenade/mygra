<?php

declare(strict_types=1);

namespace Mygra\Tests\Integration;

use Mygra\Adapters\AdapterFactory;
use Mygra\Adapters\AdapterInterface;
use Mygra\Config\SchemaConfig;
use Mygra\Migration\MigrationExecutor;
use Mygra\Migration\SchemaOrder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

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
    $db->execute('DROP TABLE IF EXISTS ' . $db->quoteIdentifier('mygra_it_users'));

    $sqlite = AdapterFactory::create(['driver' => 'sqlite', 'database' => ':memory:']);
    $sqlite->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT, pid INT, note TEXT)');
    $sqlite->execute('CREATE TABLE profiles (id INT, bio TEXT)');
    $sqlite->execute("INSERT INTO profiles VALUES (10, 'hello')");
    $insert = 'INSERT INTO users VALUES (?, ?, ?, ?, ?)';
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
    $this->assertTrue($verify->matches(), json_encode($verify->toArray()));

    // database -> SQLite: read back through the database's streaming path
    $this->migrate($db, $sqlite, [
      'source_table' => 'mygra_it_users',
      'target_table' => 'back',
      'fields' => array_combine(array_keys(self::EXPECTED[0]), array_keys(self::EXPECTED[0])),
      'options' => ['create_target' => true, 'on_error' => 'abort'],
    ]);

    // Loose compare: some drivers (sqlsrv) return numbers as strings
    $this->assertEquals(self::EXPECTED, $sqlite->query('SELECT id, name, email, bio, note FROM back ORDER BY id'));
  }

  #[DataProvider('databases')]
  public function testAppCanInsertAfterMigratingExplicitIds(string $envVar)
  {
    $db = $this->autoIdTable($envVar, 'mygra_it_seq');
    $table = $db->quoteIdentifier('mygra_it_seq');

    $sqlite = AdapterFactory::create(['driver' => 'sqlite', 'database' => ':memory:']);
    $sqlite->execute('CREATE TABLE src (id INTEGER PRIMARY KEY, name TEXT)');
    $sqlite->execute("INSERT INTO src VALUES (1, 'a'), (2, 'b')");

    $this->migrate($sqlite, $db, [
      'source_table' => 'src',
      'target_table' => 'mygra_it_seq',
      'fields' => ['id' => 'id', 'name' => 'name'],
    ]);

    // What the app does next: insert without an ID. Postgres used to hand out 1 again.
    $db->execute("INSERT INTO {$table} (name) VALUES ('new')");
    $this->assertEquals(3, $db->query("SELECT id FROM {$table} WHERE name = 'new'")[0]['id']);
  }

  #[DataProvider('databases')]
  public function testReserveIdsMovesTheGeneratorForward(string $envVar)
  {
    $db = $this->autoIdTable($envVar, 'mygra_it_reserve');

    // A live app inserting during a migration must land above the reserved range
    $db->reserveIds('mygra_it_reserve', 50);
    $db->execute("INSERT INTO {$db->quoteIdentifier('mygra_it_reserve')} (name) VALUES ('app')");
    $this->assertEquals(50, $db->query("SELECT MAX(id) AS id FROM {$db->quoteIdentifier('mygra_it_reserve')}")[0]['id']);

    // Never backwards
    $db->reserveIds('mygra_it_reserve', 10);
    $db->execute("INSERT INTO {$db->quoteIdentifier('mygra_it_reserve')} (name) VALUES ('app2')");
    $this->assertEquals(51, $db->query("SELECT MAX(id) AS id FROM {$db->quoteIdentifier('mygra_it_reserve')}")[0]['id']);
  }

  #[DataProvider('databases')]
  public function testIdMappingIntoAnAppWithExistingUsers(string $envVar)
  {
    $db = $this->autoIdTable($envVar, 'mygra_it_users2');
    $db->execute("INSERT INTO {$db->quoteIdentifier('mygra_it_users2')} (name) VALUES ('x'), ('y')");
    $db->execute("DROP TABLE IF EXISTS {$db->quoteIdentifier('mygra_it_posts2')}");
    $db->execute("CREATE TABLE {$db->quoteIdentifier('mygra_it_posts2')} (id INT PRIMARY KEY, author_id INT)");
    $db->execute("DROP TABLE IF EXISTS {$db->quoteIdentifier('mygra_offsets')}");

    $sqlite = AdapterFactory::create(['driver' => 'sqlite', 'database' => ':memory:']);
    $sqlite->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
    $sqlite->execute("INSERT INTO users VALUES (1, 'ada'), (2, 'bo')");
    $sqlite->execute('CREATE TABLE posts (id INTEGER PRIMARY KEY, author_id INT)');
    $sqlite->execute('INSERT INTO posts VALUES (1, 2), (2, 1)');

    $schemas = [
      'users' => SchemaConfig::fromArray('users', [
        'source_table' => 'users', 'target_table' => 'mygra_it_users2',
        'fields' => ['id' => 'id', 'name' => 'name'], 'options' => ['remap_ids' => true],
      ]),
      'posts' => SchemaConfig::fromArray('posts', [
        'source_table' => 'posts', 'target_table' => 'mygra_it_posts2',
        'fields' => ['id' => 'id', 'author_id' => 'author_id'], 'references' => ['author_id' => 'users'],
      ]),
    ];
    $executor = (new MigrationExecutor($sqlite, $db))->setSchemas($schemas);

    foreach ($schemas as $schema) {
      $result = $executor->execute($schema);
      $this->assertTrue($result->isSuccess(), (string) $result->getError());
      $this->assertSame(0, $result->getErrorCount(), json_encode($result->getErrors()));
    }

    // ada 1 -> 3, bo 2 -> 4; posts follow
    $this->assertEquals(
      [['id' => 1, 'name' => 'bo'], ['id' => 2, 'name' => 'ada']],
      $db->query("SELECT p.id, u.name FROM {$db->quoteIdentifier('mygra_it_posts2')} p JOIN {$db->quoteIdentifier('mygra_it_users2')} u ON u.id = p.author_id ORDER BY p.id"),
    );

    // The app's next user lands after Mygra's range
    $db->execute("INSERT INTO {$db->quoteIdentifier('mygra_it_users2')} (name) VALUES ('new')");
    $this->assertEquals(5, $db->query("SELECT id FROM {$db->quoteIdentifier('mygra_it_users2')} WHERE name = 'new'")[0]['id']);

    foreach ($schemas as $schema) {
      $this->assertTrue($executor->verify($schema)->matches(), $schema->name);
    }
    $this->assertTrue($executor->dropOffsets());
  }

  #[DataProvider('databases')]
  public function testIdsInsideTextFollowTheirRows(string $envVar)
  {
    $db = $this->autoIdTable($envVar, 'mygra_it_text');
    $table = $db->quoteIdentifier('mygra_it_text');
    $db->execute("INSERT INTO {$table} (name) VALUES ('existing')");
    $db->execute("DROP TABLE IF EXISTS {$db->quoteIdentifier('mygra_offsets')}");

    $sqlite = AdapterFactory::create(['driver' => 'sqlite', 'database' => ':memory:']);
    $sqlite->execute('CREATE TABLE posts (id INTEGER PRIMARY KEY, name TEXT)');
    $sqlite->execute("INSERT INTO posts VALUES (1, 'to /?p=2'), (2, 'ids=\"1,9\"')");

    $schema = SchemaConfig::fromArray('posts', [
      'source_table' => 'posts', 'target_table' => 'mygra_it_text',
      'fields' => ['id' => 'id', 'name' => 'name'], 'options' => ['remap_ids' => true],
      'references' => [
        'links' => ['column' => 'name', 'schema' => 'posts', 'pattern' => '/\?p=(\d+)/'],
        'lists' => ['column' => 'name', 'schema' => 'posts', 'pattern' => '/ids="([^"]*)"/', 'on_missing' => 'replace'],
      ],
    ]);
    $executor = (new MigrationExecutor($sqlite, $db))->setSchemas([$schema]);
    $result = $executor->execute($schema);

    $this->assertTrue($result->isSuccess(), (string) $result->getError());
    // Shifted by 1; dead 9 replaced by 0
    $this->assertEquals(
      [['id' => 2, 'name' => 'to /?p=3'], ['id' => 3, 'name' => 'ids="2,0"']],
      $db->query("SELECT id, name FROM {$table} WHERE id > 1 ORDER BY id"),
    );
    $this->assertTrue($executor->verify($schema)->matches());
    $executor->dropOffsets();
  }

  #[DataProvider('databases')]
  public function testUpsertFinalSync(string $envVar)
  {
    $db = $this->autoIdTable($envVar, 'mygra_it_up');
    $table = $db->quoteIdentifier('mygra_it_up');

    $sqlite = AdapterFactory::create(['driver' => 'sqlite', 'database' => ':memory:']);
    $sqlite->execute('CREATE TABLE src (id INTEGER PRIMARY KEY, name TEXT)');
    $sqlite->execute("INSERT INTO src VALUES (1, 'a'), (2, 'b')");
    $schema = ['source_table' => 'src', 'target_table' => 'mygra_it_up', 'fields' => ['id' => 'id', 'name' => 'name']];
    $this->migrate($sqlite, $db, $schema);

    $sqlite->execute("UPDATE src SET name = 'a2' WHERE id = 1");
    $sqlite->execute("INSERT INTO src VALUES (3, 'c')");
    $result = (new MigrationExecutor($sqlite, $db))->setOptions(['upsert' => true])->execute(SchemaConfig::fromArray('it', $schema));

    $this->assertTrue($result->isSuccess(), (string) $result->getError());
    $this->assertSame(3, $result->getInsertedRows());
    $this->assertEquals(
      [['id' => 1, 'name' => 'a2'], ['id' => 2, 'name' => 'b'], ['id' => 3, 'name' => 'c']],
      $db->query("SELECT id, name FROM {$table} ORDER BY id"),
    );

    // Only key columns: existing rows are left alone, new ones inserted
    $db->batchUpsert('mygra_it_up', ['id'], [['id' => 1], ['id' => 9]], ['id']);
    $this->assertSame(4, $db->getRowCount('mygra_it_up'));
  }

  #[DataProvider('databases')]
  public function testUuidKeysWithCreatedTables(string $envVar)
  {
    $config = json_decode((string) getenv($envVar), true);
    if (!$config) {
      $this->markTestSkipped("{$envVar} not set");
    }
    $db = AdapterFactory::create($config);
    $db->execute('DROP TABLE IF EXISTS ' . $db->quoteIdentifier('mygra_it_uposts'));
    $db->execute('DROP TABLE IF EXISTS ' . $db->quoteIdentifier('mygra_it_uusers'));

    $sqlite = AdapterFactory::create(['driver' => 'sqlite', 'database' => ':memory:']);
    $sqlite->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
    $sqlite->execute("INSERT INTO users VALUES (1, 'ada'), (2, 'bo')");
    $sqlite->execute('CREATE TABLE posts (id INTEGER PRIMARY KEY, author_id INT)');
    $sqlite->execute('INSERT INTO posts VALUES (10, 2), (11, 1)');

    $schemas = [
      'users' => SchemaConfig::fromArray('users', [
        'source_table' => 'users', 'target_table' => 'mygra_it_uusers',
        'fields' => ['id' => 'id', 'name' => 'name'], 'options' => ['remap_ids' => 'uuid', 'create_target' => true],
      ]),
      'posts' => SchemaConfig::fromArray('posts', [
        'source_table' => 'posts', 'target_table' => 'mygra_it_uposts',
        'fields' => ['id' => 'id', 'author_id' => 'author_id'], 'references' => ['author_id' => 'users'],
        'options' => ['remap_ids' => 'uuid', 'create_target' => true],
      ]),
    ];
    $executor = (new MigrationExecutor($sqlite, $db))->setSchemas($schemas);
    foreach ($schemas as $schema) {
      $result = $executor->execute($schema);
      $this->assertTrue($result->isSuccess(), (string) $result->getError());
      $this->assertSame(0, $result->getErrorCount(), json_encode($result->getErrors()));
    }

    $this->assertEquals(
      [['name' => 'bo'], ['name' => 'ada']],
      $db->query("SELECT u.name FROM {$db->quoteIdentifier('mygra_it_uposts')} p JOIN {$db->quoteIdentifier('mygra_it_uusers')} u ON u.id = p.author_id ORDER BY u.name DESC"),
    );
    foreach ($schemas as $schema) {
      $this->assertTrue($executor->verify($schema)->matches(), $schema->name);
    }
  }

  #[DataProvider('databases')]
  public function testDatabaseAsSourceWithSelfReferenceAcrossPages(string $envVar)
  {
    $config = json_decode((string) getenv($envVar), true);
    if (!$config) {
      $this->markTestSkipped("{$envVar} not set");
    }
    $db = AdapterFactory::create($config);
    $table = $db->quoteIdentifier('mygra_it_cats');
    $db->execute("DROP TABLE IF EXISTS {$table}");
    $db->execute("CREATE TABLE {$table} (id INT PRIMARY KEY, parent_id INT, name VARCHAR(20))");
    $db->execute("INSERT INTO {$table} VALUES (1, NULL, 'root'), (2, 1, 'a'), (3, 2, 'b'), (4, 3, 'c'), (5, 99, 'orphan')");

    // The target already has a category, so IDs shift by 1
    $sqlite = AdapterFactory::create(['driver' => 'sqlite', 'database' => ':memory:']);
    $sqlite->execute('CREATE TABLE cats (id INTEGER PRIMARY KEY, parent_id INT, name TEXT)');
    $sqlite->execute("INSERT INTO cats VALUES (1, NULL, 'existing')");

    $schema = SchemaConfig::fromArray('cats', [
      'source_table' => 'mygra_it_cats', 'target_table' => 'cats',
      'fields' => ['id' => 'id', 'parent_id' => 'parent_id', 'name' => 'name'],
      'references' => ['parent_id' => ['schema' => 'cats', 'on_missing' => 'null']],
      'options' => ['remap_ids' => true],
    ]);
    // Pages of 2 rows: parent lookups run on the source connection between pages
    $executor = (new MigrationExecutor($db, $sqlite))->setSchemas([$schema])->setBatchSize(2);
    $result = $executor->execute($schema);

    $this->assertTrue($result->isSuccess(), (string) $result->getError());
    $this->assertSame(5, $result->getTotalRows());
    $this->assertEquals(
      [['id' => 2, 'parent_id' => null, 'name' => 'root'], ['id' => 3, 'parent_id' => 2, 'name' => 'a'], ['id' => 4, 'parent_id' => 3, 'name' => 'b'], ['id' => 5, 'parent_id' => 4, 'name' => 'c'], ['id' => 6, 'parent_id' => null, 'name' => 'orphan']],
      $sqlite->query('SELECT id, parent_id, name FROM cats WHERE id > 1 ORDER BY id'),
    );
    $this->assertTrue($executor->verify($schema)->matches());
  }

  /**
   * Connect to the database under test and (re)create a table with a generated integer ID.
   */
  private function autoIdTable(string $envVar, string $name): AdapterInterface
  {
    $config = json_decode((string) getenv($envVar), true);
    if (!$config) {
      $this->markTestSkipped("{$envVar} not set");
    }

    $db = AdapterFactory::create($config);
    $table = $db->quoteIdentifier($name);
    $db->execute("DROP TABLE IF EXISTS {$table}");
    $autoId = match ($config['driver']) {
      'mysql' => 'INT AUTO_INCREMENT PRIMARY KEY',
      'pgsql' => 'SERIAL PRIMARY KEY',
      'sqlsrv' => 'INT IDENTITY(1,1) PRIMARY KEY',
    };
    $db->execute("CREATE TABLE {$table} (id {$autoId}, name VARCHAR(20))");
    return $db;
  }

  #[DataProvider('databases')]
  public function testWideTableInsert(string $envVar)
  {
    $config = json_decode((string) getenv($envVar), true);
    if (!$config) {
      $this->markTestSkipped("{$envVar} not set");
    }

    // 1000 rows x 70 columns = 70,000 parameters: over MySQL's and PostgreSQL's 65,535, SQL Server's 2,100
    $db = AdapterFactory::create($config);
    $columns = array_map(fn($i) => "c{$i}", range(0, 69));
    $db->execute('DROP TABLE IF EXISTS ' . $db->quoteIdentifier('mygra_it_wide'));
    $db->execute('CREATE TABLE mygra_it_wide (' . implode(', ', array_map(fn($c) => "{$c} INT", $columns)) . ')');

    $rows = array_map(fn($r) => array_fill_keys($columns, $r), range(1, 1000));
    $db->batchInsert('mygra_it_wide', $columns, $rows);

    $this->assertSame(1000, $db->getRowCount('mygra_it_wide'));
  }

  #[DataProvider('databases')]
  public function testForeignKeysOrderSchemas(string $envVar)
  {
    $config = json_decode((string) getenv($envVar), true);
    if (!$config) {
      $this->markTestSkipped("{$envVar} not set");
    }

    $db = AdapterFactory::create($config);
    $db->execute('DROP TABLE IF EXISTS ' . $db->quoteIdentifier('mygra_it_posts'));
    $db->execute('DROP TABLE IF EXISTS ' . $db->quoteIdentifier('mygra_it_authors'));
    $db->execute('CREATE TABLE mygra_it_authors (id INT PRIMARY KEY)');
    // Table-level FOREIGN KEY: MySQL silently ignores inline column REFERENCES
    $db->execute('CREATE TABLE mygra_it_posts (id INT PRIMARY KEY, author_id INT, FOREIGN KEY (author_id) REFERENCES mygra_it_authors(id))');

    $schema = fn($table) => SchemaConfig::fromArray($table, ['source_table' => $table, 'target_table' => $table]);
    [$ordered] = SchemaOrder::sort([$schema('mygra_it_posts'), $schema('mygra_it_authors')], $db);

    $this->assertSame(['mygra_it_authors', 'mygra_it_posts'], array_map(fn($s) => $s->name, $ordered));
  }

  private function migrate(AdapterInterface $source, AdapterInterface $target, array $schema): void
  {
    $result = (new MigrationExecutor($source, $target))->execute(SchemaConfig::fromArray('it', $schema));

    $this->assertTrue($result->isSuccess(), (string) $result->getError());
    $this->assertSame(0, $result->getErrorCount(), json_encode($result->getErrors()));
    $this->assertSame(2, $result->getInsertedRows());
  }
}
