<?php

declare(strict_types=1);

namespace Mygra\CLI\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Closes a migration after cutover: verifies every schema once more, then removes Mygra's ID
 * bookkeeping (mygra_offsets) from the target. Until then, resume, verify and --upsert reruns
 * keep using the same IDs.
 */
#[AsCommand(name: 'finish', description: 'After cutover: verify everything once more, then remove Mygra\'s bookkeeping from the target')]
final class FinishCommand extends VerifyCommand
{
  protected function configure(): void
  {
    $this
      ->addConfigOption()
      ->addFormatOption()
      ->setHelp(<<<HELP
        Run once the application has switched to the target and no more syncs are needed.

        Verifies every schema like <info>verify</info>. Only if all of them match does it remove the
        <info>mygra_offsets</info> table that remapped IDs depend on; after that, --resume and --upsert
        reruns can no longer reuse the same IDs. Nothing is removed if any schema differs.
        HELP);
  }

  protected function execute(InputInterface $input, OutputInterface $output): int
  {
    $run = $this->verifyAll($input, $output, 'Finishing: verifying');
    if (is_int($run)) {
      return $run;
    }
    [$executor, $report, $allMatch] = $run;

    if (!$allMatch) {
      $message = $this->mismatchSummary($report) . ' Nothing was removed; fix the differences and run finish again.';
      return $this->fail($input, $output, $message);
    }

    $removed = $executor->dropOffsets();
    if ($this->wantsJson($input)) {
      $this->writeJson($output, ['status' => 'ok', 'removed_offsets' => $removed, 'schemas' => $report]);
    } else {
      $this->ui->done('Every schema matches. ' . ($removed ? 'Removed mygra_offsets; the migration is finished.' : 'Nothing to clean up; the migration is finished.'));
    }

    return Command::SUCCESS;
  }
}
