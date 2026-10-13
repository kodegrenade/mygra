<?php

declare(strict_types=1);

namespace Mygra\CLI\Commands;

use Mygra\Adapters\AdapterInterface;
use Mygra\CLI\Ui;
use Mygra\Config\ConfigLoader;
use Mygra\Config\SchemaConfig;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Pre-flight checks: the config loads, both databases connect, and every schema's tables and fields exist.
 */
#[AsCommand(name: 'validate', description: 'Check the configuration, both connections and every schema before migrating')]
final class ValidateCommand extends MygraCommand
{
  /** @var array<int, array{check: string, status: string, detail: string, message?: string}> */
  private array $checks = [];
  private bool $json = false;

  protected function configure(): void
  {
    $this
      ->addConfigOption()
      ->addOption('schema', 's', InputOption::VALUE_REQUIRED, 'Validate only this schema')
      ->addFormatOption()
      ->setHelp(<<<HELP
        Loads the configuration, connects to both databases and checks that every schema's
        source table, source fields and target table exist.

          <info>php mygra validate</info>                  check everything
          <info>php mygra validate --schema=users</info>   check one schema
          <info>php mygra validate --format=json</info>    machine-readable result for CI

        A missing target table is a warning when the schema sets <info>create_target</info>.
        Exits with 0 only when no check failed.
        HELP);
  }

  protected function execute(InputInterface $input, OutputInterface $output): int
  {
    $this->json = $this->wantsJson($input);
    $this->checks = [];
    $path = $input->getOption('config');

    if (!$this->json) {
      $this->ui->info('Validating ' . Ui::path($path));
    }

    try {
      $config = ConfigLoader::load($path);
      $this->check('Config file', Ui::path($path), 'ok');
    } catch (\Exception $e) {
      $this->check('Config file', Ui::path($path), 'fail', $e->getMessage());
      return $this->finish($output);
    }

    $source = $this->tryConnect($config, 'source');
    $target = $this->tryConnect($config, 'target');

    $schemaName = $input->getOption('schema');
    $schemas = $schemaName ? [$config->getSchema($schemaName)] : array_values($config->getAllSchemas());
    if ($schemaName && !$schemas[0]) {
      $this->check($schemaName, 'schema', 'fail', "Schema not found: {$schemaName}");
      return $this->finish($output);
    }

    foreach ($schemas as $schema) {
      try {
        $this->checkSchema($schema, $source, $target);
      } catch (\Exception $e) {
        $this->check($schema->name, 'could not check', 'fail', $e->getMessage());
      }
    }

    return $this->finish($output);
  }

  private function tryConnect(ConfigLoader $config, string $which): ?AdapterInterface
  {
    $connection = $which === 'source' ? $config->getSourceConnection() : $config->getTargetConnection();
    try {
      $adapter = $this->connect($config, $which);
      $this->check(ucfirst($which) . ' connection', Ui::connection($connection), 'ok');
      return $adapter;
    } catch (\Exception $e) {
      $this->check(ucfirst($which) . ' connection', Ui::connection($connection), 'fail', $e->getPrevious()?->getMessage() ?? $e->getMessage());
      return null;
    }
  }

  private function checkSchema(SchemaConfig $schema, ?AdapterInterface $source, ?AdapterInterface $target): void
  {
    if ($source) {
      if (!in_array($schema->sourceTable, $source->getTables(), true)) {
        $this->check("{$schema->name} · source table", $schema->sourceTable, 'fail', 'Not found in the source database');
      } else {
        $this->check("{$schema->name} · source table", $schema->sourceTable, 'ok');

        $columns = $source->getTableSchema($schema->sourceTable);
        $missing = array_values(array_filter($schema->getSourceFields(), fn($f) => !$columns->hasColumn((string) $f)));
        $fields = Ui::count(count($schema->fields), 'mapped field');
        $missing
          ? $this->check("{$schema->name} · source fields", $fields, 'fail', 'Not in ' . $schema->sourceTable . ': ' . implode(', ', $missing))
          : $this->check("{$schema->name} · source fields", $fields, 'ok');
      }
    }

    if ($target) {
      if (in_array($schema->targetTable, $target->getTables(), true)) {
        $this->check("{$schema->name} · target table", $schema->targetTable, 'ok');
      } elseif ($schema->getOption('create_target', false)) {
        $this->check("{$schema->name} · target table", $schema->targetTable, 'warn', 'Missing; migrate creates it (create_target)');
      } else {
        $this->check("{$schema->name} · target table", $schema->targetTable, 'fail', 'Missing; create it, or run migrate with --create');
      }
    }
  }

  /**
   * @param string $status ok, warn or fail
   */
  private function check(string $label, string $detail, string $status, ?string $message = null): void
  {
    $this->checks[] = array_filter(['check' => $label, 'status' => $status, 'detail' => $detail, 'message' => $message], fn($v) => $v !== null);
    if ($this->json) {
      return;
    }
    $this->ui->task($label, $detail, $status);
    if ($message !== null) {
      $this->ui->detail($status === 'fail' ? "<fg=red>{$message}</>" : "<comment>{$message}</comment>", false);
    }
  }

  private function finish(OutputInterface $output): int
  {
    $statuses = array_count_values(array_column($this->checks, 'status'));
    $failed = $statuses['fail'] ?? 0;
    $warned = $statuses['warn'] ?? 0;

    if ($this->json) {
      $this->writeJson($output, ['status' => $failed ? 'failed' : 'ok', 'checks' => $this->checks]);
    } elseif ($failed) {
      $this->ui->error(Ui::count($failed, 'check') . ' failed.');
    } elseif ($warned) {
      $this->ui->warn(Ui::count($warned, 'warning') . ', nothing failed. Next: <info>php mygra migrate --dry-run</info>');
    } else {
      $this->ui->done('Everything is in place. Next: <info>php mygra migrate --dry-run</info>');
    }

    return $failed ? Command::FAILURE : Command::SUCCESS;
  }
}
