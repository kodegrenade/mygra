<?php

declare(strict_types=1);

namespace Mygra\CLI\Commands;

use Mygra\Adapters\AdapterFactory;
use Mygra\Adapters\AdapterInterface;
use Mygra\CLI\Ui;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'inspect', description: 'List the tables of a database, or show one table\'s columns, indexes and foreign keys')]
final class InspectCommand extends MygraCommand
{
  protected function configure(): void
  {
    $this
      ->addArgument('table', InputArgument::OPTIONAL, 'Table to inspect (omit for the table list)')
      ->addConfigOption()
      ->addOption('connection', null, InputOption::VALUE_REQUIRED, 'Connection from the config: source or target', 'source')
      ->addOption('driver', 'd', InputOption::VALUE_REQUIRED, 'Connect directly, without a config: mysql, pgsql, sqlite, sqlsrv')
      ->addOption('host', 'H', InputOption::VALUE_REQUIRED, 'Database host (with --driver)')
      ->addOption('database', 'D', InputOption::VALUE_REQUIRED, 'Database name, or file for sqlite (with --driver)')
      ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Database user (with --driver)')
      ->addOption('password', 'p', InputOption::VALUE_REQUIRED, 'Database password (with --driver)')
      ->setHelp(<<<HELP
          <info>php mygra inspect</info>                                tables in the source database
          <info>php mygra inspect users</info>                          one table in detail
          <info>php mygra inspect users --connection=target</info>      the target database
          <info>php mygra inspect -d mysql -H localhost -D app -u root</info>   without a config
        HELP);
  }

  protected function execute(InputInterface $input, OutputInterface $output): int
  {
    try {
      $connection = $this->connectionConfig($input);
      $adapter = AdapterFactory::create($connection);
    } catch (\Exception $e) {
      return $this->fail($input, $output, $e->getMessage());
    }

    $table = $input->getArgument('table');
    return $table
      ? $this->inspectTable($input, $output, $adapter, Ui::connection($connection), $table)
      : $this->listTables($output, $adapter, Ui::connection($connection));
  }

  private function listTables(OutputInterface $output, AdapterInterface $adapter, string $where): int
  {
    $tables = $adapter->getTables();
    if (!$tables) {
      $this->ui->warn("No tables in {$where}.");
      return Command::SUCCESS;
    }

    $this->ui->info(Ui::count(count($tables), 'table') . " in {$where}");

    $rows = [];
    foreach ($tables as $table) {
      try {
        $schema = $adapter->getTableSchema($table);
        $rows[] = [$table, count($schema->columns), number_format($adapter->getRowCount($table)), self::pk($schema->primaryKey)];
      } catch (\Exception) {
        $rows[] = [$table, '?', '?', '?'];
      }
    }
    $this->ui->line('Tables');
    $this->table($output, ['Table', 'Columns', 'Rows', 'Primary key'], $rows);
    $this->ui->line();

    return Command::SUCCESS;
  }

  private function inspectTable(InputInterface $input, OutputInterface $output, AdapterInterface $adapter, string $where, string $table): int
  {
    try {
      if (!in_array($table, $adapter->getTables(), true)) {
        return $this->fail($input, $output, "Table not found in {$where}: {$table}");
      }
      $schema = $adapter->getTableSchema($table);
    } catch (\Exception $e) {
      return $this->fail($input, $output, "Cannot inspect {$table}: {$e->getMessage()}");
    }

    try {
      $count = ' · ' . Ui::count($adapter->getRowCount($table), 'row');
    } catch (\Exception) {
      $count = '';
    }
    $this->ui->info("{$table} in {$where}{$count} · primary key " . self::pk($schema->primaryKey));

    $this->ui->line('Columns');
    $this->table($output, ['Column', 'Type', 'Nullable', 'Default', 'Flags'], array_map(fn($c) => [
      $c->name,
      $c->nativeType ?? $c->type,
      $c->nullable ? 'yes' : 'no',
      $c->default === null ? '-' : (is_string($c->default) ? $c->default : var_export($c->default, true)),
      implode(', ', array_keys(array_filter(['PK' => $c->isPrimaryKey, 'AI' => $c->isAutoIncrement, 'UQ' => $c->isUnique, 'NN' => !$c->nullable]))) ?: '-',
    ], $schema->columns));

    if ($schema->indexes) {
      $this->ui->line();
      $this->ui->line('Indexes');
      $this->table($output, ['Name', 'Columns', 'Unique', 'Primary'], array_map(fn($i) => [
        $i['name'],
        implode(', ', $i['columns']),
        $i['unique'] ? 'yes' : 'no',
        $i['primary'] ? 'yes' : 'no',
      ], $schema->indexes));
    }

    if ($schema->foreignKeys) {
      $this->ui->line();
      $this->ui->line('Foreign keys');
      $this->table($output, ['Name', 'Column', 'References'], array_map(fn($fk) => [
        $fk['name'] ?? '-',
        $fk['column'],
        "{$fk['referencedTable']}.{$fk['referencedColumn']}",
      ], $schema->foreignKeys));
    }

    $this->ui->line();
    return Command::SUCCESS;
  }

  /**
   * --driver and friends for a direct connection, otherwise a connection from the config.
   */
  private function connectionConfig(InputInterface $input): array
  {
    if ($input->getOption('driver')) {
      return [
        'driver' => $input->getOption('driver'),
        'host' => $input->getOption('host') ?? 'localhost',
        'database' => $input->getOption('database') ?? '',
        'user' => $input->getOption('user') ?? 'root',
        'password' => $input->getOption('password') ?? '',
      ];
    }

    $config = $this->loadConfig($input);
    return $input->getOption('connection') === 'target' ? $config->getTargetConnection() : $config->getSourceConnection();
  }

  private static function pk(string|array|null $pk): string
  {
    return is_array($pk) ? implode(', ', $pk) : ($pk ?: '-');
  }

  private function table(OutputInterface $output, array $headers, array $rows): void
  {
    $style = (clone Table::getStyleDefinition('compact'))->setVerticalBorderChars('  ', ' ');
    (new Table($output))->setStyle($style)->setHeaders($headers)->setRows($rows)->render();
  }
}
