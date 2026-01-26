<?php

declare(strict_types=1);

namespace Mygra\CLI\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Mygra\Adapters\AdapterFactory;
use Mygra\Config\ConfigLoader;
use Mygra\Migration\MigrationExecutor;
use Mygra\Transaction\RollbackJournal;
use Mygra\Transaction\TransactionManager;

#[AsCommand(
  name: 'rollback',
  description: 'Rollback changes from a failed or interrupted migration'
)]
class RollbackCommand extends Command
{
  protected function configure(): void
  {
    $this
      ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Path to configuration file', 'config/mygra.yaml')
      ->addOption('journal', 'j', InputOption::VALUE_REQUIRED, 'Path to specific journal file', 'logs/rollback.journal');
  }

  protected function execute(InputInterface $input, OutputInterface $output): int
  {
    $io = new SymfonyStyle($input, $output);
    $io->title('Mygra Rollback & Recovery');

    $configFile = $input->getOption('config');
    if (!file_exists($configFile)) {
      $io->error("Configuration file not found: {$configFile}");
      return Command::FAILURE;
    }

    try {
      $config = ConfigLoader::load($configFile);
      $journalFile = $input->getOption('journal');

      if (!file_exists($journalFile)) {
        $io->success("No rollback journal found at {$journalFile}. Nothing to rollback.");
        return Command::SUCCESS;
      }

      $io->warning("Found active journal: {$journalFile}");

      if (!$io->confirm("Do you want to proceed with recovery/rollback?", true)) {
        $io->note("Operation cancelled.");
        return Command::SUCCESS;
      }

      $source = AdapterFactory::create($config->getSourceConnection());
      $target = AdapterFactory::create($config->getTargetConnection());

      $journal = new RollbackJournal($journalFile);
      $transactionManager = new TransactionManager($target, $journal);
      $executor = new MigrationExecutor($source, $target, null, null, $transactionManager);

      $executor->recovery();

      $io->success("Migration rolled back / recovered successfully.");
      return Command::SUCCESS;
    } catch (\Exception $e) {
      $io->error("Rollback failed: " . $e->getMessage());
      return Command::FAILURE;
    }
  }
}
