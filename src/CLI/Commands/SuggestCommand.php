<?php

declare(strict_types=1);

namespace Mygra\CLI\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Mygra\Adapters\AdapterFactory;
use Mygra\Config\ConfigLoader;
use Mygra\Schema\MappingGenerator;

#[AsCommand(
  name: 'suggest',
  description: 'Suggest field mappings between two tables'
)]
class SuggestCommand extends Command
{
  protected function configure(): void
  {
    $this
      ->addArgument('source_table', InputArgument::REQUIRED, 'Source table name')
      ->addArgument('target_table', InputArgument::REQUIRED, 'Target table name')
      ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Path to configuration file', 'config/mygra.yaml')
      ->addOption('threshold', 't', InputOption::VALUE_REQUIRED, 'Similarity threshold (0.0-1.0)', 0.7);
  }

  protected function execute(InputInterface $input, OutputInterface $output): int
  {
    $io = new SymfonyStyle($input, $output);
    $io->title('💡 Mygra Mapping Suggestion');

    $configFile = $input->getOption('config');
    if (!file_exists($configFile)) {
      $io->error("Configuration file not found: {$configFile}");
      return Command::FAILURE;
    }

    try {
      $config = ConfigLoader::load($configFile);
      $sourceTable = $input->getArgument('source_table');
      $targetTable = $input->getArgument('target_table');
      $threshold = (float) $input->getOption('threshold');

      $source = AdapterFactory::create($config->getSourceConnection());
      $target = AdapterFactory::create($config->getTargetConnection());

      $sourceSchema = $source->getTableSchema($sourceTable);
      $targetSchema = $target->getTableSchema($targetTable);

      $generator = new MappingGenerator();
      $suggestions = $generator->suggest($sourceSchema, $targetSchema, $threshold);

      if (empty($suggestions)) {
        $io->warning("No mappings suggested with threshold {$threshold}. Try lowering the threshold.");
      } else {
        $io->success("Generated " . count($suggestions) . " suggested mappings:");

        $rows = [];
        foreach ($suggestions as $src => $tgt) {
          $rows[] = [$src, '→', $tgt];
        }
        $io->table(['Source Field', '', 'Target Field'], $rows);

        $io->section("YAML snippet for your config:");
        $io->writeln($generator->toYaml($suggestions));
      }

      return Command::SUCCESS;
    } catch (\Exception $e) {
      $io->error("Suggestion failed: " . $e->getMessage());
      return Command::FAILURE;
    }
  }
}
