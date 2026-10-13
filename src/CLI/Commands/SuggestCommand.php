<?php

declare(strict_types=1);

namespace Mygra\CLI\Commands;

use Mygra\CLI\Ui;
use Mygra\Schema\MappingGenerator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'suggest', description: 'Suggest field mappings between a source and a target table')]
final class SuggestCommand extends MygraCommand
{
  protected function configure(): void
  {
    $this
      ->addArgument('source_table', InputArgument::REQUIRED, 'Source table name')
      ->addArgument('target_table', InputArgument::REQUIRED, 'Target table name')
      ->addConfigOption()
      ->addOption('threshold', 't', InputOption::VALUE_REQUIRED, 'Similarity threshold (0.0-1.0)', 0.7)
      ->setHelp(<<<HELP
        Matches the columns of a source table to the columns of a target table by name
        and prints the <info>fields:</info> block for the schema.

          <info>php mygra suggest users customers</info>
          <info>php mygra suggest users customers --threshold=0.5</info>   looser matching
        HELP);
  }

  protected function execute(InputInterface $input, OutputInterface $output): int
  {
    $sourceTable = $input->getArgument('source_table');
    $targetTable = $input->getArgument('target_table');
    $threshold = (float) $input->getOption('threshold');
    $generator = new MappingGenerator();

    try {
      $config = $this->loadConfig($input);
      $suggestions = $generator->suggest(
        $this->connect($config, 'source')->getTableSchema($sourceTable),
        $this->connect($config, 'target')->getTableSchema($targetTable),
        $threshold,
      );
    } catch (\Exception $e) {
      return $this->fail($input, $output, $e->getMessage());
    }

    $this->ui->info(sprintf(
      'Suggesting mappings · %s %s → %s %s',
      Ui::connection($config->getSourceConnection()),
      $sourceTable,
      Ui::connection($config->getTargetConnection()),
      $targetTable,
    ));

    if (!$suggestions) {
      $this->ui->warn("No columns match at threshold {$threshold}. Try a lower <info>--threshold</info>.");
      return Command::SUCCESS;
    }

    $this->ui->line("Suggested mappings (threshold {$threshold})");
    $rows = array_map(fn($src, $tgt) => [$src, '→', $tgt], array_keys($suggestions), $suggestions);
    $this->table($output, ['Source', '', 'Target'], $rows);

    $this->ui->line();
    $this->ui->line('For the schema in your config:');
    foreach (explode("\n", $generator->toYaml($suggestions)) as $yaml) {
      $this->ui->line("  {$yaml}");
    }

    $this->ui->done(Ui::count(count($suggestions), 'mapping') . ' suggested. Check them before pasting: matching is by name only.');
    return Command::SUCCESS;
  }

  private function table(OutputInterface $output, array $headers, array $rows): void
  {
    $style = (clone Table::getStyleDefinition('compact'))->setVerticalBorderChars('  ', ' ');
    (new Table($output))->setStyle($style)->setHeaders($headers)->setRows($rows)->render();
  }
}
