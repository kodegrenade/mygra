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
use Mygra\Schema\SchemaDiff;

#[AsCommand(
  name: 'compare',
  description: 'Compare source table schema with target table schema'
)]
class CompareCommand extends Command
{
  protected function configure(): void
  {
    $this
      ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Path to configuration file', 'config/mygra.yaml')
      ->addOption('schema', 's', InputOption::VALUE_REQUIRED, 'Specific schema to compare');
  }

  protected function execute(InputInterface $input, OutputInterface $output): int
  {
    $io = new SymfonyStyle($input, $output);
    $io->title('🔍 Mygra Schema Comparison');

    $configFile = $input->getOption('config');
    if (!file_exists($configFile)) {
      $io->error("Configuration file not found: {$configFile}");
      return Command::FAILURE;
    }

    try {
      $config = ConfigLoader::load($configFile);
      $schemaName = $input->getOption('schema');

      if (!$schemaName) {
        $schemas = $config->getAllSchemas();
        $io->text("Choose a schema to compare:");
        $choices = array_keys($schemas);
        $schemaName = $io->choice("Schema", $choices);
      }

      $schema = $config->getSchema($schemaName);
      if (!$schema) {
        $io->error("Schema not found: {$schemaName}");
        return Command::FAILURE;
      }

      $io->section("Comparing: {$schema->name}");
      $io->text("Source Table: <info>{$schema->sourceTable}</info>");
      $io->text("Target Table: <info>{$schema->targetTable}</info>");

      $source = AdapterFactory::create($config->getSourceConnection());
      $target = AdapterFactory::create($config->getTargetConnection());

      $sourceSchema = $source->getTableSchema($schema->sourceTable);
      $targetSchema = $target->getTableSchema($schema->targetTable);

      $diffUtility = new SchemaDiff();
      $diffs = $diffUtility->compare($sourceSchema, $targetSchema, $schema->fields);

      if (empty($diffs)) {
        $io->success("Schemas are perfectly synced!");
      } else {
        $rows = [];
        foreach ($diffs as $diff) {
          $color = match ($diff['type']) {
            'missing' => 'error',
            'type_mismatch' => 'comment',
            'extra' => 'info',
            default => 'comment'
          };
          $rows[] = [
            ucfirst($diff['category']),
            $diff['item'],
            sprintf('<%s>%s</%s>', $color, $diff['type'], $color),
            $diff['message']
          ];
        }

        $io->table(['Category', 'Item', 'Issue Type', 'Details'], $rows);
        $io->warning("Found " . count($diffs) . " difference(s).");
      }

      return Command::SUCCESS;
    } catch (\Exception $e) {
      $io->error("Comparison failed: " . $e->getMessage());
      return Command::FAILURE;
    }
  }
}
