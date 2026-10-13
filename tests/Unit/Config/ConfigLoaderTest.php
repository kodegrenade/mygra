<?php

declare(strict_types=1);

namespace Mygra\Tests\Unit\Config;

use Mygra\Config\ConfigLoader;
use Mygra\Config\InvalidConfigException;
use PHPUnit\Framework\TestCase;

class ConfigLoaderTest extends TestCase
{
  private string $dir;

  protected function setUp(): void
  {
    $this->dir = sys_get_temp_dir() . '/mygra_loader_' . uniqid();
    mkdir($this->dir);
  }

  protected function tearDown(): void
  {
    array_map('unlink', glob("{$this->dir}/*"));
    rmdir($this->dir);
    putenv('MYGRA_TEST_HOST');
    putenv('MYGRA_TEST_UNSET');
  }

  private function write(string $name, string $content): string
  {
    file_put_contents("{$this->dir}/{$name}", $content);
    return "{$this->dir}/{$name}";
  }

  private function yaml(string $host, string $password): string
  {
    return <<<YAML
      connections:
        source: { driver: sqlite, database: ":memory:" }
        target: { driver: mysql, host: "{$host}", password: "{$password}" }
      schemas:
        users: { source_table: a, target_table: b, fields: { id: id } }
      YAML;
  }

  public function testInterpolatesEnvVarsAndDefaults()
  {
    putenv('MYGRA_TEST_HOST=db.internal');
    putenv('MYGRA_TEST_UNSET');

    $config = ConfigLoader::load($this->write('a.yaml', $this->yaml('${MYGRA_TEST_HOST}', 'pw-${MYGRA_TEST_UNSET:-fallback}')));

    $this->assertSame('db.internal', $config->get('connections.target.host'));
    $this->assertSame('pw-fallback', $config->get('connections.target.password'));

    putenv('MYGRA_TEST_UNSET=set');
    $config = ConfigLoader::load($this->write('b.yaml', $this->yaml('${MYGRA_TEST_HOST:-other}', '${MYGRA_TEST_UNSET:-fallback}')));
    $this->assertSame('db.internal', $config->get('connections.target.host'));
    $this->assertSame('set', $config->get('connections.target.password'));
  }

  public function testMissingEnvVarWithoutDefaultFails()
  {
    putenv('MYGRA_TEST_UNSET');

    $this->expectExceptionMessage('Environment variable not set: MYGRA_TEST_UNSET');
    ConfigLoader::load($this->write('a.yaml', $this->yaml('${MYGRA_TEST_UNSET}', '')));
  }

  public function testEmptyYamlFileGivesClearError()
  {
    $path = $this->write('empty.yaml', "# nothing yet\n");

    $this->expectException(InvalidConfigException::class);
    $this->expectExceptionMessage("Invalid configuration in {$path} (1 problem):\n  - the file is empty; it needs connections and schemas");
    ConfigLoader::load($path);
  }

  public function testInvalidJsonGivesClearError()
  {
    $this->expectException(InvalidConfigException::class);
    $this->expectExceptionMessage('invalid JSON: Syntax error');
    ConfigLoader::load($this->write('bad.json', '{"connections": {'));
  }

  public function testValidationRunsOnLoad()
  {
    $path = $this->write('typo.yaml', $this->yaml('h', 'p') . "\nsettings: { on_eror: skip }\n");

    $this->expectException(InvalidConfigException::class);
    $this->expectExceptionMessage('settings.on_eror: unknown setting (did you mean on_error?)');
    ConfigLoader::load($path);
  }
}
