<?php

declare(strict_types=1);

namespace Mygra\CLI\Commands;

use Mygra\CLI\Ui;
use Mygra\Migration\MigrationExecutor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Checks that the target holds what a migration should have written:
 * row counts plus a checksum of the mapped columns.
 */
#[AsCommand(name: 'verify', description: 'Check that the target holds exactly what the migration should have written')]
class VerifyCommand extends MygraCommand
{
  protected function configure(): void
  {
    $this
      ->addConfigOption()
      ->addOption('schema', 's', InputOption::VALUE_REQUIRED, 'Verify only this schema')
      ->addFormatOption()
      ->setHelp(<<<HELP
        Reads the source through the same query, transforms, validations and ID mapping as
        <info>migrate</info>, and compares it with the target: row counts and a checksum of every
        mapped column. Rows that fail validation are reported as invalid, not expected in the target.

        Salted hashes (bcrypt, argon2) differ on every run and always show a checksum mismatch.
        Exits with 0 only when every schema matches.
        HELP);
  }

  protected function execute(InputInterface $input, OutputInterface $output): int
  {
    $run = $this->verifyAll($input, $output, 'Verifying');
    if (is_int($run)) {
      return $run;
    }
    [, $report, $allMatch] = $run;

    if ($this->wantsJson($input)) {
      $this->writeJson($output, ['status' => $allMatch ? 'ok' : 'mismatch', 'schemas' => $report]);
    } elseif ($allMatch) {
      $this->ui->done('The target matches the source.');
    } else {
      $this->ui->error($this->mismatchSummary($report));
    }

    return $allMatch ? Command::SUCCESS : Command::FAILURE;
  }

  /**
   * Verify every schema (or --schema), rendering one line each unless the output is JSON.
   *
   * @return int|array{0: MigrationExecutor, 1: array<string, array>, 2: bool} An exit code on failure,
   *   else [executor, report per schema, whether all match]
   */
  protected function verifyAll(InputInterface $input, OutputInterface $output, string $verb): int|array
  {
    $json = $this->wantsJson($input);

    try {
      $config = $this->loadConfig($input);
      $executor = (new MigrationExecutor($this->connect($config, 'source'), $this->connect($config, 'target')))
        ->setSchemas($config->getAllSchemas());
    } catch (\Exception $e) {
      return $this->fail($input, $output, $e->getMessage());
    }

    $schemaName = $input->hasOption('schema') ? $input->getOption('schema') : null;
    $schemas = $schemaName ? array_filter([$config->getSchema($schemaName)]) : array_values($config->getAllSchemas());
    if (!$schemas) {
      return $this->fail($input, $output, $schemaName ? "Schema not found: {$schemaName}" : 'No schemas defined in the configuration');
    }

    if (!$json) {
      $this->ui->info(sprintf(
        '%s %s · %s → %s',
        $verb,
        Ui::count(count($schemas), 'schema'),
        Ui::connection($config->getSourceConnection()),
        Ui::connection($config->getTargetConnection()),
      ));
    }

    $report = [];
    foreach ($schemas as $schema) {
      try {
        $r = $executor->verify($schema)->toArray();
      } catch (\Throwable $e) {
        $r = ['match' => false, 'error' => $e->getMessage()];
      }
      $report[$schema->name] = $r;

      if (!$json) {
        $this->renderSchema($schema->name, $r);
      }
    }

    return [$executor, $report, !in_array(false, array_column($report, 'match'), true)];
  }

  protected function mismatchSummary(array $report): string
  {
    $mismatched = count(array_filter($report, fn($r) => !$r['match']));
    return Ui::count($mismatched, 'schema') . ' do' . ($mismatched === 1 ? 'es' : '') . ' not match the source.';
  }

  private function renderSchema(string $name, array $r): void
  {
    if (isset($r['error'])) {
      $this->ui->task($name, 'could not verify', 'fail');
      $this->ui->detail("<fg=red>{$r['error']}</>", false);
      return;
    }

    $this->ui->task($name, sprintf('%s of %s', Ui::count($r['target_rows'], 'row'), number_format($r['expected_rows'])), $r['match'] ? 'ok' : 'fail');
    if ($r['invalid_rows'] > 0) {
      $this->ui->detail(Ui::count($r['invalid_rows'], 'source row') . ' invalid, not expected in the target');
    }
    if ($r['target_rows'] !== $r['expected_rows']) {
      $diff = $r['expected_rows'] - $r['target_rows'];
      $this->ui->detail('<fg=red>' . ($diff > 0 ? Ui::count($diff, 'row') . ' missing from' : Ui::count(-$diff, 'extra row') . ' in') . ' the target</>', false);
    }
    if (!$r['checksum_match']) {
      $this->ui->detail('<fg=red>Values differ: the checksum of the mapped columns does not match</>', false);
    }
  }
}
