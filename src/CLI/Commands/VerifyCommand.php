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

/**
 * Verify Command
 *
 * Checks that the target holds what a migration should have written:
 * row counts plus a checksum of the mapped columns.
 */
#[AsCommand(
  name: 'verify',
  description: 'Verify migrated data: row counts and checksums, source vs target'
)]
class VerifyCommand extends Command
{
  protected function configure(): void
  {
    $this
      ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Path to configuration file', 'config/mygra.yaml')
      ->addOption('schema', 's', InputOption::VALUE_REQUIRED, 'Specific schema to verify (omit for all)')
      ->setHelp(
        <<<HELP
The <info>verify</info> command compares source and target after a migration.

Source rows go through the schema's query, transforms and validation, exactly as in
<info>migrate</info>; rows that fail validation are reported as invalid and not expected
in the target. Counts and an order-independent checksum of the mapped columns are compared.

Transforms that are not deterministic (e.g. salted hashes) always produce a checksum mismatch.
HELP
      );
  }

  protected function execute(InputInterface $input, OutputInterface $output): int
  {
    $io = new SymfonyStyle($input, $output);
    $io->title('Mygra Verification');

    try {
      $config = ConfigLoader::load($input->getOption('config'));
      $executor = new MigrationExecutor(
        AdapterFactory::create($config->getSourceConnection()),
        AdapterFactory::create($config->getTargetConnection())
      );
    } catch (\Exception $e) {
      $io->error($e->getMessage());
      return Command::FAILURE;
    }

    $schemaName = $input->getOption('schema');
    $schemas = $schemaName ? [$config->getSchema($schemaName)] : array_values($config->getAllSchemas());
    if (empty($schemas) || $schemas[0] === null) {
      $io->error($schemaName ? "Schema not found: {$schemaName}" : "No schemas defined in configuration");
      return Command::FAILURE;
    }

    $rows = [];
    $allMatch = true;

    foreach ($schemas as $schema) {
      try {
        $r = $executor->verify($schema);
      } catch (\Exception $e) {
        $rows[] = [$schema->name, '-', '-', '-', '-', '-', "<error>✗ {$e->getMessage()}</error>"];
        $allMatch = false;
        continue;
      }

      $allMatch = $allMatch && $r['match'];
      $rows[] = [
        $schema->name,
        $r['source_rows'],
        $r['invalid_rows'],
        $r['expected_rows'],
        $r['target_rows'],
        $r['checksum_match'] ? 'match' : '<error>differs</error>',
        $r['match'] ? '<info>✓</info>' : '<error>✗</error>',
      ];
    }

    $io->table(['Schema', 'Source', 'Invalid', 'Expected', 'Target', 'Checksum', 'Status'], $rows);

    if (!$allMatch) {
      $io->error('Target does not match the source.');
      return Command::FAILURE;
    }

    $io->success('Target matches the source.');
    return Command::SUCCESS;
  }
}
