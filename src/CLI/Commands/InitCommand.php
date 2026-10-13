<?php

declare(strict_types=1);

namespace Mygra\CLI\Commands;

use Mygra\CLI\Ui;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Yaml\Yaml;

#[AsCommand(name: 'init', description: 'Create a configuration file by answering a few questions')]
final class InitCommand extends MygraCommand
{
  private const DRIVERS = ['mysql', 'postgresql', 'sqlite', 'sqlserver'];

  protected function configure(): void
  {
    $this
      ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Where to write the configuration file', 'config/mygra.yaml')
      ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'File format: yaml, json or php', 'yaml')
      ->setHelp(<<<HELP
        Asks for both connections and a first table, and writes a configuration file
        to fill in with field mappings.

          <info>php mygra init</info>
          <info>php mygra init --format=json</info>
          <info>php mygra init --output=my-migration.yaml</info>
        HELP);
  }

  /**
   * --format here is the file format, not the output format: errors are always text.
   */
  protected function wantsJson(InputInterface $input): bool
  {
    return false;
  }

  protected function execute(InputInterface $input, OutputInterface $output): int
  {
    $format = $input->getOption('format');
    if (!in_array($format, ['yaml', 'yml', 'json', 'php'], true)) {
      return $this->fail($input, $output, "Unknown format: {$format}. Use yaml, json or php.");
    }

    $ask = fn(string $question, ?string $default = null, bool $hidden = false) => $this->ask($input, $output, $question, $default, $hidden);
    $choose = fn(string $question) => $this->askQuestion($input, $output, (new ChoiceQuestion("  {$question} <comment>[mysql]</comment>:", self::DRIVERS, 0))->setPrompt('  > '));
    $gap = function () use ($input): void {
      if ($input->isInteractive()) {
        $this->ui->line();
      }
    };

    $this->ui->info('Creating a configuration · Enter keeps the default in brackets');

    $sourceDriver = $choose('Source database driver');
    $sourceHost = $ask('Source host', 'localhost');
    $sourcePort = $ask('Source port', $this->getDefaultPort($sourceDriver));
    $sourceDatabase = $ask('Source database name');
    $sourceUser = $ask('Source username', 'root');
    $sourcePassword = $ask('Source password (hidden)', null, true) ?? '';
    $gap();

    $targetDriver = $choose('Target database driver');
    $targetHost = $ask('Target host', 'localhost');
    $targetPort = $ask('Target port', $this->getDefaultPort($targetDriver));
    $targetDatabase = $ask('Target database name');
    $targetUser = $ask('Target username', 'root');
    $targetPassword = $ask('Target password (hidden)', null, true) ?? '';
    $gap();

    $sourceTable = $ask('Source table name');
    $targetTable = $ask('Target table name');
    $schemaName = $ask('Schema name (identifier)', $sourceTable);

    $config = [
      'version' => '1.0',
      'connections' => [
        'source' => [
          'driver' => $sourceDriver,
          'host' => $sourceHost,
          'port' => (int) $sourcePort,
          'database' => $sourceDatabase,
          'user' => $sourceUser,
          'password' => $sourcePassword ?: '${SOURCE_DB_PASSWORD}',
        ],
        'target' => [
          'driver' => $targetDriver,
          'host' => $targetHost,
          'port' => (int) $targetPort,
          'database' => $targetDatabase,
          'user' => $targetUser,
          'password' => $targetPassword ?: '${TARGET_DB_PASSWORD}',
        ],
      ],
      'settings' => [
        'batch_size' => 1000,
        'log_level' => 'info',
        'log_file' => 'logs/migration.log',
        'transaction_mode' => 'per_batch',
        'on_error' => 'abort',
      ],
      'schemas' => [
        (string) $schemaName => [
          'source_table' => $sourceTable,
          'target_table' => $targetTable,
          'fields' => [
            'id' => 'id',
          ],
          'transforms' => [],
          'validations' => [],
        ],
      ],
    ];

    $outputPath = $input->getOption('output');
    $dir = dirname($outputPath);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
      return $this->fail($input, $output, "Cannot create directory {$dir}");
    }

    $content = match ($format) {
      'yaml', 'yml' => Yaml::dump($config, 6, 2),
      'json' => json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
      'php' => "<?php\n\nreturn " . var_export($config, true) . ";\n",
    };
    if (@file_put_contents($outputPath, $content) === false) {
      return $this->fail($input, $output, "Cannot write {$outputPath}");
    }

    $this->ui->done('Wrote ' . Ui::path($outputPath) . '; add your field mappings. Next: <info>php mygra validate</info>');

    return Command::SUCCESS;
  }

  private function ask(InputInterface $input, OutputInterface $output, string $question, ?string $default = null, bool $hidden = false): ?string
  {
    $shown = $default !== null && $default !== '' ? " <comment>[{$default}]</comment>" : '';
    $q = new Question("  {$question}{$shown}: ", $default);
    if ($hidden) {
      $q->setHidden(true)->setHiddenFallback(true);
    }
    return $this->askQuestion($input, $output, $q);
  }

  private function getDefaultPort(string $driver): string
  {
    return match ($driver) {
      'mysql' => '3306',
      'postgresql', 'pgsql' => '5432',
      'sqlserver', 'sqlsrv' => '1433',
      default => '',
    };
  }
}
