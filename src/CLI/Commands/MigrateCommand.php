<?php

declare(strict_types=1);

namespace Mygra\CLI\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Attribute\AsCommand;
use Mygra\Config\ConfigLoader;
use Mygra\Adapters\AdapterFactory;
use Mygra\Migration\MigrationExecutor;
use Mygra\Transform\TransformPipeline;
use Mygra\Logger\FileLogger;
use Mygra\Transaction\RollbackJournal;
use Mygra\Transaction\TransactionManager;

/**
 * Migrate Command
 * 
 * Executes database migrations based on configuration.
 */
#[AsCommand(
  name: 'migrate',
  description: 'Execute database migration'
)]
class MigrateCommand extends Command
{

  protected function configure(): void
  {
    $this
      ->addOption(
        'config',
        'c',
        InputOption::VALUE_REQUIRED,
        'Path to configuration file',
        'config/mygra.yaml'
      )
      ->addOption(
        'schema',
        's',
        InputOption::VALUE_REQUIRED,
        'Specific schema to migrate (omit for all)'
      )
      ->addOption(
        'batch-size',
        'b',
        InputOption::VALUE_REQUIRED,
        'Number of rows per batch',
        1000
      )
      ->addOption(
        'dry-run',
        null,
        InputOption::VALUE_NONE,
        'Preview actions without executing'
      )
      ->addOption(
        'create',
        null,
        InputOption::VALUE_NONE,
        'Automatically create target table if missing'
      )
      ->addOption(
        'journal',
        'j',
        InputOption::VALUE_OPTIONAL,
        'Enable rollback journaling',
        'logs/rollback.journal'
      )
      ->addOption(
        'no-progress',
        null,
        InputOption::VALUE_NONE,
        'Disable progress bar'
      )
      ->addOption(
        'log-file',
        'l',
        InputOption::VALUE_REQUIRED,
        'Path to log file'
      )
      ->setHelp(
        <<<HELP
The <info>migrate</info> command executes database migrations.

<comment>Examples:</comment>

  Migrate all schemas:
    <info>php bin/mygra migrate --config=config/mygra.yaml</info>

  Migrate a specific schema:
    <info>php bin/mygra migrate --schema=users</info>

  Dry run (preview only):
    <info>php bin/mygra migrate --schema=users --dry-run</info>

  Custom batch size:
    <info>php bin/mygra migrate --batch-size=5000</info>
HELP
      );
  }

  protected function execute(InputInterface $input, OutputInterface $output): int
  {
    $io = new SymfonyStyle($input, $output);

    $io->title('🚀 Mygra Database Migration');

    // Load configuration
    $configPath = $input->getOption('config');

    try {
      $config = ConfigLoader::load($configPath);
    } catch (\Exception $e) {
      $io->error("Failed to load configuration: {$e->getMessage()}");
      return Command::FAILURE;
    }

    // Create adapters
    $io->section('Connecting to databases...');

    try {
      $sourceAdapter = AdapterFactory::create($config->getSourceConnection());
      $targetAdapter = AdapterFactory::create($config->getTargetConnection());

      $io->text([
        '<info>✓</info> Source: ' . $config->get('connections.source.driver') .
          ' @ ' . $config->get('connections.source.host'),
        '<info>✓</info> Target: ' . $config->get('connections.target.driver') .
          ' @ ' . $config->get('connections.target.host'),
      ]);
    } catch (\Exception $e) {
      $io->error("Database connection failed: {$e->getMessage()}");
      return Command::FAILURE;
    }

    // Setup logger
    $logFile = $input->getOption('log-file')
      ?? $config->get('settings.log_file')
      ?? 'logs/migration_' . date('Ymd_His') . '.log';

    $logger = new FileLogger($logFile);

    // Create migration executor
    $journalFile = $input->getOption('journal') ?? $config->get('settings.journal_file');
    $transactionManager = null;

    if ($journalFile) {
      $journal = new RollbackJournal($journalFile);
      $transactionManager = new TransactionManager($targetAdapter, $journal, $logger);
    }

    $executor = new MigrationExecutor(
      $sourceAdapter,
      $targetAdapter,
      TransformPipeline::withDefaults(),
      null,
      $transactionManager,
      $logger
    );

    $executor->setBatchSize((int) $input->getOption('batch-size'));
    $executor->setDryRun((bool) $input->getOption('dry-run'));
    $executor->setOptions([
      'auto_create' => $input->getOption('create')
    ]);

    if ($input->getOption('dry-run')) {
      $io->warning('DRY RUN MODE - No changes will be made');
    }

    // Determine which schemas to migrate
    $schemaName = $input->getOption('schema');
    $schemas = $schemaName
      ? [$config->getSchema($schemaName)]
      : array_values($config->getAllSchemas());

    if (empty($schemas) || $schemas[0] === null) {
      $io->error(
        $schemaName
          ? "Schema not found: {$schemaName}"
          : "No schemas defined in configuration"
      );
      return Command::FAILURE;
    }

    // Execute migrations
    $results = [];
    $totalSuccess = true;

    foreach ($schemas as $schema) {
      $io->section("Migrating: {$schema->name}");
      $io->text("Source: {$schema->sourceTable} → Target: {$schema->targetTable}");

      // Setup progress bar
      $progressBar = null;
      if (!$input->getOption('no-progress')) {
        $progressBar = new ProgressBar($output);
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s%');

        $executor->onProgress(function ($processed, $total, $status) use ($progressBar) {
          $progressBar->setMaxSteps($total);
          $progressBar->setProgress($processed);
        });
      }

      // Execute
      $result = $executor->execute($schema);
      $results[] = $result;

      if ($progressBar) {
        $progressBar->finish();
        $output->writeln('');
      }

      // Display result
      if ($result->isSuccess()) {
        $io->success($result->getSummary());
      } else {
        $io->error("Migration failed: " . $result->getError());
        $totalSuccess = false;
      }

      // Show errors if any
      if ($result->getErrorCount() > 0) {
        $io->warning("Encountered {$result->getErrorCount()} row errors. Check log for details.");
      }
    }

    // Summary
    $io->section('Summary');

    $summaryRows = [];
    foreach ($results as $result) {
      $summaryRows[] = [
        $result->getSchemaName(),
        $result->isSuccess() ? '<info>✓</info>' : '<error>✗</error>',
        $result->getProcessedRows(),
        $result->getInsertedRows(),
        $result->getErrorCount(),
        $result->getFormattedDuration(),
      ];
    }

    $io->table(
      ['Schema', 'Status', 'Processed', 'Inserted', 'Errors', 'Duration'],
      $summaryRows
    );

    $io->text("Log file: <info>{$logFile}</info>");

    return $totalSuccess ? Command::SUCCESS : Command::FAILURE;
  }
}
