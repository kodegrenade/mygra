<?php

declare(strict_types=1);

namespace Mygra\CLI\Commands;

use Mygra\CLI\Ui;
use Mygra\Logger\FileLogger;
use Mygra\Migration\MigrationExecutor;
use Mygra\Migration\MigrationResult;
use Mygra\Migration\SchemaOrder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'migrate', description: 'Migrate data from the source to the target database')]
final class MigrateCommand extends MygraCommand
{
  /** Row errors shown per schema; the rest are in the log */
  private const ERRORS_SHOWN = 3;

  protected function configure(): void
  {
    $this
      ->addConfigOption()
      ->addOption('schema', 's', InputOption::VALUE_REQUIRED, 'Migrate only this schema')
      ->addOption('batch-size', 'b', InputOption::VALUE_REQUIRED, 'Rows per batch (default: settings.batch_size or 1000)')
      ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Read and transform everything, write nothing')
      ->addOption('create', null, InputOption::VALUE_NONE, 'Create target tables that do not exist')
      ->addOption('upsert', 'u', InputOption::VALUE_NONE, 'Update rows that already exist in the target (final sync before cutover)')
      ->addOption('resume', 'r', InputOption::VALUE_NONE, 'Continue after the highest key already in the target')
      ->addOption('no-progress', null, InputOption::VALUE_NONE, 'Do not show live progress')
      ->addOption('log-file', 'l', InputOption::VALUE_REQUIRED, 'Log file (default: settings.log_file)')
      ->addFormatOption()
      ->setHelp(<<<HELP
        Migrates every schema in dependency order, or one with <info>--schema</info>.

          <info>php mygra migrate</info>                  migrate everything
          <info>php mygra migrate --dry-run</info>        read and transform, write nothing
          <info>php mygra migrate --create</info>         create missing target tables
          <info>php mygra migrate --resume</info>         continue after a failure
          <info>php mygra migrate --upsert</info>         final sync before cutover
          <info>php mygra migrate --format=json</info>    machine-readable result for CI

        Exits with 0 only when every schema succeeded and no row was skipped.
        HELP);
  }

  protected function execute(InputInterface $input, OutputInterface $output): int
  {
    $json = $this->wantsJson($input);

    try {
      $config = $this->loadConfig($input);
      $source = $this->connect($config, 'source');
      $target = $this->connect($config, 'target');
    } catch (\Exception $e) {
      return $this->fail($input, $output, $e->getMessage());
    }

    $logFile = $input->getOption('log-file') ?? $config->get('settings.log_file') ?? 'logs/migration_{date}.log';
    $logger = new FileLogger($logFile, $config->get('settings.log_level', 'info'));

    $executor = (new MigrationExecutor($source, $target, null, null, $logger))
      ->setSchemas($config->getAllSchemas())
      ->setBatchSize((int) ($input->getOption('batch-size') ?? $config->get('settings.batch_size') ?? 1000))
      ->setDryRun((bool) $input->getOption('dry-run'))
      ->setOptions([
        'create_target' => (bool) $input->getOption('create'),
        'on_error' => $config->get('settings.on_error', 'skip'),
        'transaction_mode' => $config->get('settings.transaction_mode', 'per_batch'),
        'resume' => $input->getOption('resume'),
        'upsert' => $input->getOption('upsert'),
      ]);

    $schemaName = $input->getOption('schema');
    $schemas = $schemaName ? array_filter([$config->getSchema($schemaName)]) : array_values($config->getAllSchemas());
    if (!$schemas) {
      return $this->fail($input, $output, $schemaName ? "Schema not found: {$schemaName}" : 'No schemas defined in the configuration');
    }

    // Parents before children (foreign keys, depends_on, references)
    $deps = [];
    $cyclic = [];
    if (!$schemaName) {
      try {
        [$schemas, $cyclic, $deps] = SchemaOrder::sort($schemas, $source);
      } catch (\InvalidArgumentException $e) {
        return $this->fail($input, $output, $e->getMessage());
      }
    }

    if (!$json) {
      $mode = $input->getOption('dry-run') ? ' · <comment>dry run, nothing is written</comment>' : '';
      $this->ui->info(sprintf(
        'Migrating %s · %s → %s%s',
        Ui::count(count($schemas), 'schema'),
        Ui::connection($config->getSourceConnection()),
        Ui::connection($config->getTargetConnection()),
        $mode,
      ));
      if ($cyclic) {
        $this->ui->line('<comment>Dependency cycle, kept in config order: ' . implode(', ', $cyclic) . '</comment>');
        $this->ui->line();
      }
    }

    $results = [];
    $failed = []; // failed or skipped schemas; their dependents are skipped too
    $showProgress = !$json && !$input->getOption('no-progress') && $output->isDecorated();

    foreach ($schemas as $schema) {
      // Children of a failed parent would reference rows that never arrived
      $failedParents = array_values(array_intersect($deps[$schema->name] ?? [], $failed));
      if ($failedParents) {
        $result = new MigrationResult($schema->name);
        $result->setError('Skipped: depends on failed ' . implode(', ', $failedParents));
        $results[] = $result;
        $failed[] = $schema->name;
        if (!$json) {
          $this->ui->task($schema->name, 'needs ' . implode(', ', $failedParents), 'skip');
        }
        continue;
      }

      $bar = null;
      if ($showProgress) {
        $executor->onProgress(function (int $processed, int $total) use (&$bar, $schema): void {
          if ($bar === null) {
            $bar = $this->ui->progress($schema->name);
            $bar->start($total);
          }
          $bar->setProgress($processed);
        });
      }

      $result = $executor->execute($schema);
      $results[] = $result;
      $bar?->clear();

      if (!$result->isSuccess()) {
        $failed[] = $schema->name;
      }
      if (!$json) {
        $this->renderResult($result);
      }
    }

    $exitCode = $this->exitCode($results);
    $json ? $this->writeJson($output, $this->toJson($results, $logFile, $exitCode)) : $this->renderSummary($results, $logFile);

    return $exitCode;
  }

  private function renderResult(MigrationResult $result): void
  {
    $status = match (true) {
      !$result->isSuccess() => 'fail',
      $result->getErrorCount() > 0 => 'warn',
      default => 'done',
    };
    $this->ui->task(
      $result->getSchemaName(),
      Ui::count($result->getInsertedRows(), 'row') . ' · ' . Ui::duration($result->getDuration()),
      $status,
    );

    if (!$result->isSuccess()) {
      $this->ui->detail("<fg=red>{$result->getError()}</>", false);
    }
    if ($result->getMetadata('id_offset') !== null) {
      $this->ui->detail("New IDs: old ID + {$result->getMetadata('id_offset')}");
    }
    if ($result->getMetadata('id_uuid') !== null) {
      $this->ui->detail("New IDs: {$result->getMetadata('id_uuid')}");
    }
    foreach ($result->getWarnings() as $warning) {
      $this->ui->detail("<comment>{$warning}</comment>", false);
    }

    $errors = $result->getErrors();
    foreach (array_slice($errors, 0, self::ERRORS_SHOWN) as $error) {
      $this->ui->detail('Skipped ' . self::rowRef($error['row']) . ": {$error['error']}");
    }
    if (count($errors) > self::ERRORS_SHOWN) {
      $this->ui->detail('… and ' . Ui::count(count($errors) - self::ERRORS_SHOWN, 'more row') . ' in the log');
    }
  }

  /**
   * @param MigrationResult[] $results
   */
  private function renderSummary(array $results, string $logFile): void
  {
    $failed = count(array_filter($results, fn($r) => !$r->isSuccess()));
    $skipped = array_sum(array_map(fn($r) => $r->getErrorCount(), $results));
    $written = array_sum(array_map(fn($r) => $r->getInsertedRows(), $results));
    $time = Ui::duration(array_sum(array_map(fn($r) => $r->getDuration(), $results)));

    if ($failed) {
      $this->ui->error(sprintf('%s failed · %s written · Log: %s', Ui::count($failed, 'schema'), Ui::count($written, 'row'), Ui::path($logFile)));
    } elseif ($skipped) {
      $this->ui->warn(sprintf('%s skipped · %s written · Log: %s', Ui::count($skipped, 'row'), Ui::count($written, 'row'), Ui::path($logFile)));
    } else {
      $this->ui->done(sprintf('%s written in %s. Next: <info>php mygra verify</info>', Ui::count($written, 'row'), $time));
    }
  }

  /**
   * @param MigrationResult[] $results
   */
  private function toJson(array $results, string $logFile, int $exitCode): array
  {
    return [
      'status' => $exitCode === Command::SUCCESS ? 'ok' : 'failed',
      'log_file' => $logFile,
      'schemas' => array_map(fn(MigrationResult $r) => array_filter([
        'name' => $r->getSchemaName(),
        'status' => !$r->isSuccess() ? 'failed' : ($r->getErrorCount() ? 'rows_skipped' : 'ok'),
        'rows_read' => $r->getTotalRows(),
        'rows_written' => $r->getInsertedRows(),
        'rows_skipped' => $r->getErrorCount(),
        'duration_ms' => (int) round($r->getDuration() * 1000),
        'error' => $r->getError(),
        'id_offset' => $r->getMetadata('id_offset'),
        'id_uuid' => $r->getMetadata('id_uuid'),
        'warnings' => $r->getWarnings() ?: null,
        'row_errors' => array_map(fn($e) => $e['error'], array_slice($r->getErrors(), 0, 100)) ?: null,
      ], fn($v) => $v !== null), $results),
    ];
  }

  /**
   * @param MigrationResult[] $results
   */
  private function exitCode(array $results): int
  {
    foreach ($results as $result) {
      if (!$result->isSuccess() || $result->getErrorCount() > 0) {
        return Command::FAILURE;
      }
    }
    return Command::SUCCESS;
  }

  /**
   * "row id=2": the row's first column, usually its key.
   */
  private static function rowRef(array $row): string
  {
    $column = array_key_first($row);
    return $column === null ? 'row' : "row {$column}=" . var_export($row[$column], true);
  }
}
