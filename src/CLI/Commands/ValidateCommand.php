<?php

declare(strict_types=1);

namespace Mygra\CLI\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Attribute\AsCommand;
use Mygra\Config\ConfigLoader;
use Mygra\Adapters\AdapterFactory;

/**
 * Validate Command
 * 
 * Validates configuration and performs pre-flight checks.
 */
#[AsCommand(
    name: 'validate',
    description: 'Validate configuration and check database connectivity'
)]
class ValidateCommand extends Command
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
                'Specific schema to validate (omit for all)'
            )
            ->setHelp(
                <<<HELP
The <info>validate</info> command checks your configuration and database connectivity.

<comment>Examples:</comment>

  Validate configuration:
    <info>php bin/mygra validate</info>

  Validate specific schema:
    <info>php bin/mygra validate --schema=users</info>
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('🔍 Configuration Validation');

        $configPath = $input->getOption('config');
        $issues = [];
        $warnings = [];

        // Check config file exists
        if (!file_exists($configPath)) {
            $io->error("Configuration file not found: {$configPath}");
            return Command::FAILURE;
        }

        $io->text("Config file: <info>{$configPath}</info>");

        // Load and validate configuration
        $io->section('Configuration');
        try {
            $config = ConfigLoader::load($configPath);
            $io->text('<info>✓</info> Configuration file is valid');
        } catch (\Exception $e) {
            $io->text('<error>✗</error> Configuration error: ' . $e->getMessage());
            return Command::FAILURE;
        }

        // Test database connections
        $io->section('Database Connections');

        // Source connection
        try {
            $sourceConfig = $config->getSourceConnection();
            $sourceAdapter = AdapterFactory::create($sourceConfig);
            $version = method_exists($sourceAdapter, 'getServerVersion')
                ? $sourceAdapter->getServerVersion()
                : 'connected';
            $io->text("<info>✓</info> Source ({$sourceConfig['driver']}): {$sourceConfig['host']}");
        } catch (\Exception $e) {
            $io->text("<error>✗</error> Source connection failed: {$e->getMessage()}");
            $issues[] = "Source database connection failed";
        }

        // Target connection
        try {
            $targetConfig = $config->getTargetConnection();
            $targetAdapter = AdapterFactory::create($targetConfig);
            $io->text("<info>✓</info> Target ({$targetConfig['driver']}): {$targetConfig['host']}");
        } catch (\Exception $e) {
            $io->text("<error>✗</error> Target connection failed: {$e->getMessage()}");
            $issues[] = "Target database connection failed";
        }

        // Validate schemas
        $io->section('Schema Validation');

        $schemaName = $input->getOption('schema');
        $schemas = $schemaName
            ? [$config->getSchema($schemaName)]
            : array_values($config->getAllSchemas());

        foreach ($schemas as $schema) {
            if (!$schema) {
                $io->text("<error>✗</error> Schema not found: {$schemaName}");
                continue;
            }

            $io->text("<comment>{$schema->name}</comment>");

            // Check source table exists
            if (isset($sourceAdapter)) {
                $sourceTables = $sourceAdapter->getTables();
                if (in_array($schema->sourceTable, $sourceTables)) {
                    $io->text("  <info>✓</info> Source table exists: {$schema->sourceTable}");

                    // Check source fields
                    $sourceSchema = $sourceAdapter->getTableSchema($schema->sourceTable);
                    $missingFields = [];
                    foreach ($schema->getSourceFields() as $field) {
                        if (!$sourceSchema->hasColumn($field)) {
                            $missingFields[] = $field;
                        }
                    }
                    if (!empty($missingFields)) {
                        $io->text("  <error>✗</error> Missing source fields: " . implode(', ', $missingFields));
                        $issues[] = "Schema '{$schema->name}': missing source fields";
                    } else {
                        $io->text("  <info>✓</info> All source fields exist");
                    }
                } else {
                    $io->text("  <error>✗</error> Source table not found: {$schema->sourceTable}");
                    $issues[] = "Schema '{$schema->name}': source table not found";
                }
            }

            // Check target table exists
            if (isset($targetAdapter)) {
                $targetTables = $targetAdapter->getTables();
                if (in_array($schema->targetTable, $targetTables)) {
                    $io->text("  <info>✓</info> Target table exists: {$schema->targetTable}");
                } else {
                    $io->text("  <error>✗</error> Target table not found: {$schema->targetTable}");
                    $issues[] = "Schema '{$schema->name}': target table not found";
                }
            }

            // Check field count
            $fieldCount = count($schema->fields);
            $io->text("  <info>✓</info> Field mappings: {$fieldCount}");
        }

        // Summary
        $io->section('Summary');

        if (empty($issues)) {
            $io->success('All validation checks passed!');
            return Command::SUCCESS;
        } else {
            $io->error('Validation failed with ' . count($issues) . ' issue(s):');
            $io->listing($issues);
            return Command::FAILURE;
        }
    }
}
