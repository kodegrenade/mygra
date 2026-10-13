<?php

declare(strict_types=1);

namespace Mygra\Tests\Unit\CLI;

use Mygra\CLI\Commands\FinishCommand;
use Mygra\CLI\Commands\MigrateCommand;
use Mygra\CLI\Commands\VerifyCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class MigrateCommandTest extends TestCase
{
  private string $dir;

  protected function setUp(): void
  {
    $this->dir = sys_get_temp_dir() . '/mygra_test_' . uniqid();
    mkdir($this->dir);

    $pdo = new \PDO("sqlite:{$this->dir}/src.db");
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT)');
    $pdo->exec("INSERT INTO users VALUES (1, 'a@x.com'), (2, 'bad')");
  }

  protected function tearDown(): void
  {
    array_map('unlink', glob("{$this->dir}/*"));
    rmdir($this->dir);
  }

  public function testFailedParentSkipsDependentsButNotIndependentSchemas()
  {
    $schema = fn(string $name, array $options, array $extra = []) => $extra + [
      'source_table' => 'users',
      'target_table' => $name,
      'fields' => ['id' => 'id', 'email' => 'email'],
      'options' => $options + ['create_target' => true],
    ];

    file_put_contents("{$this->dir}/mygra.yaml", json_encode([ // JSON is valid YAML
      'connections' => [
        'source' => ['driver' => 'sqlite', 'database' => "{$this->dir}/src.db"],
        'target' => ['driver' => 'sqlite', 'database' => "{$this->dir}/dst.db"],
      ],
      'schemas' => [
        'grandchild' => $schema('grandchild', ['depends_on' => ['child']]),
        'child' => $schema('child', ['depends_on' => ['parent']]),
        'parent' => $schema('parent', ['on_error' => 'abort'], ['validations' => ['email' => [['validator' => 'email']]]]),
        'independent' => $schema('independent', []),
      ],
    ]));

    $tester = new CommandTester(new MigrateCommand());
    $exitCode = $tester->execute([
      '--config' => "{$this->dir}/mygra.yaml",
      '--log-file' => "{$this->dir}/migration.log",
      '--no-progress' => true,
    ]);

    $display = preg_replace('/\s+/', ' ', $tester->getDisplay());
    $this->assertSame(1, $exitCode);
    $this->assertMatchesRegularExpression('/child \.+ needs parent\s+SKIP/', $display);
    $this->assertMatchesRegularExpression('/grandchild \.+ needs child\s+SKIP/', $display);
    $this->assertMatchesRegularExpression('/independent \.+ 2 rows · \S+\s+DONE/', $display);

    $target = new \PDO("sqlite:{$this->dir}/dst.db");
    $tables = $target->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")->fetchAll(\PDO::FETCH_COLUMN);
    $this->assertSame(['independent', 'parent'], $tables); // children never touched the target
    $this->assertSame(0, (int) $target->query('SELECT COUNT(*) FROM parent')->fetchColumn());
    $this->assertSame(2, (int) $target->query('SELECT COUNT(*) FROM independent')->fetchColumn());
  }

  public function testVerifyKeepsOffsetsAndFinishRemovesThem()
  {
    file_put_contents("{$this->dir}/mygra.yaml", json_encode([
      'connections' => [
        'source' => ['driver' => 'sqlite', 'database' => "{$this->dir}/src.db"],
        'target' => ['driver' => 'sqlite', 'database' => "{$this->dir}/dst.db"],
      ],
      'schemas' => ['users' => [
        'source_table' => 'users', 'target_table' => 'users', 'fields' => ['id' => 'id', 'email' => 'email'],
        'options' => ['create_target' => true, 'remap_ids' => true],
      ]],
    ]));
    $config = ['--config' => "{$this->dir}/mygra.yaml"];
    $tables = fn() => (new \PDO("sqlite:{$this->dir}/dst.db"))->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")->fetchAll(\PDO::FETCH_COLUMN);

    $this->assertSame(0, (new CommandTester(new MigrateCommand()))->execute($config + ['--log-file' => "{$this->dir}/m.log", '--no-progress' => true]));
    $this->assertSame(0, (new CommandTester(new VerifyCommand()))->execute($config));
    $this->assertSame(['mygra_offsets', 'users'], $tables());

    $finish = new CommandTester(new FinishCommand());
    $this->assertSame(0, $finish->execute($config));
    $this->assertStringContainsString('Removed mygra_offsets', $finish->getDisplay());
    $this->assertSame(['users'], $tables());
  }
}
