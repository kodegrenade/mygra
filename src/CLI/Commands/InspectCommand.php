<?php

declare(strict_types=1);

namespace Mygra\CLI\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Attribute\AsCommand;
use Mygra\Config\ConfigLoader;
use Mygra\Adapters\AdapterFactory;

/**
 * Inspect Command
 * 
 * Inspects database schema (tables, columns, indexes).
 */
#[AsCommand(
    name: 'inspect',
    description: 'Inspect database schema'
)]
class InspectCommand extends Command
{

    protected function configure(): void
    {
        $this
            ->addArgument(
                'table',
                InputArgument::OPTIONAL,
                'Table name to inspect (omit for table list)'
            )
            ->addOption(
                'config',
                'c',
                InputOption::VALUE_REQUIRED,
                'Path to configuration file',
                'config/mygra.yaml'
            )
            ->addOption(
                'connection',
                null,
                InputOption::VALUE_REQUIRED,
                'Connection to use: source or target',
                'source'
            )
            ->addOption(
                'driver',
                'd',
                InputOption::VALUE_REQUIRED,
                'Database driver (mysql, pgsql, sqlite, sqlsrv)'
            )
            ->addOption(
                'host',
                'H',
                InputOption::VALUE_REQUIRED,
                'Database host'
            )
            ->addOption(
                'database',
                'D',
                InputOption::VALUE_REQUIRED,
                'Database name'
            )
            ->addOption(
                'user',
                'u',
                InputOption::VALUE_REQUIRED,
                'Database user'
            )
            ->addOption(
                'password',
                'p',
                InputOption::VALUE_REQUIRED,
                'Database password'
            )
            ->setHelp(
                <<<HELP
The <info>inspect</info> command displays database schema information.

<comment>Examples:</comment>

  List all tables:
    <info>php bin/mygra inspect</info>

  Inspect a specific table:
    <info>php bin/mygra inspect users</info>

  Use target connection:
    <info>php bin/mygra inspect users --connection=target</info>

  Direct connection (without config):
    <info>php bin/mygra inspect -d mysql -H localhost -D mydb -u root</info>
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Build connection config
        $connectionConfig = $this->getConnectionConfig($input);

        if (!$connectionConfig) {
            $io->error('Could not determine database connection. Use --config or provide connection options.');
            return Command::FAILURE;
        }

        try {
            $adapter = AdapterFactory::create($connectionConfig);
        } catch (\Exception $e) {
            $io->error("Connection failed: {$e->getMessage()}");
            return Command::FAILURE;
        }

        $tableName = $input->getArgument('table');

        if ($tableName) {
            return $this->inspectTable($io, $adapter, $tableName);
        } else {
            return $this->listTables($io, $adapter);
        }
    }

    /**
     * List all tables in the database.
     */
    private function listTables(SymfonyStyle $io, $adapter): int
    {
        $io->title('📋 Database Tables');

        $tables = $adapter->getTables();

        if (empty($tables)) {
            $io->warning('No tables found in database.');
            return Command::SUCCESS;
        }

        $tableData = [];
        foreach ($tables as $table) {
            try {
                $rowCount = $adapter->getRowCount($table);
                $schema = $adapter->getTableSchema($table);
                $columnCount = count($schema->columns);
                $pk = $schema->primaryKey ?: '-';

                $tableData[] = [$table, $columnCount, $rowCount, $pk];
            } catch (\Exception $e) {
                $tableData[] = [$table, '?', '?', '?'];
            }
        }

        $io->table(['Table', 'Columns', 'Rows', 'Primary Key'], $tableData);
        $io->text(sprintf('Total tables: <info>%d</info>', count($tables)));

        return Command::SUCCESS;
    }

    /**
     * Inspect a specific table.
     */
    private function inspectTable(SymfonyStyle $io, $adapter, string $tableName): int
    {
        $io->title("🔍 Table: {$tableName}");

        try {
            $schema = $adapter->getTableSchema($tableName);
        } catch (\Exception $e) {
            $io->error("Could not inspect table: {$e->getMessage()}");
            return Command::FAILURE;
        }

        // Columns
        $io->section('Columns');
        $columnData = [];
        foreach ($schema->columns as $column) {
            $flags = [];
            if ($column->isPrimaryKey) $flags[] = 'PK';
            if ($column->isAutoIncrement) $flags[] = 'AI';
            if ($column->isUnique) $flags[] = 'UQ';
            if (!$column->nullable) $flags[] = 'NN';

            $columnData[] = [
                $column->name,
                $column->nativeType ?? $column->type,
                $column->nullable ? 'YES' : 'NO',
                $column->default ?? '-',
                implode(', ', $flags) ?: '-',
            ];
        }
        $io->table(['Column', 'Type', 'Nullable', 'Default', 'Flags'], $columnData);

        // Primary Key
        if ($schema->primaryKey) {
            $pk = is_array($schema->primaryKey)
                ? implode(', ', $schema->primaryKey)
                : $schema->primaryKey;
            $io->text("Primary Key: <info>{$pk}</info>");
        }

        // Foreign Keys
        if (!empty($schema->foreignKeys)) {
            $io->section('Foreign Keys');
            $fkData = [];
            foreach ($schema->foreignKeys as $fk) {
                $fkData[] = [
                    $fk['name'] ?? '-',
                    $fk['column'],
                    $fk['referencedTable'],
                    $fk['referencedColumn'],
                ];
            }
            $io->table(['Name', 'Column', 'References Table', 'References Column'], $fkData);
        }

        // Indexes
        if (!empty($schema->indexes)) {
            $io->section('Indexes');
            $indexData = [];
            foreach ($schema->indexes as $index) {
                $indexData[] = [
                    $index['name'],
                    implode(', ', $index['columns']),
                    $index['unique'] ? 'YES' : 'NO',
                    $index['primary'] ? 'YES' : 'NO',
                ];
            }
            $io->table(['Name', 'Columns', 'Unique', 'Primary'], $indexData);
        }

        // Row count
        try {
            $rowCount = $adapter->getRowCount($tableName);
            $io->text("Row count: <info>{$rowCount}</info>");
        } catch (\Exception $e) {
            // Ignore
        }

        return Command::SUCCESS;
    }

    /**
     * Get connection configuration from input options.
     */
    private function getConnectionConfig(InputInterface $input): ?array
    {
        // Check for direct connection options first
        if ($input->getOption('driver')) {
            return [
                'driver' => $input->getOption('driver'),
                'host' => $input->getOption('host') ?? 'localhost',
                'database' => $input->getOption('database') ?? '',
                'user' => $input->getOption('user') ?? 'root',
                'password' => $input->getOption('password') ?? '',
            ];
        }

        // Try to load from config file
        $configPath = $input->getOption('config');
        if (file_exists($configPath)) {
            try {
                $config = ConfigLoader::load($configPath);
                $connection = $input->getOption('connection');

                return $connection === 'target'
                    ? $config->getTargetConnection()
                    : $config->getSourceConnection();
            } catch (\Exception $e) {
                return null;
            }
        }

        return null;
    }
}
