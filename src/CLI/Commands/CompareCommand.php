<?php

declare(strict_types=1);

namespace Mygra\CLI\Commands;

use Mygra\CLI\Ui;
use Mygra\Schema\SchemaDiff;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;

#[AsCommand(name: 'compare', description: 'Compare a schema\'s source table with its target table')]
final class CompareCommand extends MygraCommand
{
  protected function configure(): void
  {
    $this
      ->addConfigOption()
      ->addOption('schema', 's', InputOption::VALUE_REQUIRED, 'Schema to compare (asked for when omitted)')
      ->setHelp(<<<HELP
        Compares the mapped columns, primary key and indexes of a schema's source table
        with its target table.

          <info>php mygra compare --schema=users</info>
        HELP);
  }

  protected function execute(InputInterface $input, OutputInterface $output): int
  {
    try {
      $config = $this->loadConfig($input);
      $schemaName = $input->getOption('schema');
      if (!$schemaName) {
        $names = array_keys($config->getAllSchemas());
        if (!$input->isInteractive() || !$names) {
          return $this->fail($input, $output, $names ? 'Choose a schema with --schema: ' . implode(', ', $names) : 'No schemas defined in the configuration');
        }
        $schemaName = $this->askQuestion($input, $output, new ChoiceQuestion('  Schema to compare:', $names));
      }
      $schema = $config->getSchema($schemaName);
      if (!$schema) {
        return $this->fail($input, $output, "Schema not found: {$schemaName}");
      }
      $source = $this->connect($config, 'source');
      $target = $this->connect($config, 'target');
      if (!in_array($schema->targetTable, $target->getTables(), true)) {
        return $this->fail($input, $output, "Target table {$schema->targetTable} does not exist yet; <info>php mygra migrate --create</info> creates it");
      }
      $diffs = (new SchemaDiff())->compare(
        $source->getTableSchema($schema->sourceTable),
        $target->getTableSchema($schema->targetTable),
        $schema->fields,
      );
    } catch (\Exception $e) {
      return $this->fail($input, $output, $e->getMessage());
    }

    $this->ui->info(sprintf(
      'Comparing %s · %s %s → %s %s',
      $schema->name,
      Ui::connection($config->getSourceConnection()),
      $schema->sourceTable,
      Ui::connection($config->getTargetConnection()),
      $schema->targetTable,
    ));

    if (!$diffs) {
      $this->ui->done("{$schema->targetTable} matches {$schema->sourceTable}.");
      return Command::SUCCESS;
    }

    $this->ui->line('Differences');
    $rows = array_map(fn($d) => [
      ucfirst(str_replace('_', ' ', $d['category'])),
      $d['item'],
      sprintf(match ($d['type']) {
        'missing' => '<fg=red>%s</>',
        'extra' => '<info>%s</info>',
        default => '<comment>%s</comment>',
      }, str_replace('_', ' ', $d['type'])),
      $d['message'],
    ], $diffs);
    $style = (clone Table::getStyleDefinition('compact'))->setVerticalBorderChars('  ', ' ');
    (new Table($output))->setStyle($style)->setHeaders(['Category', 'Item', 'Issue', 'Details'])->setRows($rows)->setColumnMaxWidth(3, 44)->render();

    $this->ui->warn(Ui::count(count($diffs), 'difference') . " between {$schema->sourceTable} and {$schema->targetTable}.");
    return Command::SUCCESS;
  }
}
